<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * IndexNow — tell the participating search engines (Bing, Yandex, Naver,
 * Seznam and others; they share submissions) the moment a page is published or
 * updated, instead of waiting for them to recrawl.
 *
 * Ownership is proven with a key file: a random key, served as plain text at
 * /<key>.txt. No account and no API key from anyone. Google does not take part.
 *
 * Submissions are collected during the request and sent once, on shutdown,
 * without waiting for a reply, so saving a post is never slowed down. The same
 * address is not sent again within ten minutes (autosaves and repeated saves).
 *
 * Settings are read inside the hooks, never during startup (the option-types
 * trap — see FW_SEO_Redirects).
 *
 * @since 2.0.39
 */
class FW_SEO_IndexNow {

	const ENDPOINT   = 'https://api.indexnow.org/indexnow';
	const KEY_OPTION = 'fw_seo_indexnow_key';
	const LOG_OPTION = 'fw_seo_indexnow_log';
	const THROTTLE   = 600;

	/** Swapped for status_html() when the settings page is drawn. */
	const PLACEHOLDER = '<!--fw-seo-indexnow-status-->';

	/** @var string[] */
	protected static $queue = [];

	public static function init() {
		add_action( 'parse_request', [ __CLASS__, '_serve_key' ], 0 );
		add_action( 'transition_post_status', [ __CLASS__, '_on_status' ], 20, 3 );
		add_action( 'shutdown', [ __CLASS__, '_send' ] );
	}

	/** @return bool */
	public static function enabled() {
		return class_exists( 'FW_SEO_Settings' ) && FW_SEO_Settings::flag( 'indexnow', false ) && '0' !== (string) get_option( 'blog_public' );
	}

	/**
	 * The site's key, made on first use. 32 hex characters (the protocol allows 8–128).
	 *
	 * @return string
	 */
	public static function key() {
		$key = (string) get_option( self::KEY_OPTION, '' );

		if ( ! preg_match( '#^[a-f0-9]{32}$#', $key ) ) {
			$key = bin2hex( random_bytes( 16 ) );
			update_option( self::KEY_OPTION, $key, true );
		}

		return $key;
	}

	/** @return string */
	public static function key_url() {
		return home_url( '/' . self::key() . '.txt' );
	}

	/** @internal Serve /<key>.txt. */
	public static function _serve_key() {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( '.txt' !== substr( $path, -4 ) || ! self::enabled() ) {
			return;
		}

		if ( rtrim( $path, '/' ) !== (string) wp_parse_url( self::key_url(), PHP_URL_PATH ) ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo self::key(); // phpcs:ignore WordPress.Security.EscapeOutput -- 32 hex characters.
		exit;
	}

	/**
	 * Should a status change be submitted?
	 *
	 * @param string $new
	 * @param string $old
	 *
	 * @return bool
	 */
	public static function worth_sending( $new, $old = '' ) {
		// Published, or updated while published. A post taken down is left to
		// the next crawl: by then WordPress has already changed its address
		// (a trashed post's slug gets a suffix), so there is nothing true to send.
		return 'publish' === $new;
	}

	/** @internal */
	public static function _on_status( $new, $old, $post ) {
		if ( ! self::worth_sending( $new, $old ) || ! $post instanceof WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( ! class_exists( 'FW_SEO_Locations' ) || ! isset( FW_SEO_Locations::post_types()[ $post->post_type ] ) || ! self::enabled() ) {
			return;
		}

		$url = (string) get_permalink( $post );

		if ( '' !== $url ) {
			self::$queue[] = $url;
		}
	}

	/**
	 * Drop addresses sent within the throttle window, and anything off this site.
	 *
	 * @param string[] $urls
	 * @param array    $sent url => time last sent.
	 * @param int      $now
	 * @param string   $host
	 *
	 * @return string[]
	 */
	public static function due( array $urls, array $sent, $now, $host ) {
		$out = [];

		foreach ( array_unique( $urls ) as $url ) {
			if ( (string) wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
				continue;
			}

			if ( isset( $sent[ $url ] ) && $now - (int) $sent[ $url ] < self::THROTTLE ) {
				continue;
			}

			$out[] = $url;
		}

		return $out;
	}

	/**
	 * @param string[] $urls
	 *
	 * @return array The request body.
	 */
	public static function payload( array $urls ) {
		return [
			'host'        => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'key'         => self::key(),
			'keyLocation' => self::key_url(),
			'urlList'     => array_values( $urls ),
		];
	}

	/** @internal */
	public static function _send() {
		if ( ! self::$queue ) {
			return;
		}

		$log  = (array) get_option( self::LOG_OPTION, [] );
		$sent = (array) ( $log['sent'] ?? [] );
		$now  = time();
		$urls = self::due( self::$queue, $sent, $now, (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		self::$queue = [];

		if ( ! $urls ) {
			return;
		}

		wp_remote_post( self::ENDPOINT, [
			'blocking' => false,
			'timeout'  => 3,
			'headers'  => [ 'Content-Type' => 'application/json; charset=utf-8' ],
			'body'     => wp_json_encode( self::payload( $urls ) ),
		] );

		foreach ( $urls as $url ) {
			$sent[ $url ] = $now;
		}

		// Keep the throttle list small: only what is still inside the window.
		$sent = array_filter( $sent, static function ( $t ) use ( $now ) {
			return $now - (int) $t < self::THROTTLE;
		} );

		$recent = array_slice( array_merge( array_map( static function ( $u ) use ( $now ) {
			return [ 'url' => $u, 'time' => $now ];
		}, $urls ), (array) ( $log['recent'] ?? [] ) ), 0, 10 );

		update_option( self::LOG_OPTION, [ 'sent' => $sent, 'recent' => $recent ], false );
	}

	/**
	 * Status for the settings page: the key file and the last submissions.
	 *
	 * @return string
	 */
	public static function status_html() {
		$log    = (array) get_option( self::LOG_OPTION, [] );
		$recent = (array) ( $log['recent'] ?? [] );
		$html   = '<p>' . sprintf(
			/* translators: %s: the key file URL */
			esc_html__( 'Key file: %s', 'fw' ),
			'<a href="' . esc_url( self::key_url() ) . '" target="_blank" rel="noopener"><code>' . esc_html( self::key_url() ) . '</code></a>'
		) . '</p>';

		if ( '0' === (string) get_option( 'blog_public' ) ) {
			$html .= '<p>' . esc_html__( 'Nothing is sent while Settings → Reading asks search engines not to index the site.', 'fw' ) . '</p>';
		}

		if ( ! $recent ) {
			return $html . '<p>' . esc_html__( 'Nothing sent yet. Publish or update a page and it is sent within a second.', 'fw' ) . '</p>';
		}

		$html .= '<p><strong>' . esc_html__( 'Last sent', 'fw' ) . '</strong></p><ul style="margin:0">';
		foreach ( $recent as $row ) {
			$html .= '<li><code>' . esc_html( (string) $row['url'] ) . '</code> — ' . esc_html( human_time_diff( (int) $row['time'] ) ) . ' ' . esc_html__( 'ago', 'fw' ) . '</li>';
		}

		return $html . '</ul>';
	}
}
