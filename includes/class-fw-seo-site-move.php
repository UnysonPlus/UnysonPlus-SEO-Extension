<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Whole-site move — every address on this site, sent to the same path on a new
 * domain with one setting.
 *
 * The thing needed exactly once, on a day when getting it wrong is expensive,
 * so it is careful: off by default, a new address on a different domain or
 * nothing happens, the dashboard and the login page are never redirected (so
 * the switch can always be turned off again), administrators can still browse
 * the old site to check it, and the settings show exactly where real addresses
 * will go before anyone relies on it.
 *
 * Nothing reads settings during startup (the option-types trap).
 *
 * @since 2.0.46
 */
class FW_SEO_Site_Move {

	/** Swapped for preview_html() when the settings page is drawn. */
	const PLACEHOLDER = '<!--fw-seo-site-move-->';

	public static function init() {
		// parse_request, not template_redirect: the sitemap and robots.txt are
		// served before templates, and they move with the site too.
		add_action( 'parse_request', [ __CLASS__, '_maybe_move' ], 0 );
		add_action( 'admin_bar_menu', [ __CLASS__, '_admin_bar' ], 100 );
	}

	/**
	 * The new site's base address, or '' when the move is off or not usable.
	 *
	 * @return string
	 */
	public static function destination() {
		if ( ! FW_SEO_Settings::flag( 'site_move', false ) ) {
			return '';
		}

		return self::clean_destination( (string) FW_SEO_Settings::get( 'site_move_to', '' ), home_url( '/' ) );
	}

	/**
	 * A usable destination: http(s), a host, not this site's own host.
	 *
	 * @param string $url
	 * @param string $home
	 *
	 * @return string The base, without a trailing slash; '' when unusable.
	 */
	public static function clean_destination( $url, $home ) {
		$url  = trim( (string) $url );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( ! preg_match( '#^https?://#i', $url ) || '' === $host || preg_match( '#[\s\r\n]#', $url ) ) {
			return '';
		}

		$own = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
		if ( preg_replace( '#^www\.#', '', $host ) === preg_replace( '#^www\.#', '', $own ) ) {
			return ''; // A move to itself would loop forever.
		}

		// Keep only scheme, host, port and path: a query or fragment in the setting would be stamped on every address.
		$parts = wp_parse_url( $url );

		return strtolower( $parts['scheme'] ) . '://' . $host . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . rtrim( (string) ( $parts['path'] ?? '' ), '/' );
	}

	/**
	 * Where a request goes: the same path (relative to this site) and query on
	 * the new site.
	 *
	 * @param string $request_uri
	 * @param string $destination From clean_destination().
	 * @param string $base        This site's home path, '' at the root.
	 *
	 * @return string
	 */
	public static function target( $request_uri, $destination, $base = '' ) {
		$uri = (string) $request_uri;
		$base = rtrim( (string) $base, '/' );

		if ( '' !== $base && 0 === stripos( $uri, $base ) ) {
			$uri = substr( $uri, strlen( $base ) );
		}

		if ( '' === $uri || '/' !== $uri[0] ) {
			$uri = '/' . $uri;
		}

		return $destination . $uri;
	}

	/** @internal */
	public static function _maybe_move() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_customize_preview() ) {
			return;
		}

		$destination = self::destination();

		if ( '' === $destination || current_user_can( 'manage_options' ) ) {
			return;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$base = (string) wp_parse_url( home_url(), PHP_URL_PATH );

		// The REST API is how the dashboard and the editor work; it is not marked
		// as REST this early, so recognise it by its address.
		$rel = substr( $uri, strlen( rtrim( $base, '/' ) ) );
		if ( 0 === strpos( ltrim( $rel, '/' ), rest_get_url_prefix() ) || false !== strpos( $uri, 'rest_route=' ) ) {
			return;
		}

		// Not wp_safe_redirect: the whole point is another domain.
		wp_redirect( self::target( $uri, $destination, $base ), 301, 'Unyson+ SEO' ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/** @internal Tell administrators, who are not redirected, that everyone else is. */
	public static function _admin_bar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$destination = self::destination();

		if ( '' === $destination ) {
			return;
		}

		$bar->add_node( [
			'id'    => 'fw-seo-site-move',
			'title' => esc_html( sprintf(
				/* translators: %s: the new site's address */
				__( 'Moved to %s', 'fw' ),
				(string) wp_parse_url( $destination, PHP_URL_HOST )
			) ),
			'href'  => admin_url( 'admin.php?page=fw-seo-settings&view=redirects_tab' ),
			'meta'  => [ 'title' => __( 'Visitors are being redirected to the new site. You are not, because you are an administrator.', 'fw' ) ],
		] );
	}

	/**
	 * Where a few real addresses would go, under the setting.
	 *
	 * @return string
	 */
	public static function preview_html() {
		$raw         = trim( (string) FW_SEO_Settings::get( 'site_move_to', '' ) );
		$on          = FW_SEO_Settings::flag( 'site_move', false );
		$destination = self::clean_destination( $raw, home_url( '/' ) );

		if ( '' === $raw ) {
			return '';
		}

		if ( '' === $destination ) {
			return '<div class="notice notice-error inline"><p>' . esc_html__( 'That new address cannot be used: it must start with http:// or https:// and be on a different domain from this site. Nothing is redirected.', 'fw' ) . '</p></div>';
		}

		$base    = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$samples = [ home_url( '/' ) ];
		foreach ( get_posts( [ 'post_type' => [ 'page', 'post' ], 'post_status' => 'publish', 'numberposts' => 2, 'orderby' => 'modified' ] ) as $p ) {
			$samples[] = (string) get_permalink( $p );
		}
		$samples[] = home_url( '/sitemap.xml' );

		$html  = $on
			? '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'The move is on: every visitor and search engine is being sent to the new site.', 'fw' ) . '</strong> ' . esc_html__( 'You still see this site because you are an administrator.', 'fw' ) . '</p></div>'
			: '<p>' . esc_html__( 'Switched off. When it is on, addresses will go here:', 'fw' ) . '</p>';
		$html .= '<table class="widefat striped" style="max-width:960px"><tbody>';

		foreach ( array_unique( $samples ) as $url ) {
			$uri   = (string) wp_parse_url( $url, PHP_URL_PATH ) . ( wp_parse_url( $url, PHP_URL_QUERY ) ? '?' . wp_parse_url( $url, PHP_URL_QUERY ) : '' );
			$html .= '<tr><td><code>' . esc_html( $url ) . '</code></td><td>→</td><td><code>' . esc_html( self::target( $uri, $destination, $base ) ) . '</code></td></tr>';
		}

		return $html . '</tbody></table>';
	}
}
