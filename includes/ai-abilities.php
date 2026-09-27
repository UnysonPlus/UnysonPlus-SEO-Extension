<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * AI Assistant abilities for SEO.
 *
 * Registered through the AI Assistant's toolkit (fw_ai_register_ability), so they only exist while
 * that extension is active; they appear in the site-wide assistant, the MCP server and — for the
 * page-scoped two — the builder panel. Writes go through FW_SEO_Store (its own sanitising), are
 * snapshotted with fw_ai_snapshot() first, and can be undone with the generic undo-change ability.
 *
 *   seo-get-page     what a page's title / description / robots / canonical / social come out as,
 *                    where each value comes from (your override, a template, or auto-generated)
 *   seo-update-page  set or clear the per-page overrides
 *   seo-audit        site-wide check of published pages: missing / long / duplicate titles and descriptions, noindex
 */

if ( ! function_exists( 'fw_ext_seo_ai_register' ) ) :

	/** Editable per-page fields (FW_SEO_Store names) the AI may set. */
	function fw_ext_seo_ai_fields() {
		return array( 'title', 'description', 'canonical', 'noindex', 'nofollow', 'og_title', 'og_description', 'og_image', 'twitter_title', 'twitter_description', 'twitter_image' );
	}

	/**
	 * The resolved SEO of one post.
	 *
	 * @param int $post_id
	 * @return array
	 */
	function fw_ext_seo_ai_page( $post_id ) {
		$post = get_post( $post_id );
		$ctx  = FW_SEO_Context::for_post( $post );
		FW_SEO_Chain::flush( $ctx );
		$out = array(
			'post_id'   => (int) $post_id,
			'title'     => get_the_title( $post ),
			'url'       => get_permalink( $post ),
			'status'    => $post->post_status,
			'effective' => array(),
			'overrides' => array(),
		);
		foreach ( array( 'title', 'description', 'canonical', 'og_title', 'og_description', 'og_image' ) as $f ) {
			$value = FW_SEO_Chain::resolve( $f, $ctx );
			$out['effective'][ $f ] = array(
				'value'  => is_scalar( $value ) ? (string) $value : $value,
				'source' => (string) FW_SEO_Chain::source( $f, $ctx ), // override | template | auto …
			);
		}
		if ( function_exists( 'fw_seo_robots' ) ) {
			$out['effective']['robots'] = fw_seo_robots( $ctx );
		}
		foreach ( fw_ext_seo_ai_fields() as $f ) {
			$v = FW_SEO_Store::get_post( $post_id, $f );
			if ( $v !== '' && $v !== null && $v !== array() && $v !== false ) {
				$out['overrides'][ $f ] = $v;
			}
		}
		$t = (string) ( $out['effective']['title']['value'] ?? '' );
		$d = (string) ( $out['effective']['description']['value'] ?? '' );
		$out['hints'] = array_values( array_filter( array(
			mb_strlen( $t ) > 60 ? sprintf( 'Title is %d characters; search results show about 60.', mb_strlen( $t ) ) : '',
			$d === '' ? 'No meta description.' : '',
			mb_strlen( $d ) > 160 ? sprintf( 'Description is %d characters; search results show about 155–160.', mb_strlen( $d ) ) : '',
			( $d !== '' && mb_strlen( $d ) < 70 ) ? 'Description is short; 120–160 characters works best.' : '',
		) ) );
		return $out;
	}

	function fw_ext_seo_ai_register() {
		if ( ! function_exists( 'fw_ai_register_ability' ) || ! class_exists( 'FW_SEO_Store' ) ) {
			return;
		}

		fw_ai_register_ability( 'seo-get-page', array(
			'label'       => __( 'Get a page\'s SEO', 'fw' ),
			'description' => 'The SEO a page ACTUALLY outputs — title, meta description, canonical, robots, Open Graph — with where each value comes from (source: override = set on the page, template = from the SEO settings\' %%tag%% templates, auto = generated from the content), the per-page overrides that are set, and length hints. Read this before changing a page\'s SEO.',
			'input'       => array( 'post_id' => array( 'type' => 'integer' ) ),
			'required'    => array( 'post_id' ),
			'permission'  => 'edit_post',
			'readonly'    => true,
			'panel'       => true,
			'execute'     => function ( $in ) {
				return fw_ext_seo_ai_page( (int) $in['post_id'] );
			},
		) );

		fw_ai_register_ability( 'seo-update-page', array(
			'label'       => __( 'Update a page\'s SEO', 'fw' ),
			'description' => 'Sets per-page SEO overrides: title (aim for ≤60 characters), description (120–160), canonical (a URL), noindex / nofollow (true hides the page from search engines — only when the person asks), og_title / og_description / og_image (a URL) and twitter_* for social shares. Pass an empty string to CLEAR an override so the SEO settings\' template or the auto-generated value applies again. Only the fields you pass change. Undo with undo_change.',
			'input'       => array(
				'post_id'             => array( 'type' => 'integer' ),
				'title'               => array( 'type' => 'string' ),
				'description'         => array( 'type' => 'string' ),
				'canonical'           => array( 'type' => 'string' ),
				'noindex'             => array( 'type' => 'boolean' ),
				'nofollow'            => array( 'type' => 'boolean' ),
				'og_title'            => array( 'type' => 'string' ),
				'og_description'      => array( 'type' => 'string' ),
				'og_image'            => array( 'type' => 'string' ),
				'twitter_title'       => array( 'type' => 'string' ),
				'twitter_description' => array( 'type' => 'string' ),
				'twitter_image'       => array( 'type' => 'string' ),
			),
			'required'    => array( 'post_id' ),
			'permission'  => 'edit_post',
			'idempotent'  => true,
			'panel'       => true,
			'execute'     => function ( $in ) {
				$post_id = (int) $in['post_id'];
				if ( ! FW_SEO_Locations::has_post_type( get_post_type( $post_id ) ) ) {
					return new WP_Error( 'fw_seo_ai_type', 'SEO fields are not enabled for this post type.' );
				}
				$fields = array_values( array_intersect( fw_ext_seo_ai_fields(), array_keys( $in ) ) );
				if ( ! $fields ) {
					return new WP_Error( 'fw_seo_ai_nothing', 'Pass at least one SEO field to change.' );
				}
				$keys = array();
				foreach ( $fields as $f ) {
					$keys[] = FW_SEO_Store::meta_key( $f );
				}
				$rev = fw_ai_snapshot( array( 'post_meta' => array( $post_id => $keys ) ), 'unysonplus/seo-update-page', sprintf( 'Changed SEO (%s) — %s', implode( ', ', $fields ), get_the_title( $post_id ) ) );
				foreach ( $fields as $f ) {
					$v = $in[ $f ];
					if ( is_bool( $v ) ) {
						$v = $v ? '1' : '';
					}
					FW_SEO_Store::set_post( $post_id, $f, $v );
				}
				clean_post_cache( $post_id );
				return array(
					'ok'               => true,
					'message'          => sprintf( 'Updated SEO: %s.', implode( ', ', $fields ) ),
					'post_id'          => $post_id,
					'undo_revision_id' => $rev,
				) + fw_ext_seo_ai_page( $post_id );
			},
		) );

		fw_ai_register_ability( 'seo-audit', array(
			'label'       => __( 'Audit the site\'s SEO', 'fw' ),
			'description' => 'Checks up to 100 published pages / posts / products and lists problems a search engine or a searcher would notice: missing or auto-generated descriptions, titles or descriptions too long, duplicate titles, pages hidden with noindex. Use it before improving a site\'s SEO, then fix pages with seo_update_page.',
			'input'       => array( 'post_type' => array( 'type' => 'string', 'description' => 'Only this post type (default: all SEO-enabled types).' ) ),
			'permission'  => 'edit_posts',
			'readonly'    => true,
			'execute'     => function ( $in ) {
				$types = isset( $in['post_type'] ) && $in['post_type'] !== '' ? array( (string) $in['post_type'] ) : array_keys( FW_SEO_Locations::post_types() );
				$posts = get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'numberposts' => 100, 'orderby' => 'menu_order title', 'order' => 'ASC' ) );
				$pages = array();
				$seen  = array();
				foreach ( $posts as $p ) {
					$s      = fw_ext_seo_ai_page( $p->ID );
					$title  = (string) ( $s['effective']['title']['value'] ?? '' );
					$issues = $s['hints'];
					if ( ( $s['effective']['description']['source'] ?? '' ) === 'auto' ) {
						$issues[] = 'Description is auto-generated from the content.';
					}
					if ( ! empty( $s['overrides']['noindex'] ) ) {
						$issues[] = 'Hidden from search engines (noindex).';
					}
					$key = mb_strtolower( $title );
					if ( $title !== '' && isset( $seen[ $key ] ) ) {
						$issues[] = sprintf( 'Same title as post %d.', $seen[ $key ] );
					}
					$seen[ $key ] = $p->ID;
					if ( $issues ) {
						$pages[] = array( 'post_id' => $p->ID, 'page' => get_the_title( $p ), 'seo_title' => $title, 'issues' => $issues );
					}
				}
				return array(
					'checked' => count( $posts ),
					'summary' => $pages ? sprintf( '%d of %d pages have something to improve.', count( $pages ), count( $posts ) ) : sprintf( 'All %d pages look fine.', count( $posts ) ),
					'pages'   => $pages,
				);
			},
		) );
	}
	add_action( 'fw_ai_assistant_register_abilities', 'fw_ext_seo_ai_register' );

endif;
