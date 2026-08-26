<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Public helpers.
 *
 * These are a contract — themes and other extensions will call them — so the
 * names carry the extension prefix and renaming one is a breaking change.
 */

if ( ! function_exists( 'fw_seo_context' ) ) :
	/**
	 * The SEO context for the current request, resolved once.
	 *
	 * @return FW_SEO_Context|null Null before the `wp` action has run.
	 */
	function fw_seo_context() {
		$extension = fw()->extensions->get( 'seo' );

		return $extension ? $extension->get_context() : null;
	}
endif;

if ( ! function_exists( 'fw_seo_separator' ) ) :
	/**
	 * The configured title separator.
	 *
	 * @return string
	 */
	function fw_seo_separator() {
		$separator = (string) FW_SEO_Settings::get( 'separator', '|' );

		/** Filters the separator character used by the %%sep%% tag. */
		return (string) apply_filters( 'fw_seo_separator', $separator );
	}
endif;

if ( ! function_exists( 'fw_seo_description_limit' ) ) :
	/**
	 * How long an auto-generated description may be.
	 *
	 * Only auto-generated text is trimmed — a description the user wrote is
	 * theirs, and silently cutting it would be worse than letting a search
	 * engine decide where to end the snippet.
	 *
	 * @return int
	 */
	function fw_seo_description_limit() {
		/** Filters the character limit applied to auto-generated meta descriptions. */
		return (int) apply_filters( 'fw_seo_description_limit', 160 );
	}
endif;

if ( ! function_exists( 'fw_seo_truncate' ) ) :
	/**
	 * Trim to a character limit on a word boundary, never mid-word.
	 *
	 * @param string $text
	 * @param int    $limit
	 *
	 * @return string
	 */
	function fw_seo_truncate( $text, $limit ) {
		$text  = trim( (string) $text );
		$limit = (int) $limit;

		if ( $limit < 1 || '' === $text ) {
			return $text;
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );

		if ( $length <= $limit ) {
			return $text;
		}

		$cut  = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit, 'UTF-8' ) : substr( $text, 0, $limit );
		$next = function_exists( 'mb_substr' ) ? mb_substr( $text, $limit, 1, 'UTF-8' ) : substr( $text, $limit, 1 );

		// When the cut already lands on a word boundary it is clean as it is —
		// stepping back regardless would drop a whole word that fitted.
		if ( ' ' !== $next ) {
			$space = function_exists( 'mb_strrpos' ) ? mb_strrpos( $cut, ' ', 0, 'UTF-8' ) : strrpos( $cut, ' ' );

			// Only step back when a word remains; on a single very long word,
			// a hard cut beats returning almost nothing.
			if ( false !== $space && $space > (int) ( $limit * 0.5 ) ) {
				$cut = function_exists( 'mb_substr' ) ? mb_substr( $cut, 0, $space, 'UTF-8' ) : substr( $cut, 0, $space );
			}
		}

		return rtrim( $cut, " \t\n\r\0\x0B,;:-" );
	}
endif;

if ( ! function_exists( 'fw_seo_scalar' ) ) :
	/**
	 * Meta values can be anything; a tag needs a string.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	function fw_seo_scalar( $value ) {
		if ( is_string( $value ) || is_numeric( $value ) ) {
			return (string) $value;
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}

		if ( is_array( $value ) ) {
			$flat = array_filter( $value, static function ( $item ) {
				return is_string( $item ) || is_numeric( $item );
			} );

			return implode( ', ', $flat );
		}

		return '';
	}
endif;

if ( ! function_exists( 'fw_seo_term_names' ) ) :
	/**
	 * A post's terms in one taxonomy, comma separated.
	 *
	 * @param int    $post_id
	 * @param string $taxonomy
	 *
	 * @return string
	 */
	function fw_seo_term_names( $post_id, $taxonomy ) {
		$post_id = (int) $post_id;

		if ( ! $post_id || ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}

		$terms = get_the_terms( $post_id, $taxonomy );

		if ( ! is_array( $terms ) ) {
			return '';
		}

		return implode( ', ', wp_list_pluck( $terms, 'name' ) );
	}
