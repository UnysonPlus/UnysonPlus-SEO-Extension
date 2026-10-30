<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * HTML sitemap — a page listing the site's content, for visitors.
 *
 * The XML sitemap is for crawlers; this is the one people ask for. Placed with
 * the [seo_sitemap] shortcode in any page (a text element in the builder, or the
 * editor), it lists each content type under its own heading, pages as a
 * nested tree, and leaves out anything kept out of search results: a page you
 * hid from Google is not one to advertise to visitors either.
 *
 *   [seo_sitemap]                          every covered type
 *   [seo_sitemap types="page,post"]        these, in this order
 *   [seo_sitemap limit="50"]               at most 50 per type (newest first)
 *   [seo_sitemap exclude="12,48"]          leave these ids out
 *
 * @since 2.0.47
 */
class FW_SEO_HTML_Sitemap {

	const TAG = 'seo_sitemap';

	public static function init() {
		add_shortcode( self::TAG, [ __CLASS__, 'render' ] );
	}

	/**
	 * Is a post kept out of search results, by its own switch or its type's?
	 *
	 * @param WP_Post $post
	 *
	 * @return bool
	 */
	protected static function hidden( $post ) {
		if ( class_exists( 'FW_SEO_Store' ) && FW_SEO_Store::get_post( $post->ID, 'noindex' ) ) {
			return true;
		}

		$key = 'post:' . $post->post_type;

		return FW_SEO_Settings::flag( FW_SEO_Settings::robots_id( 'noindex', $key ), FW_SEO_Locations::default_noindex( $key ) );
	}

	/**
	 * @param array|string $atts
	 *
	 * @return string
	 */
	public static function render( $atts = [] ) {
		$atts    = shortcode_atts( [ 'types' => '', 'limit' => 500, 'exclude' => '' ], (array) $atts, self::TAG );
		$covered = array_keys( FW_SEO_Locations::post_types() );
		$types   = '' === trim( $atts['types'] ) ? $covered : array_values( array_intersect( array_map( 'trim', explode( ',', $atts['types'] ) ), $covered ) );
		$exclude = array_filter( array_map( 'intval', explode( ',', (string) $atts['exclude'] ) ) );
		$limit   = max( 1, min( 2000, (int) $atts['limit'] ) );

		// The page this is on is not worth listing on itself.
		if ( get_the_ID() ) {
			$exclude[] = (int) get_the_ID();
		}

		$html = '<div class="fw-seo-html-sitemap">';

		foreach ( $types as $type ) {
			$object = get_post_type_object( $type );
			if ( ! $object || ! $object->public || 'attachment' === $type ) {
				continue;
			}

			$posts = get_posts( [
				'post_type'        => $type,
				'post_status'      => 'publish',
				'numberposts'      => $limit,
				'orderby'          => $object->hierarchical ? 'menu_order title' : 'date',
				'order'            => $object->hierarchical ? 'ASC' : 'DESC',
				'exclude'          => $exclude,
				'suppress_filters' => false,
			] );

			$posts = array_values( array_filter( $posts, static function ( $p ) {
				return ! self::hidden( $p ) && '' === $p->post_password;
			} ) );

			if ( ! $posts ) {
				continue;
			}

			$html .= '<section class="fw-seo-html-sitemap__type"><h2>' . esc_html( $object->labels->name ) . '</h2>';
			$html .= $object->hierarchical ? self::tree( $posts ) : self::flat( $posts );
			$html .= '</section>';
		}

		return $html . '</div>';
	}

	/** @param WP_Post[] $posts */
	protected static function flat( array $posts ) {
		$html = '<ul>';
		foreach ( $posts as $p ) {
			$html .= '<li><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ?: __( '(no title)', 'fw' ) ) . '</a></li>';
		}

		return $html . '</ul>';
	}

	/**
	 * Pages as the nested list they are. A child whose parent is not listed
	 * (hidden or excluded) moves up rather than disappearing.
	 *
	 * @param WP_Post[] $posts
	 */
	protected static function tree( array $posts ) {
		$ids      = wp_list_pluck( $posts, 'ID' );
		$children = [];
		foreach ( $posts as $p ) {
			$parent                = in_array( (int) $p->post_parent, $ids, true ) ? (int) $p->post_parent : 0;
			$children[ $parent ][] = $p;
		}

		$walk = static function ( $parent ) use ( &$walk, $children ) {
			if ( empty( $children[ $parent ] ) ) {
				return '';
			}
			$html = '<ul>';
			foreach ( $children[ $parent ] as $p ) {
				$html .= '<li><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ?: __( '(no title)', 'fw' ) ) . '</a>' . $walk( (int) $p->ID ) . '</li>';
			}

			return $html . '</ul>';
		};

		return $walk( 0 );
	}
}
