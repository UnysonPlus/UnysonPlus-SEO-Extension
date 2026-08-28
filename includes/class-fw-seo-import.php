<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Bringing SEO data across from Yoast, Rank Math, SEOPress and All in One SEO.
 *
 * This is an adoption feature, not a convenience one: a site with three hundred
 * hand-written descriptions cannot switch without it, however good the engine
 * is.
 *
 * The part that is not a mapping table is the TEMPLATE TAGS. Every one of these
 * plugins has its own syntax, and Yoast's happens to be almost identical to
 * ours — which is the trap, because it makes the whole job look like a copy
 * loop. Import a Rank Math title verbatim and `%title%` is an unrecognised tag,
 * so it renders as nothing: the page silently loses its title and the owner
 * finds out from Search Console weeks later. So tags are translated, and
 * anything untranslatable is REPORTED rather than dropped.
 */
class FW_SEO_Import {

	/** How many objects one batch processes. */
	const BATCH = 100;

	/**
	 * The fields every source maps onto, in our own names.
	 *
	 * @return array<int,string>
	 */
	public static function fields() {
		return [
			'title',
			'description',
			'canonical',
			'og_title',
			'og_description',
			'og_image',
			'twitter_title',
			'twitter_description',
			'twitter_image',
		];
	}

	/**
	 * Which of those carry template tags and therefore need translating.
	 *
	 * @return array<int,string>
	 */
	public static function templated() {
		return [ 'title', 'description', 'og_title', 'og_description', 'twitter_title', 'twitter_description' ];
	}

