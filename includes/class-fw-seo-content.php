<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Turning a post into the plain prose an auto-generated description is made of.
 *
 * The interesting case is a page-builder page. Its `post_content` holds the
 * RENDERED markup the builder synced on save, so flattening it yields a soup of
 * button labels, counter digits and navigation text — which is exactly why the
 * generic SEO plugins produce poor descriptions on builder-built pages.
 *
 * We have the builder tree itself, so we walk it in document order and take text
 * only from the atts that actually carry prose. Rendered `post_content` stays as
 * the fallback for classic and block content.
 */
class FW_SEO_Content {

	/** @var array<int,string> Extracted prose, keyed by post id. */
	protected static $cache = [];

	/**
	 * Which atts on which shortcode carry human prose, in the order they should
	 * be read. Anything not listed here is furniture as far as a description is
	 * concerned.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function prose_map() {
		/** Filters which shortcode atts the SEO description extractor treats as prose, keyed by shortcode tag. */
		return apply_filters( 'fw_seo_prose_atts', [
			'text_block'      => [ 'text' ],
			'special_heading' => [ 'overline', 'title', 'subtitle' ],
			'call_to_action'  => [ 'title', 'message' ],
			'icon_box'        => [ 'title', 'content' ],
			'image_box'       => [ 'title', 'content' ],
			'image_content'   => [ 'title', 'content' ],
			'blockquote'      => [ 'quote' ],
			'animated_heading' => [ 'before_text', 'after_text' ],
			'highlight_text'  => [ 'text' ],
			'notification'    => [ 'message' ],
			'feature_list'    => [ 'title' ],
			'pricing_table'   => [ 'title', 'description' ],
			'team_member'     => [ 'name', 'position', 'description' ],
			'testimonials'    => [ 'quote' ],
			'post_excerpt'    => [],
			'tabs'            => [ 'title' ],
			'accordion'       => [ 'title' ],
			'steps'           => [ 'title', 'content' ],
			'counter'         => [ 'label' ],
			'text_expander'   => [ 'content' ],
			'toc'             => [],
		] );
	}

	/**
	 * The post's excerpt.
	 *
	 * @param FW_SEO_Context $ctx
	 * @param bool           $generate When false, only a hand-written excerpt counts.
	 *
	 * @return string
	 */
	public static function excerpt( FW_SEO_Context $ctx, $generate = true ) {
		$post = $ctx->post();

		if ( ! $post ) {
			return '';
		}

		$manual = trim( (string) $post->post_excerpt );

		if ( '' !== $manual ) {
			return self::flatten( $manual );
		}

		return $generate ? self::text( $ctx ) : '';
	}

	/**
	 * All the prose of the current post, flattened.
	 *
	 * @param FW_SEO_Context $ctx
	 *
	 * @return string
	 */
	public static function text( FW_SEO_Context $ctx ) {
		$post = $ctx->post();

		if ( ! $post ) {
			return '';
		}

		$post_id = (int) $post->ID;

		if ( isset( self::$cache[ $post_id ] ) ) {
			return self::$cache[ $post_id ];
		}

		$text = self::from_builder( $post );

		if ( '' === $text ) {
			$text = self::from_content( $post );
		}

		/** Filters the plain prose extracted from a post for SEO auto-generation. */
		$text = (string) apply_filters( 'fw_seo_post_text', $text, $post, $ctx );

		self::$cache[ $post_id ] = $text;

		return $text;
	}

	/**
	 * Walk the page-builder tree and collect prose in document order.
	 *
	 * @param WP_Post $post
	 *
	 * @return string Empty when the post is not a builder page.
	 */
	protected static function from_builder( WP_Post $post ) {
		if ( ! function_exists( 'fw_get_db_post_option' ) ) {
			return '';
		}

		$builder = fw_get_db_post_option( $post->ID, 'page-builder' );

		if ( ! is_array( $builder ) || empty( $builder['builder_active'] ) || empty( $builder['json'] ) ) {
			return '';
		}

		$tree = json_decode( (string) $builder['json'], true );

		if ( ! is_array( $tree ) ) {
			return '';
		}

		$collected = [];
		self::walk( $tree, self::prose_map(), $collected );

		if ( ! $collected ) {
			return '';
		}

		return self::flatten( implode( ' ', $collected ) );
	}

	/**
	 * Depth-first walk of the builder tree, appending prose as it is found.
	 *
	 * @param array $nodes
	 * @param array $map
	 * @param array $collected Accumulator, by reference.
	 * @param int   $depth     Guards against a tree that somehow references itself.
	 */
	protected static function walk( array $nodes, array $map, array &$collected, $depth = 0 ) {
		if ( $depth > 32 ) {
			return;
		}

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$shortcode = isset( $node['shortcode'] ) ? (string) $node['shortcode'] : '';
			$atts      = isset( $node['atts'] ) && is_array( $node['atts'] ) ? $node['atts'] : [];

			if ( '' !== $shortcode && isset( $map[ $shortcode ] ) ) {
				foreach ( $map[ $shortcode ] as $att ) {
					if ( ! isset( $atts[ $att ] ) ) {
						continue;
					}

					$text = self::flatten( self::stringify( $atts[ $att ] ) );

					if ( '' !== $text ) {
						$collected[] = $text;
					}
				}

				// A repeater's rows live in the atts too — pick up their prose
				// using the same att names as the parent element.
				foreach ( $atts as $value ) {
					if ( ! is_array( $value ) || ! isset( $value[0] ) || ! is_array( $value[0] ) ) {
						continue;
					}

					foreach ( $value as $row ) {
						if ( ! is_array( $row ) ) {
							continue;
						}

						foreach ( $map[ $shortcode ] as $att ) {
							if ( ! isset( $row[ $att ] ) ) {
								continue;
							}

							$text = self::flatten( self::stringify( $row[ $att ] ) );

							if ( '' !== $text ) {
								$collected[] = $text;
							}
						}
					}
				}
			}

			if ( ! empty( $node['_items'] ) && is_array( $node['_items'] ) ) {
				self::walk( $node['_items'], $map, $collected, $depth + 1 );
			}
		}
	}

	/**
	 * Prose from classic or block content.
	 *
	 * @param WP_Post $post
	 *
	 * @return string
	 */
	protected static function from_content( WP_Post $post ) {
		$content = (string) $post->post_content;

		if ( '' === trim( $content ) ) {
			return '';
		}

		// The builder stamps an md5 marker comment onto the synced content.
		$content = preg_replace( '/<!--(.*?)-->/s', ' ', $content );

		// Drop presentational blocks before flattening, when WordPress can.
		if ( function_exists( 'excerpt_remove_blocks' ) ) {
			$content = excerpt_remove_blocks( $content );
		}

		// strip_shortcodes rather than do_shortcode: rendering arbitrary
		// shortcodes inside wp_head is both slow and full of side effects.
		$content = strip_shortcodes( $content );

		return self::flatten( $content );
	}

	/**
	 * An att may be a plain string or a nested value object; take the string.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	protected static function stringify( $value ) {
		if ( is_string( $value ) || is_numeric( $value ) ) {
			return (string) $value;
		}

		return '';
	}

	/**
	 * Markup, scripts and whitespace out; readable single-line prose in.
	 *
	 * @param string $text
	 *
	 * @return string
	 */
	public static function flatten( $text ) {
		$text = (string) $text;

		if ( '' === trim( $text ) ) {
			return '';
		}

		// Block boundaries become spaces BEFORE the tags are stripped. Without
		// this, "</p><p>" closes up and welds the last word of one paragraph to
		// the first word of the next — "lazily.It also" — which is visible in
		// every generated description on a multi-paragraph post. Inline tags are
		// left to wp_strip_all_tags so "cat<b>s</b>" does not become "cat s".
		$text = preg_replace( '#<(?:br|hr)\s*/?>#i', ' ', $text );
		$text = preg_replace(
			'#</(?:p|div|li|ul|ol|h[1-6]|section|article|aside|header|footer|blockquote|figcaption|td|tr|table|dd|dt|dl|pre)\s*>#i',
			' ',
			$text
		);

		// wp_strip_all_tags removes script/style bodies as well as the tags.
		$text = wp_strip_all_tags( $text, true );
		$text = wp_specialchars_decode( $text, ENT_QUOTES );
		$text = str_replace( "\xC2\xA0", ' ', $text ); // Non-breaking spaces read as words otherwise.
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( (string) $text );
	}
}
