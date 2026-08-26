<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Finding the image that represents a page.
 *
 * A share card without an image is a grey box, so the useful behaviour is a
 * ladder that almost always lands on something: what the user chose, then the
 * featured image, then the first real image on the page, then a site-wide
 * default.
 *
 * The third rung is the one worth having. Generic SEO plugins have to find it by
 * regexing rendered HTML for the first `<img>` — which on a modern page is
 * usually a logo, an icon or a tracking pixel. Here the builder tree is
 * available, so the search is over the elements that actually carry a picture,
 * in document order, and an icon is never mistaken for a photograph.
 */
class FW_SEO_Image {

	/**
	 * Open Graph's own minimum. Below this every network refuses the image,
	 * so emitting one is indistinguishable from emitting none — except that
	 * it looks like it worked.
	 */
	const MIN_EDGE = 200;

	/** Enough of the page to find a real picture; not the whole tree. */
	const MAX_CANDIDATES = 8;

	/** @var array<int,string> */
	protected static $cache = [];

	/**
	 * Which atts on which shortcode hold a representative image.
	 *
	 * Deliberately not every image on the page. A section background is listed
	 * last-resort-ish rather than first because a decorative gradient plate is a
	 * poor share card, while a hero photograph is a good one — and the two are
	 * indistinguishable from here. Content images come first for that reason.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function image_map() {
		/** Filters which shortcode atts the SEO image finder reads, keyed by shortcode tag. */
		return apply_filters( 'fw_seo_image_atts', [
			'media_image'    => [ 'image' ],
			'image_box'      => [ 'image' ],
			'image_content'  => [ 'image' ],
			'before_after'   => [ 'image_before', 'image_after' ],
			'image_hotspots' => [ 'image' ],
			'flip_box'       => [ 'front_image', 'back_image' ],
			'gallery'        => [ 'images' ],
			'carousel'       => [ 'images' ],
			'masonry_section' => [ 'images' ],
			'team_member'    => [ 'image' ],
			'testimonials'   => [ 'image' ],
			'blockquote'     => [ 'image' ],
			'logo_grid'      => [ 'logo' ],
		] );
	}

	/**
	 * The image for a context, as a URL.
	 *
	 * @param FW_SEO_Context $ctx
	 *
	 * @return string
	 */
	public static function find( FW_SEO_Context $ctx ) {
		$post_id = $ctx->post_id();

		if ( $post_id && isset( self::$cache[ $post_id ] ) ) {
			return self::$cache[ $post_id ];
		}

		$url = '';

		if ( $post_id ) {
			if ( has_post_thumbnail( $post_id ) ) {
				$url = (string) get_the_post_thumbnail_url( $post_id, 'full' );
			}

			if ( '' === $url ) {
				$url = self::from_builder( $post_id );
			}

			if ( '' === $url ) {
				$url = self::from_content( $post_id );
			}

			self::$cache[ $post_id ] = $url;
		}

		/** Filters the image found for a page, before the site-wide default applies. */
		return (string) apply_filters( 'fw_seo_page_image', $url, $ctx );
	}

	/**
	 * Walk the builder tree for the first image, in document order.
	 *
	 * @param int $post_id
	 *
	 * @return string
	 */
	protected static function from_builder( $post_id ) {
		if ( ! function_exists( 'fw_get_db_post_option' ) ) {
			return '';
		}

		$builder = fw_get_db_post_option( $post_id, 'page-builder' );

		if ( ! is_array( $builder ) || empty( $builder['builder_active'] ) || empty( $builder['json'] ) ) {
			return '';
		}

		$tree = json_decode( (string) $builder['json'], true );

		if ( ! is_array( $tree ) ) {
			return '';
		}

		$found = [];
		self::walk( $tree, self::image_map(), $found );

		return self::pick( $found );
	}

	/**
	 * Depth-first, collecting candidates in document order.
	 *
	 * Collecting rather than stopping at the first hit, because the first image
	 * on a page is very often a logo — and a share card cropped from a 441 × 84
	 * logo is a smear. `pick()` applies the size test the crawlers apply.
	 *
	 * @param array $nodes
	 * @param array $map
	 * @param array $found Accumulator, by reference.
	 * @param int   $depth
	 */
	protected static function walk( array $nodes, array $map, array &$found, $depth = 0 ) {
		if ( $depth > 32 || count( $found ) >= self::MAX_CANDIDATES ) {
			return;
		}

		foreach ( $nodes as $node ) {
			if ( count( $found ) >= self::MAX_CANDIDATES ) {
				return;
			}

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

					$url = self::url_from_value( $atts[ $att ] );

					if ( '' !== $url && ! in_array( $url, $found, true ) ) {
						$found[] = $url;
					}
				}
			}

			if ( ! empty( $node['_items'] ) && is_array( $node['_items'] ) ) {
				self::walk( $node['_items'], $map, $found, $depth + 1 );
			}
		}
	}

	/**
	 * The first candidate big enough to be a share card.
	 *
	 * Open Graph's own floor is 200 × 200, and anything below it is rejected
	 * outright by the networks — so emitting one produces a card with no image
	 * and no explanation. Applying the same floor here means the ladder keeps
	 * descending instead of stopping on a logo.
	 *
	 * Only images we host can be measured. A remote URL is taken on trust rather
	 * than fetched: an HTTP request per page render to check an image size is
	 * not a trade worth making.
	 *
	 * @param array<int,string> $candidates
	 *
	 * @return string
	 */
	protected static function pick( array $candidates ) {
		$unmeasurable = '';

		foreach ( $candidates as $url ) {
			$size = self::dimensions( $url );

			if ( ! $size['width'] || ! $size['height'] ) {
				if ( '' === $unmeasurable ) {
					$unmeasurable = $url;
				}

				continue;
			}

			if ( $size['width'] >= self::MIN_EDGE && $size['height'] >= self::MIN_EDGE ) {
				return $url;
			}
		}

		// Everything measurable was too small. Prefer an unverified image over
		// none — but only one we could not check, never one we checked and know
		// is too small.
		return $unmeasurable;
	}

	/**
	 * An `upload` option value is `[ 'attachment_id' => .., 'url' => .. ]`; a
	 * multi-upload is a list of those. Both shapes reduce to the same thing.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	public static function url_from_value( $value ) {
		if ( is_string( $value ) ) {
			return self::valid( $value );
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		if ( isset( $value['url'] ) ) {
			return self::valid( (string) $value['url'] );
		}

		// A list of images — take the first that resolves.
		foreach ( $value as $item ) {
			$url = self::url_from_value( $item );

			if ( '' !== $url ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * First image in classic or block content.
	 *
	 * @param int $post_id
	 *
	 * @return string
	 */
	protected static function from_content( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || '' === trim( (string) $post->post_content ) ) {
			return '';
		}

		if ( ! preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $matches ) ) {
			return '';
		}

		$candidates = [];

		foreach ( $matches[1] as $src ) {
			$url = self::valid( $src );

			if ( '' !== $url && ! in_array( $url, $candidates, true ) ) {
				$candidates[] = $url;
			}

			if ( count( $candidates ) >= self::MAX_CANDIDATES ) {
				break;
			}
		}

		return self::pick( $candidates );
	}

	/**
	 * A share image must be an absolute URL — a relative path is silently
	 * dropped by every crawler that reads it, which looks like the tag working.
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	protected static function valid( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
			return '';
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}

		if ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}

		return 0 === strpos( $url, 'http' ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * Width and height for an image we host, so a crawler can lay the card out
	 * before it has downloaded the file. Nothing is guessed for a remote URL.
	 *
	 * @param string $url
	 *
	 * @return array{width:int,height:int}
	 */
	public static function dimensions( $url ) {
		$empty = [ 'width' => 0, 'height' => 0 ];

		if ( '' === $url ) {
			return $empty;
		}

		$attachment_id = attachment_url_to_postid( $url );

		if ( ! $attachment_id ) {
			return $empty;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $meta ) || empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			return $empty;
		}

		return [ 'width' => (int) $meta['width'], 'height' => (int) $meta['height'] ];
	}

	public static function flush() {
		self::$cache = [];
	}
}