endif;

if ( ! function_exists( 'fw_seo_primary_term' ) ) :
	/**
	 * A post's primary term in a taxonomy.
	 *
	 * A post filed in several unrelated terms has no natural "main" one, so
	 * picking the first would change between requests as terms are reordered.
	 * An editor-chosen primary term recorded by Yoast or Rank Math is honoured
	 * first — the breadcrumbs extension already reads the same keys, and the two
	 * disagreeing about a post's category would be worse than either choice.
	 *
	 * @param int    $post_id
	 * @param string $taxonomy
	 *
	 * @return WP_Term|null
	 */
	function fw_seo_primary_term( $post_id, $taxonomy ) {
		$post_id = (int) $post_id;

		if ( ! $post_id || ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}

		/** Filters the post meta keys consulted for an editor-chosen primary term. */
		$meta_keys = (array) apply_filters( 'fw_seo_primary_term_meta_keys', [
			'_fw_seo_primary_' . $taxonomy,
			'_yoast_wpseo_primary_' . $taxonomy,
			'rank_math_primary_' . $taxonomy,
		], $taxonomy );

		foreach ( $meta_keys as $meta_key ) {
			$term_id = (int) get_post_meta( $post_id, $meta_key, true );

			if ( ! $term_id ) {
				continue;
			}

			$term = get_term( $term_id, $taxonomy );

			if ( $term instanceof WP_Term ) {
				return $term;
			}
		}

		$terms = get_the_terms( $post_id, $taxonomy );

		return is_array( $terms ) && isset( $terms[0] ) ? $terms[0] : null;
	}
endif;

if ( ! function_exists( 'fw_seo_archive_title' ) ) :
	/**
	 * A readable title for whatever archive the context describes.
	 *
	 * Deliberately not get_the_archive_title(): that prefixes with "Category:"
	 * and friends, which is right on a page heading and wrong inside a <title>.
	 *
	 * @param FW_SEO_Context $ctx
	 *
	 * @return string
	 */
	function fw_seo_archive_title( FW_SEO_Context $ctx ) {
		switch ( $ctx->type() ) {
			case FW_SEO_Context::TERM:
				return $ctx->term() ? $ctx->term()->name : '';

			case FW_SEO_Context::AUTHOR:
				$user = $ctx->user();

				return $user ? (string) $user->display_name : '';

			case FW_SEO_Context::POST_TYPE_ARCHIVE:
				$object = $ctx->post_type() ? get_post_type_object( $ctx->post_type() ) : null;

				return $object ? (string) $object->labels->name : '';

			case FW_SEO_Context::SEARCH:
				return $ctx->search_query();

			case FW_SEO_Context::DATE:
				$date = $ctx->date();

				if ( $date['day'] ) {
					return wp_date(
						(string) get_option( 'date_format' ),
						(int) mktime( 0, 0, 0, $date['month'], $date['day'], $date['year'] )
					);
				}

				if ( $date['month'] ) {
					return wp_date( 'F Y', (int) mktime( 0, 0, 0, $date['month'], 1, $date['year'] ) );
				}

				return $date['year'] ? (string) $date['year'] : '';

			case FW_SEO_Context::FRONT_PAGE:
				return (string) get_bloginfo( 'name' );

			default:
				return $ctx->has_post() ? (string) get_the_title( $ctx->post() ) : '';
		}
	}
endif;

