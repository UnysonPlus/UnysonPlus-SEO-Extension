<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Video and news sitemaps.
 *
 * Video: every page with a video, and for each video the thumbnail, title and
 * description search engines need to show a video result. Videos are found in
 * the page builder's own video elements (so the poster and the address are
 * known, not scraped out of an embed) and in the content (YouTube and Vimeo
 * links, <video> tags). A video with no thumbnail anywhere is left out:
 * search engines require one.
 *
 * News: articles from the last 48 hours, in Google News' format. Useful to an
 * approved news publisher and to nobody else, so it is off until switched on.
 *
 * Both register through the same provider registry as the built-in sitemaps.
 * Nothing reads settings during startup (the option-types trap).
 *
 * @since 2.0.49
 */
class FW_SEO_Media_Sitemaps {

	const CACHE       = 'fw_seo_video_posts';
	const NEWS_WINDOW = 2 * DAY_IN_SECONDS;
	const NEWS_MAX    = 1000;

	public static function init() {
		add_action( 'fw_seo_register_sitemap_providers', [ __CLASS__, '_register' ] );
		add_action( 'save_post', [ __CLASS__, '_flush' ] );
		add_action( 'deleted_post', [ __CLASS__, '_flush' ] );
	}

	/** @internal */
	public static function _flush() {
		delete_transient( self::CACHE );
	}

	/** @internal */
	public static function _register() {
		FW_SEO_Sitemap::register_provider( 'video', [
			'label' => __( 'Videos', 'fw' ),
			'count' => static function () {
				return FW_SEO_Settings::flag( 'sitemap_video', true ) ? count( self::video_post_ids() ) : 0;
			},
			'urls'  => static function ( $page, $per_page ) {
				$ids = array_slice( self::video_post_ids(), ( max( 1, (int) $page ) - 1 ) * $per_page, $per_page );
				$out = [];
				foreach ( $ids as $id ) {
					$videos = self::videos_for( get_post( $id ) );
					if ( $videos ) {
						$out[] = [ 'loc' => (string) get_permalink( $id ), 'lastmod' => fw_seo_sitemap_date( get_post_field( 'post_modified_gmt', $id ) ), 'videos' => $videos ];
					}
				}

				return $out;
			},
		] );

		FW_SEO_Sitemap::register_provider( 'news', [
			'label' => __( 'News', 'fw' ),
			'count' => static function () {
				return FW_SEO_Settings::flag( 'sitemap_news', false ) ? count( self::news_posts() ) : 0;
			},
			'urls'  => static function () {
				$name = trim( (string) FW_SEO_Settings::get( 'sitemap_news_name', '' ) ) ?: wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
				$lang = strtolower( substr( (string) get_bloginfo( 'language' ), 0, 2 ) ) ?: 'en';

				return array_map( static function ( $post ) use ( $name, $lang ) {
					return [
						'loc'  => (string) get_permalink( $post ),
						'news' => [
							'name'     => $name,
							'language' => $lang,
							'date'     => (string) get_post_time( 'c', true, $post ),
							'title'    => wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
						],
					];
				}, self::news_posts() );
			},
		] );
	}

	/* ------------------------------------------------------------------ *
	 * Videos — finding them (pure parts first)
	 * ------------------------------------------------------------------ */

	/** @return string The YouTube id in an address, or ''. */
	public static function youtube_id( $url ) {
		return preg_match( '#(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#', (string) $url, $m ) ? $m[1] : '';
	}

	/** @return string The Vimeo id in an address, or ''. */
	public static function vimeo_id( $url ) {
		return preg_match( '#vimeo\.com/(?:video/|.*?/)?(\d{6,})#', (string) $url, $m ) ? $m[1] : '';
	}

	/** @return bool A video file address. */
	public static function is_file( $url ) {
		return (bool) preg_match( '#^https?://\S+\.(mp4|webm|mov|m4v|ogv)(\?\S*)?$#i', (string) $url );
	}

	/**
	 * One video from an address and what is known about it.
	 *
	 * @return array|null {thumbnail, title, description, content?, player?}
	 */
	public static function video( $url, $poster, $title, $description, $fallback_thumb = '' ) {
		$url = trim( (string) $url );
		$yt  = self::youtube_id( $url );
		$vm  = self::vimeo_id( $url );

		if ( '' !== $yt ) {
			$entry = [ 'player' => 'https://www.youtube.com/embed/' . $yt ];
			$thumb = $poster ?: 'https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg';
		} elseif ( '' !== $vm ) {
			$entry = [ 'player' => 'https://player.vimeo.com/video/' . $vm ];
			$thumb = $poster ?: $fallback_thumb;
		} elseif ( self::is_file( $url ) ) {
			$entry = [ 'content' => $url ];
			$thumb = $poster ?: $fallback_thumb;
		} else {
			return null;
		}

		$title       = trim( wp_strip_all_tags( (string) $title ) );
		$description = trim( wp_strip_all_tags( (string) $description ) );

		if ( '' === (string) $thumb || '' === $title ) {
			return null;
		}

		return [ 'thumbnail' => (string) $thumb, 'title' => $title, 'description' => '' !== $description ? $description : $title ] + $entry;
	}