	/**
	 * @return array<string,array>
	 */
	public static function sources() {
		$sources = [];

		$sources['yoast'] = [
			'label'   => __( 'Yoast SEO', 'fw' ),
			'storage' => 'meta',
			'meta'    => [
				'title'               => '_yoast_wpseo_title',
				'description'         => '_yoast_wpseo_metadesc',
				'canonical'           => '_yoast_wpseo_canonical',
				'og_title'            => '_yoast_wpseo_opengraph-title',
				'og_description'      => '_yoast_wpseo_opengraph-description',
				'og_image'            => '_yoast_wpseo_opengraph-image',
				'twitter_title'       => '_yoast_wpseo_twitter-title',
				'twitter_description' => '_yoast_wpseo_twitter-description',
				'twitter_image'       => '_yoast_wpseo_twitter-image',
			],
			// Yoast stores 1 = noindex, 2 = index. Anything else means "use the
			// default", which for us means storing nothing at all.
			'noindex'  => [ 'key' => '_yoast_wpseo_meta-robots-noindex', 'true' => [ '1' ] ],
			'nofollow' => [ 'key' => '_yoast_wpseo_meta-robots-nofollow', 'true' => [ '1' ] ],
			'tags'     => [
				'%%name%%'                 => '%%author_name%%',
				'%%user_description%%'     => '%%author_bio%%',
				'%%category%%'             => '%%post_categories%%',
				'%%primary_category%%'     => '%%primary_category%%',
				'%%tag%%'                  => '%%post_tags%%',
				'%%pt_single%%'            => '%%post_type_singular%%',
				'%%pt_plural%%'            => '%%post_type_plural%%',
				'%%category_description%%' => '%%term_description%%',
				'%%tag_description%%'      => '%%term_description%%',
				'%%focuskw%%'              => '',
			],
		];

		$sources['rankmath'] = [
			'label'   => __( 'Rank Math', 'fw' ),
			'storage' => 'meta',
			'meta'    => [
				'title'               => 'rank_math_title',
				'description'         => 'rank_math_description',
				'canonical'           => 'rank_math_canonical_url',
				'og_title'            => 'rank_math_facebook_title',
				'og_description'      => 'rank_math_facebook_description',
				'og_image'            => 'rank_math_facebook_image',
				'twitter_title'       => 'rank_math_twitter_title',
				'twitter_description' => 'rank_math_twitter_description',
				'twitter_image'       => 'rank_math_twitter_image',
			],
			// Rank Math keeps its directives in one serialised list.
			'robots_list' => 'rank_math_robots',
			'tags'        => [
				'%title%'            => '%%title%%',
				'%sitename%'         => '%%sitename%%',
				'%sitedesc%'         => '%%sitedesc%%',
				'%sep%'              => '%%sep%%',
				'%excerpt%'          => '%%excerpt%%',
				'%excerpt_only%'     => '%%excerpt_only%%',
				'%page%'             => '%%page%%',
				'%currentyear%'      => '%%currentyear%%',
				'%currentmonth%'     => '%%currentmonth%%',
				'%currentday%'       => '%%currentday%%',
				'%date%'             => '%%date%%',
				'%modified%'         => '%%modified%%',
				'%name%'             => '%%author_name%%',
				'%post_author%'      => '%%author_name%%',
				'%category%'         => '%%primary_category%%',
				'%categories%'       => '%%post_categories%%',
				'%tag%'              => '%%post_tags%%',
				'%tags%'             => '%%post_tags%%',
				'%term%'             => '%%term_title%%',
				'%term_description%' => '%%term_description%%',
				'%parent_title%'     => '%%parent_title%%',
				'%pt_single%'        => '%%post_type_singular%%',
				'%pt_plural%'        => '%%post_type_plural%%',
				'%search_query%'     => '%%searchphrase%%',
				'%id%'               => '%%id%%',
				'%focuskw%'          => '',
			],
		];

		$sources['seopress'] = [
			'label'   => __( 'SEOPress', 'fw' ),
			'storage' => 'meta',
			'meta'    => [
				'title'               => '_seopress_titles_title',
				'description'         => '_seopress_titles_desc',
				'canonical'           => '_seopress_robots_canonical',
				'og_title'            => '_seopress_social_fb_title',
				'og_description'      => '_seopress_social_fb_desc',
				'og_image'            => '_seopress_social_fb_img',
				'twitter_title'       => '_seopress_social_twitter_title',
				'twitter_description' => '_seopress_social_twitter_desc',
				'twitter_image'       => '_seopress_social_twitter_img',
			],
			'noindex'  => [ 'key' => '_seopress_robots_index', 'true' => [ 'yes' ] ],
			'nofollow' => [ 'key' => '_seopress_robots_follow', 'true' => [ 'yes' ] ],
			'tags'     => [
				'%%post_title%%'         => '%%title%%',
				'%%sitetitle%%'          => '%%sitename%%',
				'%%tagline%%'            => '%%sitedesc%%',
				'%%post_excerpt%%'       => '%%excerpt%%',
				'%%post_date%%'          => '%%date%%',
				'%%post_modified_date%%' => '%%modified%%',
				'%%post_author%%'        => '%%author_name%%',
				'%%_category_title%%'    => '%%post_categories%%',
				'%%tag_title%%'          => '%%post_tags%%',
				'%%search_keywords%%'    => '%%searchphrase%%',
				'%%currentpagenumber%%'  => '%%pagenumber%%',
			],
		];

		$sources['aioseo'] = [
			'label'   => __( 'All in One SEO', 'fw' ),
			// Version 4 moved out of post meta into its own table, so this
			// source cannot be read the way the other three are.
			'storage' => 'table',
			'table'   => 'aioseo_posts',
			'columns' => [
				'title'               => 'title',
				'description'         => 'description',
				'canonical'           => 'canonical_url',
				'og_title'            => 'og_title',
				'og_description'      => 'og_description',
				'og_image'            => 'og_image_custom_url',
				'twitter_title'       => 'twitter_title',
				'twitter_description' => 'twitter_description',
				'twitter_image'       => 'twitter_image_custom_url',
			],
			'flags'   => [ 'noindex' => 'robots_noindex', 'nofollow' => 'robots_nofollow' ],
			'tags'    => [
				'#post_title'       => '%%title%%',
				'#site_title'       => '%%sitename%%',
				'#tagline'          => '%%sitedesc%%',
				'#separator_sa'     => '%%sep%%',
				'#post_excerpt'     => '%%excerpt%%',
				'#post_excerpt_only' => '%%excerpt_only%%',
				'#post_content'     => '%%post_content%%',
				'#post_date'        => '%%date%%',
				'#post_year'        => '%%currentyear%%',
				'#post_month'       => '%%currentmonth%%',
				'#current_year'     => '%%currentyear%%',
				'#current_month'    => '%%currentmonth%%',
				'#current_date'     => '%%currentdate%%',
				'#author_name'      => '%%author_name%%',
				'#categories'       => '%%post_categories%%',
				'#tags'             => '%%post_tags%%',
				'#taxonomy_title'   => '%%term_title%%',
				'#term_title'       => '%%term_title%%',
				'#term_description' => '%%term_description%%',
				'#search_term'      => '%%searchphrase%%',
				'#page_number'      => '%%pagenumber%%',
			],
		];

		/** Filters the SEO import source definitions. */
		return apply_filters( 'fw_seo_import_sources', $sources );
	}