if ( ! function_exists( 'fw_seo_auto_description' ) ) :
	/**
	 * Generate a description from whatever the context is about.
	 *
	 * @param FW_SEO_Context $ctx
	 *
	 * @return string
	 */
	function fw_seo_auto_description( FW_SEO_Context $ctx ) {
		$description = '';

		switch ( $ctx->type() ) {
			case FW_SEO_Context::TERM:
				$description = $ctx->term() ? $ctx->term()->description : '';
				break;

			case FW_SEO_Context::AUTHOR:
				$user        = $ctx->user();
				$description = $user ? (string) $user->description : '';
				break;

			case FW_SEO_Context::POST_TYPE_ARCHIVE:
				$object      = $ctx->post_type() ? get_post_type_object( $ctx->post_type() ) : null;
				$description = $object ? (string) $object->description : '';
				break;

			case FW_SEO_Context::SEARCH:
			case FW_SEO_Context::NOT_FOUND:
				// Neither has content worth describing, and both should stay out
				// of the index anyway.
				return '';

			default:
				if ( $ctx->has_post() ) {
					$description = FW_SEO_Content::excerpt( $ctx, true );
				}
				break;
		}

		$description = FW_SEO_Content::flatten( $description );

		/** Filters the auto-generated meta description before it is trimmed to length. */
		return (string) apply_filters( 'fw_seo_auto_description', $description, $ctx );
	}
endif;

if ( ! function_exists( 'fw_seo_canonical_url' ) ) :
	/**
	 * The canonical URL for a context, paging included.
	 *
	 * Page two of an archive is its own canonical, not a duplicate of page one —
	 * pointing every page at page one hides the rest of the archive from the
	 * index, which is the opposite of what the setting is usually reached for.
	 *
	 * @param FW_SEO_Context $ctx
	 *
	 * @return string
	 */
	function fw_seo_canonical_url( FW_SEO_Context $ctx ) {
		$url = $ctx->url();

		if ( '' === $url ) {
			return '';
		}

		if ( $ctx->is_paged() ) {
			$url = $ctx->is( FW_SEO_Context::SINGULAR ) && ! $ctx->max_page()
				? $url
				: fw_seo_paged_url( $url, $ctx->page() );
		}

		/** Filters the canonical URL emitted for the current context. */
		return (string) apply_filters( 'fw_seo_canonical_url', $url, $ctx );
	}
endif;

if ( ! function_exists( 'fw_seo_paged_url' ) ) :
	/**
	 * Append the pagination segment to a URL, honouring plain permalinks.
	 *
	 * @param string $url
	 * @param int    $page
	 *
	 * @return string
	 */
	function fw_seo_paged_url( $url, $page ) {
		$page = (int) $page;

		if ( $page < 2 ) {
			return $url;
		}

		global $wp_rewrite;

		if ( ! $wp_rewrite || ! $wp_rewrite->using_permalinks() ) {
			return add_query_arg( 'paged', $page, $url );
		}

		return user_trailingslashit( trailingslashit( $url ) . $wp_rewrite->pagination_base . '/' . $page );
	}
endif;

