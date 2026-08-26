<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The built-in sitemap providers: one per public post type, one per public
 * taxonomy, and the homepage.
 *
 * Each is registered through the same registry a third party would use, so
 * there is no privileged path — if the built-ins can express something, so can
 * an extension.
 */

// -----------------------------------------------------------------------------
// Homepage
//
// Registered separately because the front page is not reliably a post: on a
// posts-index front page there is nothing for the post-type provider to list.
// -----------------------------------------------------------------------------

FW_SEO_Sitemap::register_provider( 'home', [
	'label' => __( 'Homepage', 'fw' ),
	'count' => static function () {
		return fw_seo_sitemap_source_enabled( 'front_page', 'sitemap_home' ) ? 1 : 0;
	},
	'urls'  => static function () {
		$lastmod = '';

		$latest = get_posts( [
			'posts_per_page' => 1,
			'post_status'    => 'publish',
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );

		if ( $latest ) {
			$lastmod = fw_seo_sitemap_date( $latest[0]->post_modified_gmt );
		}

		return [
			[
				'loc'     => home_url( '/' ),
				'lastmod' => $lastmod,
			],
		];
	},
] );

// -----------------------------------------------------------------------------
// Post types
// -----------------------------------------------------------------------------

foreach ( FW_SEO_Locations::post_types() as $fw_seo_pt => $fw_seo_pt_object ) {
	FW_SEO_Sitemap::register_provider( 'pt-' . $fw_seo_pt, [
		'label' => $fw_seo_pt_object->labels->name,
		'count' => static function () use ( $fw_seo_pt ) {
			// A post type the site has told search engines to ignore has no
			// business being advertised in a sitemap. Reporting zero is how a
			// provider excludes itself: the index skips it and its URL 404s.
			if ( ! fw_seo_sitemap_source_enabled( 'post:' . $fw_seo_pt, 'sitemap_pt__' . FW_SEO_Locations::slug( $fw_seo_pt ) ) ) {
				return 0;
			}

			return fw_seo_sitemap_post_count( $fw_seo_pt );
		},
		'urls'  => static function ( $page, $per_page ) use ( $fw_seo_pt ) {
			return fw_seo_sitemap_post_urls( $fw_seo_pt, $page, $per_page );
		},
	] );
}

// -----------------------------------------------------------------------------
// Taxonomies
// -----------------------------------------------------------------------------

foreach ( FW_SEO_Locations::taxonomies() as $fw_seo_tax => $fw_seo_tax_object ) {
	FW_SEO_Sitemap::register_provider( 'tax-' . $fw_seo_tax, [
		'label' => $fw_seo_tax_object->labels->name,
		'count' => static function () use ( $fw_seo_tax ) {
			if ( ! fw_seo_sitemap_source_enabled( 'tax:' . $fw_seo_tax, 'sitemap_tax__' . FW_SEO_Locations::slug( $fw_seo_tax ) ) ) {
				return 0;
			}

			return fw_seo_sitemap_term_count( $fw_seo_tax );
		},
		'urls'  => static function ( $page, $per_page ) use ( $fw_seo_tax ) {
			return fw_seo_sitemap_term_urls( $fw_seo_tax, $page, $per_page );
		},
	] );
}

unset( $fw_seo_pt, $fw_seo_pt_object, $fw_seo_tax, $fw_seo_tax_object );

// -----------------------------------------------------------------------------
// The functions the providers delegate to
// -----------------------------------------------------------------------------

if ( ! function_exists( 'fw_seo_sitemap_source_enabled' ) ) :
	/**
	 * Should this source contribute URLs?
	 *
	 * Called at request time, never at file scope. Reading the extension
	 * settings while this file is being included would force Unyson's
	 * option-type initialisation before the page-builder extension has
	 * registered its `page-builder` type, which fatals every builder page.
	 *
	 * @param string $location_key A FW_SEO_Locations key, e.g. 'post:page'.
	 * @param string $setting_id   The Sitemap-tab switch for this source.
	 *
	 * @return bool
	 */
	function fw_seo_sitemap_source_enabled( $location_key, $setting_id ) {
		if ( FW_SEO_Settings::flag( FW_SEO_Settings::robots_id( 'noindex', $location_key ), false ) ) {
			return false;
		}

		return FW_SEO_Settings::flag( $setting_id, true );
	}
endif;

if ( ! function_exists( 'fw_seo_sitemap_date' ) ) :
	/**
	 * A GMT datetime as W3C, which is the only format the sitemap schema takes.
	 *
	 * @param string $gmt_datetime
	 *
	 * @return string
	 */
	function fw_seo_sitemap_date( $gmt_datetime ) {
		$timestamp = strtotime( (string) $gmt_datetime . ' UTC' );

		return $timestamp ? gmdate( 'c', $timestamp ) : '';
	}
endif;

if ( ! function_exists( 'fw_seo_sitemap_post_query_args' ) ) :
	/**
	 * The shared query for a post-type sitemap page.
	 *
	 * The noindex exclusion is a plain meta query, which is only possible
	 * because the overrides live in discrete meta keys rather than inside a
	 * serialised option blob.
	 *
	 * @param string $post_type
	 *
	 * @return array
	 */
	function fw_seo_sitemap_post_query_args( $post_type ) {
		$args = [
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'has_password'           => false,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => [
				[
					'key'     => FW_SEO_Store::meta_key( 'noindex' ),
					'compare' => 'NOT EXISTS',
				],
			],
		];

		/** Filters the WP_Query arguments used to build a post-type sitemap. */
		return (array) apply_filters( 'fw_seo_sitemap_post_query_args', $args, $post_type );
	}
endif;

if ( ! function_exists( 'fw_seo_sitemap_post_count' ) ) :
	/**
	 * @param string $post_type
	 *
	 * @return int
	 */
	function fw_seo_sitemap_post_count( $post_type ) {
		$args = fw_seo_sitemap_post_query_args( $post_type );

		$args['posts_per_page'] = 1;
		$args['fields']         = 'ids';
		$args['no_found_rows']  = false; // We specifically need the total here.

		$query = new WP_Query( $args );

		return (int) $query->found_posts;
	}
endif;

if ( ! function_exists( 'fw_seo_sitemap_post_urls' ) ) :
	/**
	 * @param string $post_type
	 * @param int    $page
	 * @param int    $per_page
	 *
	 * @return array<int,array>
	 */
	function fw_seo_sitemap_post_urls( $post_type, $page, $per_page ) {
		$args = fw_seo_sitemap_post_query_args( $post_type );

		$args['posts_per_page'] = (int) $per_page;
		$args['paged']          = (int) $page;
		$args['orderby']        = 'modified';
		$args['order']          = 'DESC';

		$query = new WP_Query( $args );
		$urls  = [];

		$with_images = FW_SEO_Settings::flag( 'sitemap_images', true );

		foreach ( $query->posts as $post ) {
			$permalink = get_permalink( $post );

			if ( ! $permalink ) {
				continue;
			}

			$entry = [
				'loc'     => $permalink,
				'lastmod' => fw_seo_sitemap_date( $post->post_modified_gmt ),
			];

			if ( $with_images ) {
				$entry['images'] = fw_seo_sitemap_post_images( $post );
			}

			$urls[] = $entry;
		}

		return $urls;
	}
endif;

if ( ! function_exists( 'fw_seo_sitemap_post_images' ) ) :
	/**
	 * Images to list for a post: the featured image, plus any in the content.
	 *
	 * Capped deliberately — a gallery page can carry hundreds, and a sitemap
	 * listing every thumbnail on a site is mostly weight.
	 *
	 * @param WP_Post $post
	 *
	 * @return array<int,string>
	 */
	function fw_seo_sitemap_post_images( WP_Post $post ) {
		$images = [];

		if ( has_post_thumbnail( $post ) ) {
			$featured = wp_get_attachment_image_url( get_post_thumbnail_id( $post ), 'full' );

			if ( $featured ) {
				$images[] = $featured;
			}
		}

		// The builder syncs rendered markup into post_content, so a simple scan
		// finds builder images as well as classic ones.
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $post->post_content, $matches ) ) {
			foreach ( $matches[1] as $src ) {
				$src = trim( $src );

				// Skip data URIs and anything off-site: an image sitemap is a
				// claim of ownership, and neither qualifies.
				if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
					continue;
				}

				if ( false === strpos( $src, (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
					continue;
				}

				$images[] = $src;
			}
		}

		/** Filters the images listed for one post in the image sitemap. */
		$images = (array) apply_filters( 'fw_seo_sitemap_post_images', $images, $post );

		/** Filters how many images a single sitemap entry may list. */
		$limit = (int) apply_filters( 'fw_seo_sitemap_images_per_url', 10 );

		return array_slice( array_values( array_unique( $images ) ), 0, max( 1, $limit ) );
	}