	// -------------------------------------------------------------------------
	// Template tags
	// -------------------------------------------------------------------------

	/**
	 * The tag patterns each source uses, so an untranslated one can be spotted.
	 *
	 * @param string $source
	 *
	 * @return string|null A regex, or null when the source has no tag syntax.
	 */
	public static function tag_pattern( $source ) {
		switch ( $source ) {
			case 'yoast':
			case 'seopress':
				return '/%%[a-z0-9_\-]+%%/i';

			case 'rankmath':
				// Single percent signs, and the delimiter is also the literal
				// character people put in titles ("50% off"), so the pattern
				// deliberately requires a word between two of them.
				return '/%[a-z0-9_\-]+(?:\([^)]*\))?%/i';

			case 'aioseo':
				return '/#[a-z0-9_]+/i';

			default:
				return null;
		}
	}

	/**
	 * Translate one value's tags into ours.
	 *
	 * Anything with no counterpart is left ALONE rather than stripped, and
	 * named in $unknown. Silently deleting it would leave a title that reads
	 * fine and is missing a word; leaving it visible means the person importing
	 * can see there is something to fix.
	 *
	 * @param string $value
	 * @param string $source
	 * @param array  $unknown Accumulator, by reference.
	 *
	 * @return string
	 */
	public static function translate( $value, $source, array &$unknown = [] ) {
		$value = (string) $value;

		if ( '' === trim( $value ) ) {
			return '';
		}

		$sources = self::sources();

		if ( ! isset( $sources[ $source ] ) ) {
			return $value;
		}

		$map = $sources[ $source ]['tags'] ?? [];

		// Longest first, so `%%category_description%%` is not eaten by a
		// shorter `%%category%%` rule that happens to be a prefix of it.
		uksort( $map, static function ( $a, $b ) {
			return strlen( $b ) <=> strlen( $a );
		} );

		foreach ( $map as $theirs => $ours ) {
			$value = str_ireplace( $theirs, $ours, $value );
		}

		// Whatever still looks like one of THEIR tags had no counterpart.
		$pattern = self::tag_pattern( $source );

		if ( $pattern ) {
			// Ours are already translated, so exclude anything that is now a
			// valid tag of our own before deciding something is unknown.
			$stripped = preg_replace( '/%%[a-z0-9_\-|:]+%%/i', '', $value );

			if ( preg_match_all( $pattern, (string) $stripped, $found ) ) {
				foreach ( $found[0] as $tag ) {
					$unknown[ $tag ] = isset( $unknown[ $tag ] ) ? $unknown[ $tag ] + 1 : 1;
				}
			}
		}

		return trim( $value );
	}

	// -------------------------------------------------------------------------
	// Detection
	// -------------------------------------------------------------------------

