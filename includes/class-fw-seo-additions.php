<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The small site-wide additions: an extra verification tag, page numbers in
 * paginated titles, a link back in the RSS feed, and which roles may edit SEO
 * fields.
 *
 * Settings are read inside the hooks that need them, never during startup
 * (the option-types trap — see FW_SEO_Redirects).
 *
 * @since 2.0.39
 */
class FW_SEO_Additions {

	public static function init() {
		add_filter( 'the_content_feed', [ __CLASS__, '_filter_feed' ] );
		add_filter( 'the_excerpt_rss', [ __CLASS__, '_filter_feed' ] );
	}

	/* ------------------------------------------------------------------ *
	 * Additional verification
	 * ------------------------------------------------------------------ */

	/**
	 * Verification meta tags pasted whole, one per line.
	 *
	 * Only `<meta name content>` survives; anything else (a script, a link, an
	 * attribute that is not name/content) is dropped, since this text ends up
	 * in every page's head.
	 *
	 * @param string $text
	 *
	 * @return array<int, array{name: string, content: string}>
	 */
	public static function parse_verification( $text ) {
		$out = [];

		if ( ! preg_match_all( '#<meta\b([^>]*)>#i', (string) $text, $tags ) ) {
			return $out;
		}

		foreach ( $tags[1] as $attrs ) {
			$name    = self::attr( $attrs, 'name' );
			$content = self::attr( $attrs, 'content' );

			if ( '' === $name || '' === $content || ! preg_match( '#^[A-Za-z0-9:._-]{1,100}$#', $name ) ) {
				continue;
			}

			$out[] = [ 'name' => $name, 'content' => $content ];
		}

		return $out;
	}

	/**
	 * @param string $attrs
	 * @param string $name
	 *
	 * @return string
	 */
	protected static function attr( $attrs, $name ) {
		if ( preg_match( '#\b' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>/]+))#i', $attrs, $m ) ) {
			return trim( html_entity_decode( $m[1] . ( $m[2] ?? '' ) . ( $m[3] ?? '' ), ENT_QUOTES ) );
		}

		return '';
	}

	/* ------------------------------------------------------------------ *
	 * Paginated titles
	 * ------------------------------------------------------------------ */

	/**
	 * Add "Page 2 of 7" to a title on page 2 and beyond, unless it already
	 * carries the page number (a template using %%page%% or %%pagenumber%%).
	 *
	 * @param string $title
	 * @param int    $page
	 * @param int    $max   0 when unknown.
	 * @param string $sep
	 *
	 * @return string
	 */
	public static function paged_title( $title, $page, $max, $sep ) {
		$page = (int) $page;

		if ( $page < 2 || '' === $title ) {
			return $title;
		}

		$label = $max >= $page
			/* translators: 1: current page number, 2: total number of pages. */
			? sprintf( __( 'Page %1$d of %2$d', 'fw' ), $page, $max )
			/* translators: %d: current page number. */
			: sprintf( __( 'Page %d', 'fw' ), $page );

		// Already numbered: the %%page%% tag ("Page 2 of 7") anywhere, or a bare
		// %%pagenumber%% at the end. A number inside the title ("Top 2 recipes")
		// is not a page number and does not count.
		if ( false !== stripos( $title, $label ) || false !== stripos( $title, sprintf( __( 'Page %d', 'fw' ), $page ) ) || preg_match( '#(?<!\d)' . $page . '\s*$#', $title ) ) {
			return $title;
		}

		return $title . ' ' . $sep . ' ' . $label;
	}

	/**
	 * @param string         $title
	 * @param FW_SEO_Context $ctx
	 *
	 * @return string
	 */
	public static function apply_paged_title( $title, $ctx ) {
		if ( ! FW_SEO_Settings::flag( 'paged_titles', true ) || ! $ctx->is_paged() ) {
			return $title;
		}

		return self::paged_title( $title, $ctx->page(), $ctx->max_page(), (string) FW_SEO_Settings::get( 'separator', '|' ) );
	}

	/* ------------------------------------------------------------------ *
	 * RSS attribution
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $template
	 * @param string $post_url
	 * @param string $post_title
	 * @param string $site_url
	 * @param string $site_name
	 *
	 * @return string The line to add, or ''.
	 */
	public static function feed_line( $template, $post_url, $post_title, $site_url, $site_name ) {
		$template = trim( (string) $template );

		if ( '' === $template ) {
			return '';
		}

		$html = strtr( wp_kses_post( $template ), [
			'%%post_link%%' => '<a href="' . esc_url( $post_url ) . '">' . esc_html( $post_title ) . '</a>',
			'%%site_link%%' => '<a href="' . esc_url( $site_url ) . '">' . esc_html( $site_name ) . '</a>',
		] );

		return '<p>' . $html . '</p>';
	}

	/** @internal */
	public static function _filter_feed( $content ) {
		$line = self::feed_line(
			(string) FW_SEO_Settings::get( 'rss_after', '' ),
			(string) get_permalink(),
			(string) get_the_title(),
			home_url( '/' ),
			(string) get_bloginfo( 'name' )
		);

		return '' === $line ? $content : $content . "\n" . $line;
	}

	/* ------------------------------------------------------------------ *
	 * Editor access
	 * ------------------------------------------------------------------ */

	/**
	 * May this user see and change SEO fields on posts and terms?
	 *
	 * @param WP_User|null $user  Defaults to the current user.
	 * @param array|null   $roles Defaults to the setting.
	 *
	 * @return bool
	 */
	public static function can_edit( $user = null, $roles = null ) {
		$user  = $user ?: wp_get_current_user();
		$roles = null === $roles ? (array) FW_SEO_Settings::get( 'editor_roles', [] ) : (array) $roles;

		// Checkboxes store { role: true }; a plain list is accepted too.
		$allowed = [];
		foreach ( $roles as $key => $on ) {
			if ( is_int( $key ) ) {
				$allowed[] = (string) $on;
			} elseif ( $on ) {
				$allowed[] = (string) $key;
			}
		}

		if ( ! $allowed || user_can( $user, 'manage_options' ) ) {
			return true;
		}

		return (bool) array_intersect( $allowed, (array) $user->roles );
	}
}