endif;

if ( ! function_exists( 'fw_seo_sitemap_term_count' ) ) :
	/**
	 * @param string $taxonomy
	 *
	 * @return int
	 */
	function fw_seo_sitemap_term_count( $taxonomy ) {
		$count = wp_count_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
		] );

		return is_wp_error( $count ) ? 0 : (int) $count;
	}
endif;

if ( ! function_exists( 'fw_seo_sitemap_term_urls' ) ) :
	/**
	 * @param string $taxonomy
	 * @param int    $page
	 * @param int    $per_page
	 *
	 * @return array<int,array>
	 */
	function fw_seo_sitemap_term_urls( $taxonomy, $page, $per_page ) {
		$terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
			'number'     => (int) $per_page,
			'offset'     => ( max( 1, (int) $page ) - 1 ) * (int) $per_page,
			'orderby'    => 'id',
			// An empty archive is a thin page; hide_empty already covers most,
			// and a term the editor marked noindex is excluded below.
			'meta_query' => [
				[
					'key'     => FW_SEO_Store::meta_key( 'noindex' ),
					'compare' => 'NOT EXISTS',
				],
			],
		] );

		if ( is_wp_error( $terms ) ) {
			return [];
		}

		$urls = [];

		foreach ( $terms as $term ) {
			$link = get_term_link( $term );

			if ( is_wp_error( $link ) ) {
				continue;
			}

			$urls[] = [ 'loc' => $link ];
		}

		return $urls;
	}
endif;