	/**
	 * Video addresses and posters in a page-builder tree.
	 *
	 * @param mixed $tree
	 *
	 * @return array<int, array{url: string, poster: string, title: string}>
	 */
	public static function from_builder( $tree ) {
		$out  = [];
		$walk = static function ( $node ) use ( &$walk, &$out ) {
			if ( ! is_array( $node ) ) {
				return;
			}

			if ( in_array( (string) ( $node['shortcode'] ?? '' ), [ 'media_video', 'video_popup' ], true ) ) {
				$atts   = (array) ( $node['atts'] ?? [] );
				$url    = '';
				$poster = '';
				array_walk_recursive( $atts, static function ( $v, $k ) use ( &$url, &$poster ) {
					if ( ! is_string( $v ) || '' === $v ) {
						return;
					}
					if ( '' === $url && ( self::youtube_id( $v ) || self::vimeo_id( $v ) || self::is_file( $v ) ) ) {
						$url = $v;
					} elseif ( '' === $poster && 'url' === $k && preg_match( '#\.(jpe?g|png|webp|avif)(\?|$)#i', $v ) ) {
						$poster = $v;
					}
				} );
				if ( '' !== $url ) {
					$out[] = [ 'url' => $url, 'poster' => $poster, 'title' => (string) ( $atts['caption'] ?? $atts['title'] ?? '' ) ];
				}
			}

			foreach ( $node as $child ) {
				if ( is_array( $child ) ) {
					$walk( $child );
				}
			}
		};
		$walk( $tree );

		return $out;
	}

	/**
	 * Video addresses in HTML content: links and embeds to YouTube / Vimeo, <video> files.
	 *
	 * @return array<int, array{url: string, poster: string, title: string}>
	 */
	public static function from_html( $html ) {
		$out = [];

		if ( preg_match_all( '#https?://(?:www\.)?(?:youtube(?:-nocookie)?\.com|youtu\.be|vimeo\.com|player\.vimeo\.com)/[^\s"\'<>\]]+#i', (string) $html, $m ) ) {
			foreach ( $m[0] as $url ) {
				$out[] = [ 'url' => $url, 'poster' => '', 'title' => '' ];
			}
		}

		if ( preg_match_all( '#<video\b([^>]*)>(.*?)</video>#is', (string) $html, $v, PREG_SET_ORDER ) ) {
			foreach ( $v as $tag ) {
				$src    = preg_match( '#\ssrc=["\']([^"\']+)#i', $tag[1] . $tag[2], $s ) ? $s[1] : '';
				$poster = preg_match( '#\sposter=["\']([^"\']+)#i', $tag[1], $p ) ? $p[1] : '';
				if ( '' !== $src ) {
					$out[] = [ 'url' => $src, 'poster' => $poster, 'title' => '' ];
				}
			}
		}

		return $out;
	}

	/**
	 * The sitemap entries for a post's videos, one per distinct video.
	 *
	 * @param WP_Post|null $post
	 *
	 * @return array
	 */
	public static function videos_for( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return [];
		}

		$found = self::from_html( (string) $post->post_content );
		$tree  = get_post_meta( $post->ID, 'fw:opt:ext:pb:page-builder:json', true );
		if ( is_string( $tree ) && '' !== $tree ) {
			$found = array_merge( self::from_builder( json_decode( $tree, true ) ), $found );
		}

		$featured    = (string) get_the_post_thumbnail_url( $post, 'large' );
		$description = class_exists( 'FW_SEO_Chain' ) && class_exists( 'FW_SEO_Context' ) ? FW_SEO_Chain::resolve( 'description', FW_SEO_Context::for_post( $post ) ) : '';
		$description = '' !== $description ? $description : wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 30 );
		$out         = [];
		$seen        = [];

		foreach ( $found as $f ) {
			$video = self::video( $f['url'], $f['poster'], '' !== $f['title'] ? $f['title'] : get_the_title( $post ), $description, $featured );
			$key   = $video ? ( $video['player'] ?? $video['content'] ) : '';
			if ( $video && ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$out[]        = $video;
			}
		}

		return $out;
	}

	/**
	 * Published, indexable posts that may carry a video. Narrowed in SQL, then
	 * checked; cached until any post is saved.
	 *
	 * @return int[]
	 */
	public static function video_post_ids() {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$types = array_keys( FW_SEO_Locations::post_types() );
		if ( ! $types ) {
			return [];
		}
		$in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$like = "(p.post_content LIKE '%youtu%' OR p.post_content LIKE '%vimeo%' OR p.post_content LIKE '%<video%' OR m.meta_value LIKE '%media_video%' OR m.meta_value LIKE '%video_popup%')";
		$ids  = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'fw:opt:ext:pb:page-builder:json'
			 WHERE p.post_status = 'publish' AND p.post_password = '' AND p.post_type IN ($in) AND $like ORDER BY p.post_modified DESC LIMIT 5000", // phpcs:ignore WordPress.DB
			$types
		) );

		$keep = [];
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( class_exists( 'FW_SEO_Store' ) && FW_SEO_Store::get_post( $post->ID, 'noindex' ) ) {
				continue;
			}
			if ( self::videos_for( $post ) ) {
				$keep[] = (int) $id;
			}
		}

		set_transient( self::CACHE, $keep, 12 * HOUR_IN_SECONDS );

		return $keep;
	}

	/* ------------------------------------------------------------------ *
	 * News
	 * ------------------------------------------------------------------ */

	/** @return WP_Post[] Articles published in the last 48 hours. */
	public static function news_posts() {
		$types = array_values( array_filter( array_map( 'trim', explode( ',', (string) FW_SEO_Settings::get( 'sitemap_news_types', 'post' ) ) ) ) ) ?: [ 'post' ];

		return array_values( array_filter( get_posts( [
			'post_type'        => $types,
			'post_status'      => 'publish',
			'numberposts'      => self::NEWS_MAX,
			'date_query'       => [ [ 'after' => gmdate( 'Y-m-d H:i:s', time() - self::NEWS_WINDOW ), 'column' => 'post_date_gmt' ] ],
			'has_password'     => false,
			'suppress_filters' => true,
		] ), static function ( $p ) {
			return ! ( class_exists( 'FW_SEO_Store' ) && FW_SEO_Store::get_post( $p->ID, 'noindex' ) );
		} ) );
	}
}
