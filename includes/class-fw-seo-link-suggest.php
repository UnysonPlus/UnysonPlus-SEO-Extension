<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Internal link suggestions — pages that could link to each other, both ways.
 *
 * A page's subject is its title, reduced to a phrase worth matching ("How to
 * choose a kitchen worktop" → "choose a kitchen worktop"). A page whose text
 * already says that phrase, and does not link to the page, is a place a link
 * belongs: the words are there, they only need a link. The suggestion shows the
 * sentence, so the person decides; nothing is linked automatically.
 *
 * Deterministic on purpose: every suggestion can be explained by pointing at
 * the words that matched. Pairs already linked are left out, using the link
 * index (FW_SEO_Links).
 *
 * @since 2.0.43
 */
class FW_SEO_Link_Suggest {

	const CACHE = 'fw_seo_link_suggest_';
	const TTL   = 12 * HOUR_IN_SECONDS;
	const LIMIT = 5;

	/** Words that never make a phrase on their own, and are trimmed from its ends. */
	const STOP = [ 'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'how', 'in', 'into', 'is', 'it', 'its', 'of', 'on', 'or', 'our', 'the', 'their', 'this', 'to', 'we', 'what', 'when', 'where', 'which', 'who', 'why', 'with', 'you', 'your', 'about', 'all', 'can', 'do', 'does', 'more', 'most', 'my', 'new', 'not', 'that', 'these', 'those', 'up', 'vs', 'will' ];

	/** Openers that make a title a sentence rather than a subject. */
	const LEADS = [ 'how to', 'what is', 'what are', 'why you should', 'why', 'a guide to', 'the guide to', 'guide to', 'an introduction to', 'introduction to', 'everything you need to know about', 'tips for', 'ways to' ];

	public static function init() {
		add_action( 'save_post', [ __CLASS__, '_flush' ], 40 );
	}

	/** @internal Any save can change any page's suggestions; the cache is short, so clear just this one. */
	public static function _flush( $post_id ) {
		delete_transient( self::CACHE . (int) $post_id );
	}

	/* ------------------------------------------------------------------ *
	 * Text — pure
	 * ------------------------------------------------------------------ */

	/**
	 * Lower-case, accents kept, punctuation to spaces, one space between words.
	 *
	 * @param string $text
	 *
	 * @return string
	 */
	public static function normalise( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = preg_replace( '#[^\p{L}\p{N}]+#u', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * The phrase that stands for a page's subject, or '' when its title has no
	 * subject worth matching ("Home", "About us", "Hello world!").
	 *
	 * @param string $title
	 *
	 * @return string
	 */
	public static function phrase( $title ) {
		$p = self::normalise( $title );

		foreach ( self::LEADS as $lead ) {
			if ( 0 === strpos( $p . ' ', $lead . ' ' ) ) {
				$p = trim( substr( $p, strlen( $lead ) ) );
				break;
			}
		}

		$words = '' === $p ? [] : explode( ' ', $p );
		while ( $words && in_array( $words[0], self::STOP, true ) ) {
			array_shift( $words );
		}
		while ( $words && in_array( end( $words ), self::STOP, true ) ) {
			array_pop( $words );
		}

		// One word is too vague to place a link on ("garden" is in every page of a
		// garden site); a phrase needs two words that are not filler, and enough
		// letters to be specific.
		$content = array_filter( $words, static function ( $w ) {
			return ! in_array( $w, self::STOP, true );
		} );

		if ( count( $content ) < 2 || strlen( implode( ' ', $words ) ) < 8 || count( $words ) > 8 ) {
			return '';
		}

		return implode( ' ', $words );
	}

	/**
	 * The sentence of $text containing $phrase, as found (original case), or ''.
	 *
	 * @param string $text   Plain text.
	 * @param string $phrase Normalised phrase.
	 *
	 * @return string
	 */
	public static function sentence( $text, $phrase ) {
		if ( '' === $phrase ) {
			return '';
		}

		// A paragraph, list item or heading ends a sentence even without a full stop.
		$text  = preg_replace( '#<(br\s*/?|/(p|div|li|h[1-6]|td|th|blockquote|figcaption))>#i', "\n", (string) $text );
		$plain = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$plain = trim( preg_replace( '#[ \t]+#u', ' ', $plain ) );

		foreach ( preg_split( '#(?<=[.!?])\s+|\s*\n\s*#u', $plain ) as $s ) {
			$s = trim( $s );
			// A line that is little more than the phrase is a heading, a card title
			// or a list of recent posts, not prose a link can sit in.
			if ( count( explode( ' ', self::normalise( $s ) ) ) < count( explode( ' ', $phrase ) ) + 3 ) {
				continue;
			}
			if ( preg_match( '#(?<![\p{L}\p{N}])' . str_replace( ' ', '[^\p{L}\p{N}]+', preg_quote( $phrase, '#' ) ) . '(?![\p{L}\p{N}])#iu', $s ) ) {
				return function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $s, 0, 220, '…', 'UTF-8' ) : substr( $s, 0, 220 );
			}
		}

		return '';
	}

	/* ------------------------------------------------------------------ *
	 * The site
	 * ------------------------------------------------------------------ */

	/**
	 * A page's readable text: its content and the text of its page-builder tree.
	 *
	 * @param int $post_id
	 *
	 * @return string
	 */
	public static function text( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}

		$parts   = [ (string) $post->post_content ];
		$builder = get_post_meta( $post->ID, FW_SEO_Links::BUILDER_META, true );

		if ( is_string( $builder ) && '' !== $builder ) {
			$walk = static function ( $node ) use ( &$walk, &$parts ) {
				if ( is_array( $node ) ) {
					foreach ( $node as $k => $v ) {
						// Text-bearing fields only; links, colours and ids are not prose.
						if ( is_string( $v ) && ( ! is_string( $k ) || ! preg_match( '#(link|url|href|src|id|class|color|colour|icon|style|css|animation|width|height|size)#i', $k ) ) && preg_match( '#\p{L}{3,}.*\s.*\p{L}{3,}#u', $v ) ) {
							$parts[] = $v;
						} elseif ( is_array( $v ) ) {
							$walk( $v );
						}
					}
				}
			};
			$walk( json_decode( $builder, true ) );
		}

		// Each element is its own block, so sentences never run across them;
		// sentence() splits on the line breaks.
		return implode( "\n", array_map( static function ( $p ) {
			return preg_replace( '#<(br\s*/?|/(p|div|li|h[1-6]|td|th|blockquote|figcaption))>#i', "\n", (string) $p );
		}, $parts ) );
	}

	/** @return array<int, array{id: int, title: string, phrase: string}> */
	protected static function pages() {
		$types = class_exists( 'FW_SEO_Locations' ) ? array_keys( FW_SEO_Locations::post_types() ) : [ 'post', 'page' ];
		$out   = [];

		foreach ( get_posts( [
			'post_type'        => $types,
			'post_status'      => 'publish',
			'numberposts'      => 2000,
			'suppress_filters' => true,
		] ) as $p ) {
			$out[] = [ 'id' => (int) $p->ID, 'title' => (string) $p->post_title, 'phrase' => self::phrase( $p->post_title ) ];
		}

		return $out;
	}

	/**
	 * Posts whose content or page-builder data contain the phrase's first and
	 * last words (a cheap filter; the exact phrase is checked on the text).
	 *
	 * @param string $phrase
	 *
	 * @return int[]
	 */
	protected static function candidates( $phrase ) {
		global $wpdb;

		$words = explode( ' ', $phrase );
		$first = '%' . $wpdb->esc_like( reset( $words ) ) . '%';
		$last  = '%' . $wpdb->esc_like( end( $words ) ) . '%';

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s AND post_content LIKE %s", // phpcs:ignore WordPress.DB
			$first,
			$last
		) );

