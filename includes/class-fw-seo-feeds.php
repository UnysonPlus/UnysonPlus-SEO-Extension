<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Feed cleanup — switch off the feeds a site does not use.
 *
 * WordPress publishes a feed for nearly everything: every category, tag and
 * author, every search, every post's comments. Few sites use more than the
 * main one, and the rest are thin duplicate documents search engines crawl.
 * Each type switched off loses its <link> in the page head (core's own
 * feed_links_* filters) and its address redirects (301) to the page it is the
 * feed of. The main feed is not offered: it is the one people subscribe to.
 *
 * Nothing reads settings during startup (the option-types trap).
 *
 * @since 2.0.47
 */
class FW_SEO_Feeds {

	/** The kinds that can be switched off, in the order the setting offers them. */
	const KINDS = [ 'comments', 'taxonomy', 'author', 'search', 'post_type' ];

	public static function init() {
		add_action( 'template_redirect', [ __CLASS__, '_maybe_redirect' ], 1 );
		add_action( 'wp_head', [ __CLASS__, '_head_filters' ], 1 );
	}

	/** @return string[] The kinds switched off. */
	public static function off() {
		$value = (array) FW_SEO_Settings::get( 'feeds_off', [] );
		$out   = [];

		foreach ( $value as $k => $v ) {
			$kind = is_int( $k ) ? (string) $v : ( $v ? (string) $k : '' );
			if ( in_array( $kind, self::KINDS, true ) ) {
				$out[] = $kind;
			}
		}

		return $out;
	}

	/** @internal Remove the head links, just before core prints them (priority 2/3). */
	public static function _head_filters() {
		$off = self::off();

		if ( ! $off ) {
			return;
		}

		$no = '__return_false';

		if ( in_array( 'comments', $off, true ) ) {
			add_filter( 'feed_links_show_comments_feed', $no );
			add_filter( 'feed_links_extra_show_post_comments_feed', $no );
		}
		if ( in_array( 'taxonomy', $off, true ) ) {
			add_filter( 'feed_links_extra_show_category_feed', $no );
			add_filter( 'feed_links_extra_show_tag_feed', $no );
			add_filter( 'feed_links_extra_show_tax_feed', $no );
		}
		if ( in_array( 'author', $off, true ) ) {
			add_filter( 'feed_links_extra_show_author_feed', $no );
		}
		if ( in_array( 'search', $off, true ) ) {
			add_filter( 'feed_links_extra_show_search_feed', $no );
		}
		if ( in_array( 'post_type', $off, true ) ) {
			add_filter( 'feed_links_extra_show_post_type_archive_feed', $no );
		}
	}

	/**
	 * Which kind of feed the current request is, or ''.
	 *
	 * @return string
	 */
	public static function current_kind() {
		if ( ! is_feed() ) {
			return '';
		}

		if ( is_comment_feed() ) {
			return 'comments';
		}
		if ( is_search() ) {
			return 'search';
		}
		if ( is_author() ) {
			return 'author';
		}
		if ( is_category() || is_tag() || is_tax() ) {
			return 'taxonomy';
		}
		if ( is_post_type_archive() ) {
			return 'post_type';
		}

		return 'main';
	}

	/**
	 * The page a feed address belongs to: the address without its feed part.
	 *
	 * "/category/news/feed/" → "/category/news/"; "/?s=x&feed=rss2" → "/?s=x";
	 * "/my-post/feed/" → "/my-post/".
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	public static function page_of( $url ) {
		$parts = wp_parse_url( (string) $url );
		$path  = preg_replace( '#/feed(/(rss2?|atom|rdf))?/?$#i', '/', (string) ( $parts['path'] ?? '/' ) );
		$path  = preg_replace( '#/(rss2?|atom|rdf)/?$#i', '/', $path );
		// The site-wide comment feed (/comments/feed/) has no page of its own.
		$path  = preg_replace( '#/comments/$#i', '/', $path );
		$query = '';

		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $args );
			unset( $args['feed'], $args['withcomments'] );
			$query = $args ? '?' . http_build_query( $args ) : '';
		}

		$host = isset( $parts['host'] ) ? ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) : '';

		return $host . $path . $query;
	}

	/** @internal */
	public static function _maybe_redirect() {
		$kind = self::current_kind();

		if ( '' === $kind || 'main' === $kind || ! in_array( $kind, self::off(), true ) ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		wp_safe_redirect( home_url( self::relative_page( $uri ) ), 301, 'Unyson+ SEO' );
		exit;
	}

	/** @return string The page address relative to the home URL. */
	protected static function relative_page( $uri ) {
		$page = self::page_of( $uri );
		$base = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

		if ( '' !== $base && 0 === strpos( $page, $base ) ) {
			$page = substr( $page, strlen( $base ) );
		}

		return '' === $page ? '/' : $page;
	}
}