	/**
	 * How many posts a source has data for.
	 *
	 * Counts DISTINCT post ids carrying any of its keys with a non-empty value.
	 * An empty row is not data — all four plugins write empties freely, and
	 * counting them would promise an import that turns out to move nothing.
	 *
	 * @param string $source
	 *
	 * @return int
	 */
	public static function count_available( $source ) {
		global $wpdb;

		$sources = self::sources();

		if ( ! isset( $sources[ $source ] ) ) {
			return 0;
		}

		$definition = $sources[ $source ];

		if ( 'table' === ( $definition['storage'] ?? 'meta' ) ) {
			$table = $wpdb->prefix . $definition['table'];

			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				return 0;
			}

			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB -- table name validated above.
		}

		$keys = array_values( $definition['meta'] );

		foreach ( [ 'noindex', 'nofollow' ] as $flag ) {
			if ( isset( $definition[ $flag ]['key'] ) ) {
				$keys[] = $definition[ $flag ]['key'];
			}
		}

		if ( isset( $definition['robots_list'] ) ) {
			$keys[] = $definition['robots_list'];
		}

		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		$sql = $wpdb->prepare(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
			 WHERE meta_key IN ({$placeholders}) AND meta_value != '' AND meta_value IS NOT NULL", // phpcs:ignore WordPress.DB.PreparedSQL
			$keys
		);

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Every source that has something to offer, with its count.
	 *
	 * @return array<string,array{label:string,count:int}>
	 */
	public static function available() {
		$found = [];

		foreach ( self::sources() as $id => $definition ) {
			$count = self::count_available( $id );

			if ( $count > 0 ) {
				$found[ $id ] = [ 'label' => $definition['label'], 'count' => $count ];
			}
		}

		return $found;
	}