if ( ! function_exists( 'fw_seo_robots' ) ) :
	/**
	 * The robots directives for a context, as an ordered list.
	 *
	 * Resolution order is deliberate: a per-object override wins over the
	 * per-location setting, which wins over the site-wide defaults — and the
	 * site's own "Discourage search engines" checkbox beats all of them, because
	 * silently indexing a site whose owner asked us not to would be indefensible.
	 *
	 * @param FW_SEO_Context $ctx
	 *
	 * @return array<int,string>
	 */
	function fw_seo_robots( FW_SEO_Context $ctx ) {
		if ( ! get_option( 'blog_public' ) ) {
			return [ 'noindex', 'nofollow' ];
		}

		$noindex  = (bool) FW_SEO_Store::get( $ctx, 'noindex' );
		$nofollow = (bool) FW_SEO_Store::get( $ctx, 'nofollow' );

		if ( ! $noindex ) {
			$noindex = FW_SEO_Settings::flag( FW_SEO_Settings::robots_id( 'noindex', $ctx->template_key() ), false );
		}

		if ( ! $nofollow ) {
			$nofollow = FW_SEO_Settings::flag( FW_SEO_Settings::robots_id( 'nofollow', $ctx->template_key() ), false );
		}

		// Search results and 404s are never worth indexing, and both generate
		// unbounded numbers of URLs if they are.
		if ( $ctx->is( FW_SEO_Context::SEARCH ) || $ctx->is( FW_SEO_Context::NOT_FOUND ) ) {
			$noindex = true;
		}

		$directives   = [];
		$directives[] = $noindex ? 'noindex' : 'index';
		$directives[] = $nofollow ? 'nofollow' : 'follow';

		foreach ( (array) FW_SEO_Store::get( $ctx, 'robots_advanced' ) as $directive ) {
			$directives[] = $directive;
		}

		$max_snippet = (int) FW_SEO_Store::get( $ctx, 'max_snippet' );

		if ( $max_snippet ) {
			$directives[] = 'max-snippet:' . $max_snippet;
		}

		$max_image = (string) FW_SEO_Store::get( $ctx, 'max_image_preview' );

		if ( '' !== $max_image ) {
			$directives[] = 'max-image-preview:' . $max_image;
		}

		$max_video = (int) FW_SEO_Store::get( $ctx, 'max_video_preview' );

		if ( $max_video ) {
			$directives[] = 'max-video-preview:' . $max_video;
		}

		/** Filters the robots directives emitted for the current context. */
		$directives = (array) apply_filters( 'fw_seo_robots', $directives, $ctx );

		return array_values( array_unique( array_filter( $directives ) ) );
	}
endif;

if ( ! function_exists( 'fw_seo_title' ) ) :
	/**
	 * The resolved SEO title for the current request.
	 *
	 * @return string
	 */
	function fw_seo_title() {
		$ctx = fw_seo_context();

		return $ctx ? FW_SEO_Chain::resolve( 'title', $ctx ) : '';
	}
endif;

if ( ! function_exists( 'fw_seo_description' ) ) :
	/**
	 * The resolved meta description for the current request.
	 *
	 * @return string
	 */
	function fw_seo_description() {
		$ctx = fw_seo_context();

		return $ctx ? FW_SEO_Chain::resolve( 'description', $ctx ) : '';
	}
endif;

if ( ! function_exists( 'fw_seo_og_type' ) ) {
	/**
	 * The Open Graph object type for a context.
	 *
	 * Kept narrow on purpose. Open Graph defines a long list of types, most of
	 * which change how a card renders only on networks nobody targets, and
	 * claiming `article` for a contact page is a small lie that costs a real
	 * signal. Anything that is not a dated, authored post is a `website`.
	 *
	 * @param FW_SEO_Context $ctx
	 *
	 * @return string
	 */
	function fw_seo_og_type( FW_SEO_Context $ctx ) {
		$type = 'website';

		if ( $ctx->is( FW_SEO_Context::SINGULAR ) && $ctx->post_id() ) {
			$post_type = get_post_type( $ctx->post_id() );

			// A hierarchical type is a page: no author byline, no publish date
			// that means anything. Everything else behaves like a post.
			$object = get_post_type_object( $post_type );

			if ( $object && ! $object->hierarchical ) {
				$type = 'article';
			}
		}

		/** Filters the Open Graph object type for the current page. */
		return (string) apply_filters( 'fw_seo_og_type', $type, $ctx );
	}
}

if ( ! function_exists( 'fw_seo_at_handle' ) ) {
	/**
	 * Normalise a social handle to `@name`.
	 *
	 * People paste all three of `name`, `@name` and the full profile URL, and
	 * Twitter's tag only accepts the middle one — so accept all three rather
	 * than emitting a tag that silently does nothing.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	function fw_seo_at_handle( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( false !== strpos( $value, '/' ) ) {
			$value = trim( (string) wp_parse_url( $value, PHP_URL_PATH ), '/' );
			$value = explode( '/', $value )[0];
		}

		$value = ltrim( $value, '@' );
		$value = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $value );

		return '' === $value ? '' : '@' . $value;
	}
}