		$meta = $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s AND meta_value LIKE %s", // phpcs:ignore WordPress.DB
			FW_SEO_Links::BUILDER_META,
			$first,
			$last
		) );

		return array_map( 'intval', array_unique( array_merge( $ids, $meta ) ) );
	}

	/**
	 * Is there already a link from $from to $to?
	 *
	 * @return bool
	 */
	protected static function linked( $from, $to ) {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM ' . FW_SEO_Links::table() . ' WHERE source_id = %d AND target_id = %d LIMIT 1', // phpcs:ignore WordPress.DB
			(int) $from,
			(int) $to
		) );
	}

	/**
	 * Suggestions for one page, both ways.
	 *
	 * @param int $post_id
	 *
	 * @return array{in: array, out: array, phrase: string}
	 */
	public static function for_post( $post_id ) {
		$post_id = (int) $post_id;
		$cached  = get_transient( self::CACHE . $post_id );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$pages  = self::pages();
		$self   = null;
		foreach ( $pages as $p ) {
			if ( $p['id'] === $post_id ) {
				$self = $p;
			}
		}

		$result = [ 'in' => [], 'out' => [], 'phrase' => $self ? $self['phrase'] : self::phrase( get_the_title( $post_id ) ) ];

		// In: pages whose text says this page's phrase. The database narrows the
		// field first, so only pages containing its first and last words are read.
		if ( '' !== $result['phrase'] ) {
			$maybe = array_flip( self::candidates( $result['phrase'] ) );
			foreach ( $pages as $p ) {
				// A page on the same subject competes with this one; it is not a place to link from.
				if ( $p['id'] === $post_id || $p['phrase'] === $result['phrase'] || ! isset( $maybe[ $p['id'] ] ) || count( $result['in'] ) >= self::LIMIT ) {
					continue;
				}
				$sentence = self::sentence( self::text( $p['id'] ), $result['phrase'] );
				if ( '' !== $sentence && ! self::linked( $p['id'], $post_id ) ) {
					$result['in'][] = [ 'id' => $p['id'], 'title' => $p['title'], 'sentence' => $sentence ];
				}
			}
		}

		// Out: pages whose phrase this page's text says.
		// Each subject once: several pages sharing a title are one suggestion
		// (the first), and a page on this page's own subject is a competitor.
		$text = self::text( $post_id );
		$seen = [ $result['phrase'] => true ];
		foreach ( $pages as $p ) {
			if ( $p['id'] === $post_id || '' === $p['phrase'] || isset( $seen[ $p['phrase'] ] ) || count( $result['out'] ) >= self::LIMIT ) {
				continue;
			}
			$seen[ $p['phrase'] ] = true;
			$sentence = self::sentence( $text, $p['phrase'] );
			if ( '' !== $sentence && ! self::linked( $post_id, $p['id'] ) ) {
				$result['out'][] = [ 'id' => $p['id'], 'title' => $p['title'], 'sentence' => $sentence, 'phrase' => $p['phrase'] ];
			}
		}

		set_transient( self::CACHE . $post_id, $result, self::TTL );

		return $result;
	}

	/* ------------------------------------------------------------------ *
	 * The editor
	 * ------------------------------------------------------------------ */

	/**
	 * Collapsed in the SEO box: useful when working on links, out of the way
	 * the rest of the time.
	 *
	 * @param int $post_id
	 *
	 * @return string
	 */
	public static function editor_html( $post_id ) {
		if ( ! class_exists( 'FW_SEO_Links' ) || 'ready' !== FW_SEO_Links::state()['status'] || 'publish' !== get_post_status( $post_id ) ) {
			return '';
		}

		$s = self::for_post( $post_id );

		if ( ! $s['in'] && ! $s['out'] ) {
			return '';
		}

		$item = static function ( $row, $phrase ) {
			$sentence = esc_html( $row['sentence'] );
			// Show where the link would go: the matched words, emphasised.
			$sentence = preg_replace( '#(?<![\p{L}\p{N}])(' . str_replace( ' ', '[^\p{L}\p{N}]+', preg_quote( $phrase, '#' ) ) . ')(?![\p{L}\p{N}])#iu', '<mark>$1</mark>', $sentence, 1 );

			return '<li style="margin:0 0 8px"><a href="' . esc_url( (string) get_edit_post_link( $row['id'] ) ) . '"><strong>' . esc_html( $row['title'] ) . '</strong></a><br><span class="description">' . $sentence . '</span></li>';
		};

		$html = '<details class="fw-seo-link-suggest" style="margin:0 0 12px"><summary style="cursor:pointer">' . esc_html( sprintf(
			/* translators: %d: number of suggestions */
			_n( '%d internal link suggestion', '%d internal link suggestions', count( $s['in'] ) + count( $s['out'] ), 'fw' ),
			count( $s['in'] ) + count( $s['out'] )
		) ) . '</summary><div style="padding:8px 0 0">';

		if ( $s['in'] ) {
			$html .= '<p><strong>' . esc_html__( 'Pages that could link here', 'fw' ) . '</strong> — ' . esc_html__( 'they already mention this page\'s subject; link the highlighted words to this page.', 'fw' ) . '</p><ul style="margin:0 0 12px">';
			foreach ( $s['in'] as $row ) {
				$html .= $item( $row, $s['phrase'] );
			}
			$html .= '</ul>';
		}

		if ( $s['out'] ) {
			$html .= '<p><strong>' . esc_html__( 'Pages this one could link to', 'fw' ) . '</strong> — ' . esc_html__( 'this page mentions them; link the highlighted words.', 'fw' ) . '</p><ul style="margin:0">';
			foreach ( $s['out'] as $row ) {
				$html .= $item( $row, $row['phrase'] );
			}
			$html .= '</ul>';
		}

		return $html . '</div></details>';
	}

	/**
	 * How many pages could link to a page, for the orphan list.
	 *
	 * @param int $post_id
	 *
	 * @return int
	 */
	public static function inbound_count( $post_id ) {
		return count( self::for_post( $post_id )['in'] );
	}
}
