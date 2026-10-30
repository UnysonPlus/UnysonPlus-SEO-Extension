<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Page analysis — a focus keyphrase with specific, checkable feedback, and
 * readability stated as facts.
 *
 * "Scores are trusted by the people reading them, and a check that is wrong is
 * worse than a check that is missing." So every check here is deterministic and
 * says exactly what it looked at: the phrase is matched as a phrase (letter case
 * and punctuation ignored, nothing fuzzier), headings are the page's real
 * headings (content and page-builder heading elements), and readability is
 * reported as measurements (sentence and paragraph length) rather than a grade
 * from a formula that only fits English.
 *
 * The same engine feeds the site-wide audit (FW_SEO_Audit).
 *
 * @since 2.0.50
 */
class FW_SEO_Analysis {

	const META  = '_fw_seo_focus';
	const NONCE = 'fw_seo_focus';

	/** A sentence longer than this is "long". */
	const LONG_SENTENCE = 25;

	/** A paragraph longer than this is "long". */
	const LONG_PARAGRAPH = 150;

	public static function init() {
		if ( is_admin() ) {
			add_action( 'save_post', [ __CLASS__, '_save' ], 10, 2 );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Text — pure
	 * ------------------------------------------------------------------ */

	/** Lower-case, accents kept, punctuation to single spaces. */
	public static function norm( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );

		return trim( (string) preg_replace( '#[^\p{L}\p{N}]+#u', ' ', $text ) );
	}

	/** Does $text contain $phrase as whole words? */
	public static function has_phrase( $text, $phrase ) {
		$phrase = self::norm( $phrase );

		return '' !== $phrase && false !== strpos( ' ' . self::norm( $text ) . ' ', ' ' . $phrase . ' ' );
	}

	/** How many times $phrase occurs in $text, as whole words. */
	public static function count_phrase( $text, $phrase ) {
		$phrase = self::norm( $phrase );

		return '' === $phrase ? 0 : substr_count( ' ' . self::norm( $text ) . ' ', ' ' . $phrase . ' ' );
	}

	/** @return int Words in plain text. */
	public static function words( $text ) {
		$n = self::norm( $text );

		return '' === $n ? 0 : count( explode( ' ', $n ) );
	}

	/**
	 * Paragraphs of a page's HTML, as plain text (block-level breaks).
	 *
	 * @return string[]
	 */
	public static function paragraphs( $html ) {
		$html  = preg_replace( '#<(script|style)\b.*?</\1>#is', '', (string) $html );
		$html  = preg_replace( '#<(br\s*/?|/(p|div|li|h[1-6]|td|th|blockquote|figcaption|section))>#i', "\n", $html );
		$text  = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$parts = array_map( 'trim', preg_split( "#\n\s*#", $text ) );

		return array_values( array_filter( $parts, static function ( $p ) {
			return self::words( $p ) >= 3;
		} ) );
	}

	/** @return string[] Sentences in plain text. */
	public static function sentences( $text ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '#(?<=[.!?…])\s+(?=\P{Ll})#u', (string) $text ) ), static function ( $s ) {
			return self::words( $s ) >= 3;
		} ) );
	}

	/**
	 * Headings in HTML, in order.
	 *
	 * @return array<int, array{level: int, text: string}>
	 */
	public static function headings( $html ) {
		$out = [];
		if ( preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1>#is', (string) $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $h ) {
				$out[] = [ 'level' => (int) $h[1], 'text' => trim( wp_strip_all_tags( $h[2] ) ) ];
			}
		}

		return $out;
	}

	/**
	 * Heading problems: a level skipped going down (h2 → h4), more than one H1.
	 *
	 * The page's own title is the H1 in nearly every theme, so a heading in the
	 * content starts at H2; an H1 inside the content is a second H1.
	 *
	 * @param array $headings From headings().
	 *
	 * @return string[]
	 */
	public static function heading_problems( array $headings ) {
		$problems = [];
		$prev     = 1;
		$h1       = 0;

		foreach ( $headings as $h ) {
			if ( 1 === $h['level'] ) {
				$h1 ++;
			}
			if ( $h['level'] > $prev + 1 ) {
				$problems[] = sprintf(
					/* translators: 1: heading level jumped from, 2: to, 3: heading text */
					__( 'Heading level skipped: H%1$d to H%2$d at "%3$s".', 'fw' ),
					$prev,
					$h['level'],
					wp_html_excerpt( $h['text'], 50, '…' )
				);
			}
			$prev = $h['level'];
		}

		if ( $h1 > 0 ) {
			$problems[] = __( 'An H1 in the content: the page title is already the H1, so headings in the content start at H2.', 'fw' );
		}

		return $problems;
	}

	/* ------------------------------------------------------------------ *
	 * A page's material
	 * ------------------------------------------------------------------ */

	/**
	 * Everything the checks read about one post.
	 *
	 * @param int $post_id
	 *
	 * @return array{html: string, headings: array, alts: string[], title: string, description: string, slug: string}
	 */
	public static function material( $post_id ) {
		$post  = get_post( (int) $post_id );
		$html  = $post ? (string) $post->post_content : '';
		$heads = self::headings( $html );
		$alts  = [];

		$tree = $post ? get_post_meta( $post->ID, 'fw:opt:ext:pb:page-builder:json', true ) : '';
		if ( is_string( $tree ) && '' !== $tree ) {
			$walk = static function ( $node ) use ( &$walk, &$html, &$heads, &$alts ) {
				if ( ! is_array( $node ) ) {
					return;
				}
				$sc   = (string) ( $node['shortcode'] ?? '' );
				$atts = (array) ( $node['atts'] ?? [] );
				if ( in_array( $sc, [ 'special_heading', 'animated_heading' ], true ) && '' !== trim( wp_strip_all_tags( (string) ( $atts['title'] ?? '' ) ) ) ) {
					$level   = preg_match( '#^h([1-6])$#', (string) ( $atts['heading'] ?? 'h2' ), $m ) ? (int) $m[1] : 0;
					$text    = trim( wp_strip_all_tags( (string) $atts['title'] ) );
					$html   .= "\n<p>" . esc_html( $text ) . '</p>';
					if ( $level ) {
						$heads[] = [ 'level' => $level, 'text' => $text ];
					}
				}
				foreach ( $atts as $k => $v ) {
					if ( is_string( $v ) && is_string( $k ) ) {
						if ( 'alt' === $k && '' !== trim( $v ) ) {
							$alts[] = $v;
						} elseif ( ! preg_match( '#(link|url|href|src|id|class|color|colour|icon|style|css|animation|width|height|size|heading|title)$#i', $k ) && preg_match( '#\p{L}{3,}\s+\p{L}{3,}#u', wp_strip_all_tags( $v ) ) ) {
							$html .= "\n" . $v;
							$heads = array_merge( $heads, self::headings( $v ) );
						}
					}
				}
				foreach ( $node as $child ) {
					if ( is_array( $child ) ) {
						$walk( $child );
					}
				}
			};
			$walk( json_decode( $tree, true ) );
		}

		if ( preg_match_all( '#<img\b[^>]*\balt=["\']([^"\']+)#i', (string) ( $post ? $post->post_content : '' ), $m ) ) {
			$alts = array_merge( $alts, $m[1] );
		}
		$thumb = $post ? (int) get_post_thumbnail_id( $post ) : 0;
		if ( $thumb ) {
			$alts[] = (string) get_post_meta( $thumb, '_wp_attachment_image_alt', true );
		}

		$title = $description = '';
		if ( $post && class_exists( 'FW_SEO_Chain' ) ) {
			$ctx         = FW_SEO_Context::for_post( $post );
			$title       = FW_SEO_Chain::resolve( 'title', $ctx );
			$description = FW_SEO_Chain::resolve( 'description', $ctx );
		}

		return [
			'html'        => $html,
			'headings'    => $heads,
			'alts'        => array_values( array_filter( $alts ) ),
			'title'       => $title ?: ( $post ? $post->post_title : '' ),
			'description' => $description,
			'slug'        => $post ? str_replace( '-', ' ', urldecode( (string) $post->post_name ) ) : '',
		];
	}

	/* ------------------------------------------------------------------ *
	 * The checks
	 * ------------------------------------------------------------------ */

	/**
	 * Keyphrase checks on a page's material.
	 *
	 * @param string $phrase
	 * @param array  $m From material().
	 *
	 * @return array<int, array{id: string, ok: bool, label: string}>
	 */
	public static function keyphrase_checks( $phrase, array $m ) {
		$paras = self::paragraphs( $m['html'] );
		$first = $paras ? $paras[0] : '';
		$text  = implode( ' ', $paras );
		$count = self::count_phrase( $text, $phrase );
		$words = self::words( $text );
		$subs  = array_filter( $m['headings'], static function ( $h ) {
			return $h['level'] >= 2;
		} );

		$check = static function ( $id, $ok, $yes, $no ) {
			return [ 'id' => $id, 'ok' => (bool) $ok, 'label' => $ok ? $yes : $no ];
		};

		return [
			$check( 'title', self::has_phrase( $m['title'], $phrase ), __( 'In the SEO title.', 'fw' ), __( 'Not in the SEO title. The title is what people see in search results; put the phrase in it, near the start if it reads well.', 'fw' ) ),
			$check( 'description', self::has_phrase( $m['description'], $phrase ), __( 'In the meta description.', 'fw' ), __( 'Not in the meta description. Search engines show it in bold there when it matches the search.', 'fw' ) ),
			$check( 'slug', self::has_phrase( $m['slug'], $phrase ), __( 'In the address (URL).', 'fw' ), __( 'Not in the address. Change the slug only on a new page: on a published one, an automatic redirect keeps the old address working.', 'fw' ) ),
			$check( 'intro', self::has_phrase( $first, $phrase ), __( 'In the first paragraph.', 'fw' ), __( 'Not in the first paragraph. Say what the page is about early.', 'fw' ) ),
			$check( 'heading', (bool) array_filter( $subs, static function ( $h ) use ( $phrase ) {
				return self::has_phrase( $h['text'], $phrase );
			} ), __( 'In a subheading.', 'fw' ), $subs ? __( 'Not in any subheading (H2–H6).', 'fw' ) : __( 'The page has no subheadings. On a longer page, headings help readers and search engines see its structure.', 'fw' ) ),
			$check( 'alt', (bool) array_filter( $m['alts'], static function ( $a ) use ( $phrase ) {
				return self::has_phrase( $a, $phrase );
			} ), __( 'In an image\'s alt text.', 'fw' ), $m['alts'] ? __( 'Not in any image\'s alt text. Only where it truly describes the image.', 'fw' ) : __( 'No image on the page has alt text.', 'fw' ) ),
			$check( 'used', $count > 0, sprintf(
				/* translators: 1: times used, 2: words in the text */
				_n( 'Used %1$d time in %2$d words.', 'Used %1$d times in %2$d words.', $count, 'fw' ),
				$count,
				$words
			), __( 'Not used in the text at all.', 'fw' ) ),
		];
	}

	/**
	 * Readability as measurements.
	 *
	 * @param string $html
	 *
	 * @return array{sentences: int, avg: float, long: int, long_share: float, long_paragraphs: int}
	 */
	public static function readability( $html ) {
		$paras     = self::paragraphs( $html );
		$sentences = self::sentences( implode( "\n", $paras ) );
		$lengths   = array_map( [ __CLASS__, 'words' ], $sentences );
		$long      = count( array_filter( $lengths, static function ( $n ) {
			return $n > self::LONG_SENTENCE;
		} ) );

		return [
			'sentences'       => count( $sentences ),
			'avg'             => $lengths ? round( array_sum( $lengths ) / count( $lengths ), 1 ) : 0.0,
			'long'            => $long,
			'long_share'      => $lengths ? round( $long / count( $lengths ), 2 ) : 0.0,
			'long_paragraphs' => count( array_filter( $paras, static function ( $p ) {
				return self::words( $p ) > self::LONG_PARAGRAPH;
			} ) ),
		];
	}

	/**
	 * Other published pages with the same focus keyphrase: they compete for it.
	 *
	 * @return int[]
	 */
	public static function competitors( $post_id, $phrase ) {
		global $wpdb;

		$norm = self::norm( $phrase );
		if ( '' === $norm ) {
			return [];
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_status = 'publish' AND m.post_id <> %d",
			self::META,
			(int) $post_id
		) );

		return array_map( 'intval', wp_list_pluck( array_filter( (array) $rows, static function ( $r ) use ( $norm ) {
			return self::norm( $r->meta_value ) === $norm;
		} ), 'post_id' ) );
	}

	/* ------------------------------------------------------------------ *
	 * The editor
	 * ------------------------------------------------------------------ */

	/**
	 * The panel in the SEO box: the keyphrase field, then what the saved page
	 * shows. Checked on save, so it describes what visitors get.
	 *
	 * @param int $post_id
	 *
	 * @return string
	 */
	public static function editor_html( $post_id ) {
		$phrase = (string) get_post_meta( $post_id, self::META, true );
		$m      = self::material( $post_id );

		$html  = '<div class="fw-seo-analysis" style="margin:0 0 14px">';
		$html .= wp_nonce_field( self::NONCE, 'fw_seo_focus_nonce', false, false );
		$html .= '<p style="margin:0 0 6px"><label for="fw-seo-focus"><strong>' . esc_html__( 'Focus keyphrase', 'fw' ) . '</strong></label> <span class="description">' . esc_html__( 'The search this page should answer. Checked when you save.', 'fw' ) . '</span></p>';
		$html .= '<input type="text" id="fw-seo-focus" name="fw_seo_focus" class="regular-text" style="width:100%;max-width:520px" value="' . esc_attr( $phrase ) . '" placeholder="' . esc_attr__( 'e.g. crab linguine recipe', 'fw' ) . '">';

		if ( '' !== trim( $phrase ) ) {
			$html .= '<ul style="margin:10px 0 0">';
			foreach ( self::keyphrase_checks( $phrase, $m ) as $c ) {
				$html .= '<li style="display:flex;gap:6px;margin:0 0 4px"><span class="dashicons ' . ( $c['ok'] ? 'dashicons-yes-alt" style="color:#00a32a' : 'dashicons-marker" style="color:#dba617' ) . '"></span><span>' . esc_html( $c['label'] ) . '</span></li>';
			}
			foreach ( self::competitors( $post_id, $phrase ) as $other ) {
				$html .= '<li style="display:flex;gap:6px;margin:0 0 4px"><span class="dashicons dashicons-marker" style="color:#dba617"></span><span>' . esc_html__( 'Also the focus of', 'fw' ) . ' <a href="' . esc_url( (string) get_edit_post_link( $other ) ) . '">' . esc_html( get_the_title( $other ) ) . '</a>. ' . esc_html__( 'Two pages aiming at one search compete with each other; give each its own.', 'fw' ) . '</span></li>';
			}
			$html .= '</ul>';
		}

		$r = self::readability( $m['html'] );
		if ( $r['sentences'] >= 5 ) {
			$html .= '<p style="margin:10px 0 0" class="description"><strong>' . esc_html__( 'Readability:', 'fw' ) . '</strong> ' . esc_html( sprintf(
				/* translators: 1: average words per sentence, 2: long sentences, 3: percentage, 4: long-sentence threshold */
				__( 'sentences average %1$s words; %2$d (%3$d%%) are over %4$d words.', 'fw' ),
				number_format_i18n( $r['avg'], 1 ),
				$r['long'],
				(int) round( $r['long_share'] * 100 ),
				self::LONG_SENTENCE
			) ) . ( $r['long_paragraphs'] ? ' ' . esc_html( sprintf(
				/* translators: 1: number of paragraphs, 2: word threshold */
				_n( '%1$d paragraph is over %2$d words.', '%1$d paragraphs are over %2$d words.', $r['long_paragraphs'], 'fw' ),
				$r['long_paragraphs'],
				self::LONG_PARAGRAPH
			) ) : '' ) . '</p>';
		}

		$problems = self::heading_problems( $m['headings'] );
		foreach ( $problems as $p ) {
			$html .= '<p style="margin:6px 0 0" class="description"><span class="dashicons dashicons-marker" style="color:#dba617"></span> ' . esc_html( $p ) . '</p>';
		}

		return $html . '</div>';
	}

	/** @internal */
	public static function _save( $post_id, $post ) {
		if ( ! isset( $_POST['fw_seo_focus_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fw_seo_focus_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ( class_exists( 'FW_SEO_Additions' ) && ! FW_SEO_Additions::can_edit() ) ) {
			return;
		}

		$phrase = sanitize_text_field( wp_unslash( $_POST['fw_seo_focus'] ?? '' ) );

		if ( '' === $phrase ) {
			delete_post_meta( $post_id, self::META );
		} else {
			update_post_meta( $post_id, self::META, $phrase );
		}
	}
}