	/**
	 * The post ids a source has data for, one batch at a time.
	 *
	 * @param string $source
	 * @param int    $offset
	 * @param int    $limit
	 *
	 * @return array<int,int>
	 */
	public static function batch_ids( $source, $offset, $limit ) {
		global $wpdb;

		$sources    = self::sources();
		$definition = $sources[ $source ] ?? null;

		if ( ! $definition ) {
			return [];
		}

		$limit  = max( 1, (int) $limit );
		$offset = max( 0, (int) $offset );

		if ( 'table' === ( $definition['storage'] ?? 'meta' ) ) {
			$table = $wpdb->prefix . $definition['table'];

			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				return [];
			}

			return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT post_id FROM `{$table}` ORDER BY post_id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB
				$limit,
				$offset
			) ) );
		}

		$keys         = array_values( $definition['meta'] );
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

		$sql = $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key IN ({$placeholders}) AND meta_value != ''
			 ORDER BY post_id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL
			array_merge( $keys, [ $limit, $offset ] )
		);

		return array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	// -------------------------------------------------------------------------
	// The import
	// -------------------------------------------------------------------------

	/**
	 * Read one post's values from a source, already translated.
	 *
	 * @param string $source
	 * @param int    $post_id
	 * @param array  $unknown Accumulator, by reference.
	 *
	 * @return array<string,mixed> field => value, only what the source has.
	 */
	public static function read( $source, $post_id, array &$unknown = [] ) {
		global $wpdb;

		$sources    = self::sources();
		$definition = $sources[ $source ] ?? null;
		$values     = [];

		if ( ! $definition ) {
			return $values;
		}

		$templated = self::templated();

		if ( 'table' === ( $definition['storage'] ?? 'meta' ) ) {
			$table = $wpdb->prefix . $definition['table'];
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE post_id = %d", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB

			if ( ! $row ) {
				return $values;
			}

			foreach ( $definition['columns'] as $field => $column ) {
				$raw = (string) ( $row[ $column ] ?? '' );

				if ( '' === trim( $raw ) ) {
					continue;
				}

				$values[ $field ] = in_array( $field, $templated, true )
					? self::translate( $raw, $source, $unknown )
					: $raw;
			}

			foreach ( ( $definition['flags'] ?? [] ) as $field => $column ) {
				if ( ! empty( $row[ $column ] ) ) {
					$values[ $field ] = true;
				}
			}

			return $values;
		}

		foreach ( $definition['meta'] as $field => $key ) {
			$raw = (string) get_post_meta( $post_id, $key, true );

			if ( '' === trim( $raw ) ) {
				continue;
			}

			$values[ $field ] = in_array( $field, $templated, true )
				? self::translate( $raw, $source, $unknown )
				: $raw;
		}

		foreach ( [ 'noindex', 'nofollow' ] as $flag ) {
			if ( ! isset( $definition[ $flag ]['key'] ) ) {
				continue;
			}

			$raw = (string) get_post_meta( $post_id, $definition[ $flag ]['key'], true );

			// Only an explicit "yes" counts. Their "use the default" value must
			// not become our explicit switch, or an import would turn a site's
			// defaults into three hundred hard-coded overrides.
			if ( in_array( $raw, $definition[ $flag ]['true'], true ) ) {
				$values[ $flag ] = true;
			}
		}

		if ( isset( $definition['robots_list'] ) ) {
			$list = get_post_meta( $post_id, $definition['robots_list'], true );

			if ( is_array( $list ) ) {
				if ( in_array( 'noindex', $list, true ) ) {
					$values['noindex'] = true;
				}

				if ( in_array( 'nofollow', $list, true ) ) {
					$values['nofollow'] = true;
				}
			}
		}

		return $values;
	}

	/**
	 * Import one batch.
	 *
	 * @param string $source
	 * @param int    $offset
	 * @param bool   $overwrite Replace values this extension already holds.
	 *
	 * @return array{processed:int,imported:int,skipped:int,fields:int,unknown:array,done:bool}
	 */
	public static function run_batch( $source, $offset = 0, $overwrite = false ) {
		$ids     = self::batch_ids( $source, $offset, self::BATCH );
		$unknown = [];

		$result = [
			'processed' => 0,
			'imported'  => 0,
			'skipped'   => 0,
			'fields'    => 0,
			'unknown'   => [],
			'done'      => count( $ids ) < self::BATCH,
		];

		foreach ( $ids as $post_id ) {
			$result['processed'] ++;

			if ( ! get_post( $post_id ) ) {
				// The source table can outlive the posts it describes.
				$result['skipped'] ++;
				continue;
			}

			$values = self::read( $source, $post_id, $unknown );

			if ( ! $values ) {
				$result['skipped'] ++;
				continue;
			}

			$wrote = 0;

			foreach ( $values as $field => $value ) {
				// Never clobber something already set here unless asked. A
				// second run of an import must be safe, and someone who has
				// started editing in this extension has said something newer
				// than the plugin they are leaving.
				if ( ! $overwrite ) {
					$existing = FW_SEO_Store::get_post( $post_id, $field );

					if ( ! FW_SEO_Store::is_empty( $existing ) ) {
						continue;
					}
				}

				FW_SEO_Store::set_post( $post_id, $field, $value );
				$wrote ++;
			}

			if ( $wrote ) {
				$result['imported'] ++;
				$result['fields'] += $wrote;
			} else {
				$result['skipped'] ++;
			}
		}

		$result['unknown'] = $unknown;

		return $result;
	}

	// -------------------------------------------------------------------------
	// Admin
	// -------------------------------------------------------------------------

	const NONCE = 'fw_seo_import';

	/**
	 * Wire the AJAX endpoint. Batched rather than one long request: a site with
	 * ten thousand posts would otherwise need the import to outrun PHP's time
	 * limit, and a timeout halfway through is the worst possible outcome —
	 * partly imported, with no way to tell how far it got.
	 */
	public static function init() {
		add_action( 'wp_ajax_fw_seo_import', [ __CLASS__, '_action_ajax' ] );
	}

	/**
	 * @internal
	 */
	public static function _action_ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'fw' ) ], 403 );
		}

		$source    = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$offset    = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$overwrite = ! empty( $_POST['overwrite'] );

		if ( ! isset( self::sources()[ $source ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown source.', 'fw' ) ], 400 );
		}

		$result = self::run_batch( $source, $offset, $overwrite );

		$result['offset'] = $offset + self::BATCH;
		$result['total']  = self::count_available( $source );

		wp_send_json_success( $result );
	}
}
