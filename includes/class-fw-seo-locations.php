<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The set of places a template can be defined for.
 *
 * One list, used by three consumers that used to each roll their own: the
 * settings screen (which renders a box per location), the settings reader
 * (which maps a context to its keys), and the metabox (which decides whether a
 * post type gets SEO fields at all).
 *
 * A location key is `FW_SEO_Context::template_key()` — `post:page`,
 * `tax:category`, `archive:product`, or a bare context type like `front_page`.
 */
class FW_SEO_Locations {

	/** @var array|null */
	protected static $cache = null;

	/**
	 * @return array<string,array{key:string,label:string,group:string,kind:string,object:string}>
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$locations = [];

		$locations['front_page'] = [
			'key'    => 'front_page',
			'label'  => __( 'Homepage', 'fw' ),
			'group'  => 'general',
			'kind'   => 'singleton',
			'object' => '',
		];

		$locations['blog_page'] = [
			'key'    => 'blog_page',
			'label'  => __( 'Blog index', 'fw' ),
			'group'  => 'general',
			'kind'   => 'singleton',
			'object' => '',
		];

		foreach ( self::post_types() as $post_type => $object ) {
			$locations[ 'post:' . $post_type ] = [
				'key'    => 'post:' . $post_type,
				'label'  => $object->labels->name,
				'group'  => 'post_types',
				'kind'   => 'post_type',
				'object' => $post_type,
			];

			if ( ! empty( $object->has_archive ) ) {
				$locations[ 'archive:' . $post_type ] = [
					/* translators: %s: post type plural label. */
					'label'  => sprintf( __( '%s archive', 'fw' ), $object->labels->name ),
					'key'    => 'archive:' . $post_type,
					'group'  => 'archives',
					'kind'   => 'post_type_archive',
					'object' => $post_type,
				];
			}
		}

		foreach ( self::taxonomies() as $taxonomy => $object ) {
			$locations[ 'tax:' . $taxonomy ] = [
				'key'    => 'tax:' . $taxonomy,
				'label'  => $object->labels->name,
				'group'  => 'taxonomies',
				'kind'   => 'taxonomy',
				'object' => $taxonomy,
			];
		}

		$locations['author'] = [
			'key'    => 'author',
			'label'  => __( 'Author archives', 'fw' ),
			'group'  => 'archives',
			'kind'   => 'singleton',
			'object' => '',
		];

		$locations['date'] = [
			'key'    => 'date',
			'label'  => __( 'Date archives', 'fw' ),
			'group'  => 'archives',
			'kind'   => 'singleton',
			'object' => '',
		];

		$locations['search'] = [
			'key'    => 'search',
			'label'  => __( 'Search results', 'fw' ),
			'group'  => 'special',
			'kind'   => 'singleton',
			'object' => '',
		];

		$locations['404'] = [
			'key'    => '404',
			'label'  => __( 'Not found (404)', 'fw' ),
			'group'  => 'special',
			'kind'   => 'singleton',
			'object' => '',
		];

		/** Filters the list of locations that can carry SEO title/description templates. */
		self::$cache = apply_filters( 'fw_seo_locations', $locations );

		return self::$cache;
	}

	/**
	 * Public post types that can carry SEO settings.
	 *
	 * @return array<string,WP_Post_Type>
	 */
	public static function post_types() {
		$types = get_post_types( [ 'public' => true ], 'objects' );

		unset( $types['attachment'] );

		/** Filters the post types the SEO extension manages (templates + editor metabox). */
		return apply_filters( 'fw_seo_post_types', $types );
	}

	/**
	 * Public taxonomies that can carry SEO settings.
	 *
	 * @return array<string,WP_Taxonomy>
	 */
	public static function taxonomies() {
		$taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );

		unset( $taxonomies['post_format'] );

		/** Filters the taxonomies the SEO extension manages (templates + term fields). */
		return apply_filters( 'fw_seo_taxonomies', $taxonomies );
	}

	/**
	 * Does this post type get an SEO metabox?
	 *
	 * @param string $post_type
	 *
	 * @return bool
	 */
	public static function has_post_type( $post_type ) {
		return isset( self::post_types()[ $post_type ] );
	}

	/**
	 * Does this taxonomy get SEO term fields?
	 *
	 * @param string $taxonomy
	 *
	 * @return bool
	 */
	public static function has_taxonomy( $taxonomy ) {
		return isset( self::taxonomies()[ $taxonomy ] );
	}

	/**
	 * A location key as a settings-safe id fragment: `post:page` -> `post_page`.
	 *
	 * @param string $location_key
	 *
	 * @return string
	 */
	public static function slug( $location_key ) {
		return str_replace( [ ':', '-' ], '_', (string) $location_key );
	}

	/**
	 * The default title template for a location, used when the site has not set
	 * one. Chosen so a fresh install produces sensible titles with no setup.
	 *
	 * @param string $location_key
	 *
	 * @return string
	 */
	public static function default_title( $location_key ) {
		$defaults = [
			'front_page' => '%%sitename%% %%sep%% %%sitedesc%%',
			'blog_page'  => '%%title%% %%sep%% %%sitename%%',
			'author'     => '%%author_name%% %%sep%% %%sitename%%',
			'date'       => '%%archive_date%% %%sep%% %%sitename%%',
			'search'     => '%%searchphrase%% %%sep%% %%sitename%%',
			'404'        => __( 'Page not found', 'fw' ) . ' %%sep%% %%sitename%%',
		];

		if ( isset( $defaults[ $location_key ] ) ) {
			return $defaults[ $location_key ];
		}

		if ( 0 === strpos( $location_key, 'archive:' ) ) {
			return '%%post_type_plural%% %%sep%% %%sitename%%';
		}

		if ( 0 === strpos( $location_key, 'tax:' ) ) {
			return '%%term_title%% %%sep%% %%sitename%%';
		}

		return '%%title%% %%sep%% %%sitename%%';
	}

	/**
	 * The default description template. Empty for most locations on purpose —
	 * an empty template falls through to auto-generation, which produces a
	 * better description than any generic pattern would.
	 *
	 * @param string $location_key
	 *
	 * @return string
	 */
	public static function default_description( $location_key ) {
		if ( 'front_page' === $location_key ) {
			return '%%sitedesc%%';
		}

		if ( 0 === strpos( $location_key, 'tax:' ) ) {
			return '%%term_description%%';
		}

		if ( 'author' === $location_key ) {
			return '%%author_bio%%';
		}

		return '';
	}
}
