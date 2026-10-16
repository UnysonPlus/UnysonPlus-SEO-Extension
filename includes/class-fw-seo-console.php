<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Google Search Console: your own data, shown where you work.
 *
 * Worth being precise about what this is and is not. It is NOT a rank tracker
 * and cannot be one — Search Console reports an average position across
 * everyone who saw the result, which varies by location, device and
 * personalisation. It is a useful trend and it is not "your ranking".
 *
 * What it genuinely adds is the half nothing else can see: whether Google has
 * indexed a page at all, and which pages earn impressions without earning
 * clicks — a title-and-description problem, which is precisely what this
 * extension exists to fix.
 *
 * The credentials are the SITE'S OWN. There is no shared client, no proxy and
 * no account with us: the site talks to Google directly with an OAuth client
 * the owner created, and the tokens never leave the database. That costs ten
 * minutes of setup and is the only arrangement consistent with everything else
 * here working without an account.
 */
class FW_SEO_Console {

	const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const API_BASE  = 'https://www.googleapis.com/webmasters/v3';

	/** Read-only: this never needs to change anything in Search Console. */
	const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

	const TOKENS_OPTION = 'fw_seo_console_tokens';
	const STATE_TRANSIENT = 'fw_seo_console_state';

	/** Google counts a day as finished some hours late; asking for today is asking for a gap. */
	const LAG_DAYS = 3;

	/**
	 * @return string
	 */
	public static function client_id() {
		return trim( (string) FW_SEO_Settings::get( 'console_client_id', '' ) );
	}

	/**
	 * @return string
	 */
	public static function client_secret() {
		return trim( (string) FW_SEO_Settings::get( 'console_client_secret', '' ) );
	}

	/**
	 * @return bool
	 */
	public static function has_credentials() {
		return '' !== self::client_id() && '' !== self::client_secret();
	}

	/**
	 * Where Google sends the visitor back to.
	 *
	 * Must match a redirect URI registered on the OAuth client exactly —
	 * including the scheme — which is the single most common reason a first
	 * connection fails.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin.php?page=fw-seo-settings&fw-seo-console=callback' );
	}

	/**
	 * @return array{access:string,refresh:string,expires:int,property:string}
	 */
	public static function tokens() {
		$stored = get_option( self::TOKENS_OPTION, [] );

		return [
			'access'   => (string) ( $stored['access'] ?? '' ),
			'refresh'  => (string) ( $stored['refresh'] ?? '' ),
			'expires'  => (int) ( $stored['expires'] ?? 0 ),
			'property' => (string) ( $stored['property'] ?? '' ),
			'connected' => (int) ( $stored['connected'] ?? 0 ),
		];
	}

	/**
	 * @param array $tokens
	 */
	public static function store_tokens( array $tokens ) {
		// `autoload` off: these are read on two admin screens, not on every
		// front-end request, and they do not belong in the alloptions cache.
		update_option( self::TOKENS_OPTION, $tokens, false );
	}

	/**
	 * @return bool
	 */
	public static function connected() {
		$tokens = self::tokens();

		return '' !== $tokens['refresh'];
	}

	public static function disconnect() {
		delete_option( self::TOKENS_OPTION );
	}

	// -------------------------------------------------------------------------
	// The OAuth dance
	// -------------------------------------------------------------------------

	/**
	 * Where to send someone to grant access.
	 *
	 * @return string '' when there are no credentials to use.
	 */
	public static function auth_url() {
		if ( ! self::has_credentials() ) {
			return '';
		}

		// A nonce would expire on its own schedule and is tied to a user
		// session; `state` has to survive a round trip through Google and come
		// back matching, so it is generated, stored and compared explicitly.
		$state = wp_generate_password( 32, false );

		set_transient( self::STATE_TRANSIENT, $state, 15 * MINUTE_IN_SECONDS );

		return add_query_arg( [
			'client_id'     => rawurlencode( self::client_id() ),
			'redirect_uri'  => rawurlencode( self::redirect_uri() ),
			'response_type' => 'code',
			'scope'         => rawurlencode( self::SCOPE ),
			// Without both of these Google returns no refresh token on a repeat
			// authorisation, and the connection silently dies in an hour.
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => rawurlencode( $state ),
		], self::AUTH_URL );
	}

	/**
	 * @param string $state
	 *
	 * @return bool
	 */
	public static function verify_state( $state ) {
		$expected = get_transient( self::STATE_TRANSIENT );

		delete_transient( self::STATE_TRANSIENT );

		return is_string( $expected ) && '' !== $expected && hash_equals( $expected, (string) $state );
	}

	/**
	 * Swap the one-time code for tokens.
	 *
	 * @param string $code
	 *
	 * @return array{ok:bool,reason:string}
	 */
	public static function exchange_code( $code ) {
		$response = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 15,
			'body'    => [
				'code'          => $code,
				'client_id'     => self::client_id(),
				'client_secret' => self::client_secret(),
				'redirect_uri'  => self::redirect_uri(),
				'grant_type'    => 'authorization_code',
			],
		] );

		return self::absorb_token_response( $response, true );
	}

	/**
	 * Trade the refresh token for a new access token.
	 *
	 * @return array{ok:bool,reason:string}
	 */
	public static function refresh() {
		$tokens = self::tokens();

		if ( '' === $tokens['refresh'] ) {
			return [ 'ok' => false, 'reason' => 'notconnected' ];
		}

		$response = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 15,
			'body'    => [
				'refresh_token' => $tokens['refresh'],
				'client_id'     => self::client_id(),
				'client_secret' => self::client_secret(),
				'grant_type'    => 'refresh_token',
			],
		] );

		return self::absorb_token_response( $response, false );
	}

	/**
	 * Read a token response and keep what it gave us.
	 *
	 * @param array|WP_Error $response
	 * @param bool           $initial Whether a refresh token is expected.
	 *
	 * @return array{ok:bool,reason:string}
	 */
	public static function absorb_token_response( $response, $initial ) {
		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'reason' => 'network' ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			// invalid_grant means the refresh token has been revoked or expired
			// — the user has to reconnect, and saying "network error" would send
			// them looking in the wrong place entirely.
			$reason = ( isset( $body['error'] ) && 'invalid_grant' === $body['error'] ) ? 'revoked' : 'rejected';

			return [ 'ok' => false, 'reason' => $reason ];
		}

		$existing = self::tokens();

		self::store_tokens( [
			'access'  => (string) $body['access_token'],
			// A refresh response does NOT include a refresh token. Overwriting
			// with the empty value would disconnect the site on every hourly
			// renewal, which is a bug that only shows up an hour later.
			'refresh' => ! empty( $body['refresh_token'] ) ? (string) $body['refresh_token'] : $existing['refresh'],
			'expires' => time() + max( 60, (int) ( $body['expires_in'] ?? 3600 ) ) - 60,
			'property' => $existing['property'],
			// When the connection was first made. Kept because the commonest
			// failure has a date-shaped signature: Google kills refresh tokens
			// from an app left in Testing after exactly seven days.
			'connected' => $existing['connected'] ?: time(),
		] );

		return [ 'ok' => true, 'reason' => '' ];
	}

	/**
	 * A usable access token, renewed if it has expired.
	 *
	 * @return string '' when not connected or the renewal failed.
	 */
	public static function access_token() {
		$tokens = self::tokens();

		if ( '' === $tokens['refresh'] ) {
			return '';
		}

		if ( '' !== $tokens['access'] && $tokens['expires'] > time() ) {
			return $tokens['access'];
		}

		$result = self::refresh();

		return $result['ok'] ? self::tokens()['access'] : '';
	}

	// -------------------------------------------------------------------------
	// Talking to the API
	// -------------------------------------------------------------------------

	/**
	 * @param string $path
	 * @param array|null $body POST when given, GET otherwise.
	 *
	 * @return array{ok:bool,reason:string,data:array}
	 */
	public static function request( $path, ?array $body = null ) {
		$token = self::access_token();

		if ( '' === $token ) {
			return [ 'ok' => false, 'reason' => self::connected() ? 'revoked' : 'notconnected', 'data' => [] ];
		}

		$args = [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
		];

		if ( null === $body ) {
			$response = wp_remote_get( self::API_BASE . $path, $args );
		} else {
			$args['body'] = wp_json_encode( $body );
			$response     = wp_remote_post( self::API_BASE . $path, $args );
		}

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'reason' => 'network', 'data' => [] ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$reason = 'error';

			if ( 401 === $code ) {
				$reason = 'revoked';
			} elseif ( 403 === $code ) {
				// 403 here almost always means the connected Google account
				// cannot see this property, not that the token is bad — and
				// those lead to completely different fixes.
				$reason = 'noaccess';
			} elseif ( 429 === $code ) {
				$reason = 'ratelimited';
			}

			return [ 'ok' => false, 'reason' => $reason, 'data' => is_array( $data ) ? $data : [] ];
		}

		return [ 'ok' => true, 'reason' => '', 'data' => is_array( $data ) ? $data : [] ];
	}

	/**
	 * The properties this account can see.
	 *
	 * @return array{ok:bool,reason:string,sites:array}
	 */
	public static function sites() {
		$result = self::request( '/sites' );

		if ( ! $result['ok'] ) {
			return [ 'ok' => false, 'reason' => $result['reason'], 'sites' => [] ];
		}

		$sites = [];

		foreach ( (array) ( $result['data']['siteEntry'] ?? [] ) as $entry ) {
			if ( empty( $entry['siteUrl'] ) ) {
				continue;
			}

			// Properties the account can only view a restricted slice of are
			// not useful here and would fail later with a confusing error.
			if ( isset( $entry['permissionLevel'] ) && 'siteUnverifiedUser' === $entry['permissionLevel'] ) {
				continue;
			}

			$sites[] = (string) $entry['siteUrl'];
		}

		return [ 'ok' => true, 'reason' => '', 'sites' => $sites ];
	}

	/**
	 * Guess which property matches this site.
	 *
	 * @param array $sites
	 *
	 * @return string
	 */
	public static function match_property( array $sites ) {
		$home = untrailingslashit( home_url() );
		$host = (string) wp_parse_url( $home, PHP_URL_HOST );

		// An exact URL-prefix property first, then a domain property, then any
		// property on the same host — most specific wins, because a site in a
		// subdirectory must not match the root property.
		foreach ( [ $home . '/', $home ] as $candidate ) {
			if ( in_array( $candidate, $sites, true ) ) {
				return $candidate;
			}
		}

		if ( '' !== $host && in_array( 'sc-domain:' . $host, $sites, true ) ) {
			return 'sc-domain:' . $host;
		}

		foreach ( $sites as $site ) {
			if ( '' !== $host && false !== strpos( $site, $host ) ) {
				return $site;
			}
		}

		return '';
	}

	/**
	 * @return string
	 */
	public static function property() {
		return self::tokens()['property'];
	}

	/**
	 * @param string $property
	 */
	public static function set_property( $property ) {
		$tokens             = self::tokens();
		$tokens['property'] = (string) $property;

		self::store_tokens( $tokens );
	}

	// -------------------------------------------------------------------------
	// The findings
	// -------------------------------------------------------------------------

	/**
	 * @param int $days
	 *
	 * @return array{start:string,end:string}
	 */
	public static function window( $days = 28 ) {
		$end = time() - ( self::LAG_DAYS * DAY_IN_SECONDS );

		return [
			'start' => gmdate( 'Y-m-d', $end - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) ),
			'end'   => gmdate( 'Y-m-d', $end ),
		];
	}

	/**
	 * Raw search-analytics query.
	 *
	 * @param array $body
	 *
	 * @return array
	 */
	public static function query( array $body ) {
		$property = self::property();

		if ( '' === $property ) {
			return [ 'ok' => false, 'reason' => 'noproperty', 'rows' => [] ];
		}

		$result = self::request(
			'/sites/' . rawurlencode( $property ) . '/searchAnalytics/query',
			$body
		);

		if ( ! $result['ok'] ) {
			return [ 'ok' => false, 'reason' => $result['reason'], 'rows' => [] ];
		}

		return [ 'ok' => true, 'reason' => '', 'rows' => (array) ( $result['data']['rows'] ?? [] ) ];
	}

	/**
	 * Pages earning impressions but few clicks.
	 *
	 * The most actionable thing Search Console says, and the one this extension
	 * is placed to act on: people are SEEING the result and not choosing it,
	 * which is a title-and-description problem far more often than a ranking
	 * one — and the title and description are editable right here.
	 *
	 * @param int $limit
	 *
	 * @return array
	 */
	public static function missed_clicks( $limit = 15 ) {
		$window = self::window( 28 );

		$result = self::query( [
			'startDate'  => $window['start'],
			'endDate'    => $window['end'],
			'dimensions' => [ 'page' ],
			'rowLimit'   => 200,
		] );

		if ( ! $result['ok'] ) {
			return $result;
		}

		$rows = [];

		foreach ( $result['rows'] as $row ) {
			$impressions = (int) ( $row['impressions'] ?? 0 );
			$clicks      = (int) ( $row['clicks'] ?? 0 );

			// Below this, click-through is noise: one click either way swings
			// the rate wildly and the page tells you nothing.
			if ( $impressions < 100 ) {
				continue;
			}

			$ctr = $impressions ? $clicks / $impressions : 0.0;

			$rows[] = [
				'page'        => (string) ( $row['keys'][0] ?? '' ),
				'impressions' => $impressions,
				'clicks'      => $clicks,
				'ctr'         => $ctr,
				'position'    => (float) ( $row['position'] ?? 0 ),
			];
		}

		// Worst click-through first, but only among pages with enough
		// impressions to be worth the effort of rewriting.
		usort( $rows, static function ( $a, $b ) {
			if ( $a['ctr'] === $b['ctr'] ) {
				return $b['impressions'] <=> $a['impressions'];
			}

			return $a['ctr'] <=> $b['ctr'];
		} );

		return [ 'ok' => true, 'reason' => '', 'rows' => array_slice( $rows, 0, max( 1, (int) $limit ) ) ];
	}

	/**
	 * Headline totals, and how they compare with the previous 28 days.
	 *
	 * A number on its own answers nothing: 4,000 impressions is good news or
	 * bad depending entirely on what it was last month.
	 *
	 * @return array
	 */
	public static function summary() {
		$now  = self::window( 28 );
		$then = [
			'start' => gmdate( 'Y-m-d', strtotime( $now['start'] ) - ( 28 * DAY_IN_SECONDS ) ),
			'end'   => $now['start'],
		];

		$totals = static function ( $window ) {
			$result = self::query( [
				'startDate' => $window['start'],
				'endDate'   => $window['end'],
				'rowLimit'  => 1,
			] );

			if ( ! $result['ok'] ) {
				return null;
			}

			$row = $result['rows'][0] ?? [];

			return [
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'position'    => (float) ( $row['position'] ?? 0 ),
			];
		};

		$current = $totals( $now );

		if ( null === $current ) {
			return [ 'ok' => false, 'reason' => 'error', 'current' => null, 'previous' => null ];
		}

		return [
			'ok'       => true,
			'reason'   => '',
			'current'  => $current,
			'previous' => $totals( $then ),
			'window'   => $now,
		];
	}

	// -------------------------------------------------------------------------
	// Admin
	// -------------------------------------------------------------------------

	const CACHE_KEY = 'fw_seo_console_report';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_init', [ __CLASS__, '_action_callback' ] );
		add_action( 'admin_post_fw_seo_console_connect', [ __CLASS__, '_action_connect' ] );
		add_action( 'admin_post_fw_seo_console_disconnect', [ __CLASS__, '_action_disconnect' ] );
		add_action( 'admin_post_fw_seo_console_refresh', [ __CLASS__, '_action_refresh' ] );
	}

	/**
	 * @internal
	 */
	public static function _action_connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'fw' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'fw_seo_console_connect' );

		$url = self::auth_url();

		if ( '' === $url ) {
			wp_safe_redirect( self::back( 'nocreds' ) );
			exit;
		}

		// Off-site, so wp_safe_redirect would refuse it — this is the one
		// redirect here that genuinely must leave the site.
		wp_redirect( $url );
		exit;
	}

	/**
	 * @internal
	 */
	public static function _action_callback() {
		if ( ! isset( $_GET['fw-seo-console'] ) || 'callback' !== $_GET['fw-seo-console'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['error'] ) ) {
			wp_safe_redirect( self::back( 'denied' ) );
			exit;
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		if ( ! self::verify_state( $state ) ) {
			wp_safe_redirect( self::back( 'badstate' ) );
			exit;
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

		if ( '' === $code ) {
			wp_safe_redirect( self::back( 'nocode' ) );
			exit;
		}

		$result = self::exchange_code( $code );

		if ( ! $result['ok'] ) {
			wp_safe_redirect( self::back( $result['reason'] ) );
			exit;
		}

		// Pick the matching property straight away, so a successful connection
		// is immediately useful rather than asking another question.
		$sites = self::sites();

		if ( $sites['ok'] ) {
			$match = self::match_property( $sites['sites'] );

			if ( '' !== $match ) {
				self::set_property( $match );
			}
		}

		delete_transient( self::CACHE_KEY );

		wp_safe_redirect( self::back( 'connected' ) );
		exit;
	}

	/**
	 * @internal
	 */
	public static function _action_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'fw' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'fw_seo_console_disconnect' );

		self::disconnect();
		delete_transient( self::CACHE_KEY );

		wp_safe_redirect( self::back( 'disconnected' ) );
		exit;
	}

	/**
	 * @internal
	 */
	public static function _action_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'fw' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'fw_seo_console_refresh' );

		delete_transient( self::CACHE_KEY );
		self::report();

		wp_safe_redirect( self::back() );
		exit;
	}

	/**
	 * @param string $notice
	 *
	 * @return string
	 */
	protected static function back( $notice = '' ) {
		$url = FW_SEO_Settings_Page::url( 'console_tab' );

		if ( '' !== $notice ) {
			$url = add_query_arg( 'fw-seo-console-notice', $notice, $url );
		}

		return $url;
	}

	/**
	 * The cached report, fetched if there is none.
	 *
	 * @return array
	 */
	public static function report() {
		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$report = [
			'summary' => self::summary(),
			'missed'  => self::missed_clicks( 15 ),
			'checked' => time(),
		];

		set_transient( self::CACHE_KEY, $report, self::CACHE_TTL );

		return $report;
	}

	/**
	 * @return string
	 */
	public static function report_html() {
		$notice = isset( $_GET['fw-seo-console-notice'] ) ? sanitize_key( wp_unslash( $_GET['fw-seo-console-notice'] ) ) : '';

		ob_start();
		?>
		<div class="fw-seo-console">
			<?php echo self::notice_html( $notice ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<?php if ( ! self::has_credentials() ) : ?>
				<?php echo self::setup_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php elseif ( ! self::connected() ) : ?>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_console_connect' ), 'fw_seo_console_connect' ) ); ?>">
						<?php esc_html_e( 'Connect to Search Console', 'fw' ); ?>
					</a>
				</p>
				<p class="description">
					<?php esc_html_e( 'Read-only access to your own property. Google will warn that the app is unverified — it is yours, so choose Advanced and continue.', 'fw' ); ?>
				</p>
				<details class="fw-seo-details">
					<summary><?php esc_html_e( 'Setup steps again', 'fw' ); ?></summary>
					<?php echo self::setup_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</details>
			<?php else : ?>
				<?php echo self::connected_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php endif; ?>
		</div>
		<style>
		.fw-seo-console table{border-collapse:collapse;width:100%;max-width:52em;margin:.8em 0}
		.fw-seo-console th,.fw-seo-console td{text-align:left;padding:6px 10px;border-bottom:1px solid #e6e6ea;font-size:13px}
		.fw-seo-console th{font-weight:600;color:#50575e}
		.fw-seo-console td.num,.fw-seo-console th.num{text-align:right;width:7em;color:#50575e;white-space:nowrap}
		.fw-seo-console .totals{display:flex;gap:2em;flex-wrap:wrap;margin:.4em 0 1em}
		.fw-seo-console .totals div{min-width:8em}
		.fw-seo-console .totals strong{display:block;font-size:20px;line-height:1.2}
		.fw-seo-console .totals span{font-size:12px;color:#50575e}
		.fw-seo-console .up{color:#1a7f47}.fw-seo-console .down{color:#b32d2e}
		.fw-seo-console .fw-seo-details{margin:.6em 0 0}
		.fw-seo-console .fw-seo-details summary{cursor:pointer;color:#2271b1;font-size:13px}
		.fw-seo-console .prop{font-size:12px;color:#50575e;margin:.2em 0 1em}
		</style>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * @param string $notice
	 *
	 * @return string
	 */
	protected static function notice_html( $notice ) {
		if ( '' === $notice ) {
			return '';
		}

		$map = [
			'connected'    => [ 'success', __( 'Connected to Search Console.', 'fw' ) ],
			'disconnected' => [ 'info', __( 'Disconnected. The stored tokens have been deleted from this site.', 'fw' ) ],
			'denied'       => [ 'warning', __( 'Access was not granted, so nothing has changed.', 'fw' ) ],
			'badstate'     => [ 'warning', __( 'That sign-in could not be verified and was refused. Start again from this page rather than from a bookmarked link.', 'fw' ) ],
			'nocode'       => [ 'warning', __( 'Google did not return an authorisation code. Try connecting again.', 'fw' ) ],
			'revoked'      => [ 'warning', __( 'Google rejected the request. The access may have been revoked — connect again.', 'fw' ) ],
			'rejected'     => [ 'error', __( 'Google refused the credentials. Check the client ID, the secret, and that the redirect URI is registered exactly as shown.', 'fw' ) ],
			'network'      => [ 'warning', __( 'Could not reach Google. Usually temporary — try again.', 'fw' ) ],
			'nocreds'      => [ 'error', __( 'Add a client ID and secret first.', 'fw' ) ],
		];

		if ( ! isset( $map[ $notice ] ) ) {
			return '';
		}

		return '<div class="notice notice-' . esc_attr( $map[ $notice ][0] ) . ' inline" style="margin:0 0 1em"><p>'
			. esc_html( $map[ $notice ][1] ) . '</p></div>';
	}

	/**
	 * @return string
	 */
	protected static function connected_html() {
		$report   = self::report();
		$summary  = $report['summary'] ?? [];
		$missed   = $report['missed'] ?? [];
		$property = self::property();

		$disconnect = wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_console_disconnect' ), 'fw_seo_console_disconnect' );
		$refresh    = wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_console_refresh' ), 'fw_seo_console_refresh' );

		ob_start();

		if ( '' === $property ) {
			?>
			<div class="notice notice-warning inline" style="margin:0 0 1em"><p>
				<?php esc_html_e( 'Connected, but no Search Console property matches this site\'s address. Check the property exists and that the account you connected can see it.', 'fw' ); ?>
			</p></div>
			<?php
		} elseif ( empty( $summary['ok'] ) ) {
			$reason = $summary['reason'] ?? 'error';
			?>
			<div class="notice notice-warning inline" style="margin:0 0 1em"><p>
				<?php
				if ( 'noaccess' === $reason ) {
					esc_html_e( 'The connected account cannot read this property. That is a permissions problem in Search Console, not a problem with the connection.', 'fw' );
				} elseif ( 'ratelimited' === $reason ) {
					esc_html_e( 'Google is rate limiting these requests. Readings are cached for six hours; try again later.', 'fw' );
				} elseif ( 'revoked' === $reason ) {
					if ( self::looks_like_testing_expiry() ) {
						esc_html_e( 'Access stopped working about a week after connecting, which is the signature of an OAuth app left on "Testing" — Google cancels those after seven days. Open the OAuth consent screen in Google Cloud, press Publish app, then connect again. It will not expire after that.', 'fw' );
					} else {
						esc_html_e( 'Access has been revoked. Connect again to restore it.', 'fw' );
					}
				} else {
					esc_html_e( 'Google returned an unexpected response. Try again shortly.', 'fw' );
				}
				?>
			</p></div>
			<?php
		} else {
			$cur  = $summary['current'];
			$prev = $summary['previous'];
			?>
			<p class="prop"><?php
				printf(
					/* translators: 1: property, 2: start date, 3: end date. */
					esc_html__( '%1$s · %2$s to %3$s', 'fw' ),
					'<strong>' . esc_html( $property ) . '</strong>',
					esc_html( $summary['window']['start'] ),
					esc_html( $summary['window']['end'] )
				);
			?></p>

			<div class="totals">
				<?php foreach ( [
					'clicks'      => __( 'Clicks', 'fw' ),
					'impressions' => __( 'Impressions', 'fw' ),
				] as $key => $label ) :
					$now_v  = (int) $cur[ $key ];
					$then_v = is_array( $prev ) ? (int) $prev[ $key ] : null;
					$delta  = ( null !== $then_v && $then_v > 0 ) ? ( ( $now_v - $then_v ) / $then_v ) * 100 : null;
					?>
					<div>
						<strong><?php echo esc_html( number_format_i18n( $now_v ) ); ?></strong>
						<span>
							<?php echo esc_html( $label ); ?>
							<?php if ( null !== $delta ) : ?>
								<em class="<?php echo $delta >= 0 ? 'up' : 'down'; ?>">
									<?php echo esc_html( ( $delta >= 0 ? '+' : '' ) . number_format_i18n( $delta, 0 ) . '%' ); ?>
								</em>
							<?php endif; ?>
						</span>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( ! empty( $missed['ok'] ) && $missed['rows'] ) : ?>
				<h4 style="margin:1.4em 0 .2em;font-size:13px"><?php esc_html_e( 'Seen but not clicked', 'fw' ); ?></h4>
				<p class="description">
					<?php esc_html_e( 'Pages with plenty of impressions and few clicks. People are seeing the result and choosing something else — far more often a title and description problem than a ranking one, and both are editable here.', 'fw' ); ?>
				</p>
				<table>
					<thead>
						<tr>
							<th><?php esc_html_e( 'Page', 'fw' ); ?></th>
							<th class="num"><?php esc_html_e( 'Impressions', 'fw' ); ?></th>
							<th class="num"><?php esc_html_e( 'Clicks', 'fw' ); ?></th>
							<th class="num"><?php esc_html_e( 'Click-through', 'fw' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $missed['rows'] as $row ) :
							$id = url_to_postid( $row['page'] );
							?>
							<tr>
								<td>
									<?php if ( $id ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ?: $row['page'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $row['page'] ); ?>
									<?php endif; ?>
								</td>
								<td class="num"><?php echo esc_html( number_format_i18n( $row['impressions'] ) ); ?></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $row['clicks'] ) ); ?></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $row['ctr'] * 100, 1 ) . '%' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Average position is deliberately not shown as a ranking. Search Console averages it across everyone who saw the result, which varies by location, device and personalisation — it is a trend, not your position.', 'fw' ); ?>
			</p>
			<?php
		}
		?>
		<p>
			<a class="button" href="<?php echo esc_url( $refresh ); ?>"><?php esc_html_e( 'Refresh', 'fw' ); ?></a>
			<a class="button" href="<?php echo esc_url( $disconnect ); ?>"><?php esc_html_e( 'Disconnect', 'fw' ); ?></a>
		</p>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * The one failure with a date-shaped signature.
	 *
	 * An OAuth app left in "Testing" has its refresh tokens killed by Google
	 * after exactly seven days — so a connection made a week ago stops working
	 * for a reason that has nothing to do with this site, and "connect again"
	 * would send someone round the same loop next week. Worth naming.
	 *
	 * @return bool
	 */
	public static function looks_like_testing_expiry() {
		$connected = self::tokens()['connected'];

		if ( ! $connected ) {
			return false;
		}

		$days = ( time() - $connected ) / DAY_IN_SECONDS;

		return $days >= 6 && $days <= 10;
	}

	/**
	 * The setup walkthrough.
	 *
	 * Written out in full because the alternative is a user alt-tabbing to a
	 * search engine at step two. The order matters: the consent screen has to
	 * be published BEFORE the client is used in anger, or the first connection
	 * quietly expires a week later.
	 *
	 * @return string
	 */
	public static function setup_html() {
		$uri = self::redirect_uri();

		ob_start();
		?>
		<div class="fw-seo-setup">
			<p class="description" style="margin-bottom:1em">
				<?php esc_html_e( 'A one-time setup of about five minutes. You are creating your own Google credentials, so your data goes straight from Google to this site — there is no account with us and nothing passes through our servers.', 'fw' ); ?>
			</p>

			<ol class="fw-seo-steps">
				<li>
					<?php
					printf(
						wp_kses( __( 'Open the <a href="%s" target="_blank" rel="noopener">Google Cloud Console</a> and create a project (any name).', 'fw' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ),
						'https://console.cloud.google.com/projectcreate'
					);
					?>
				</li>
				<li>
					<?php
					printf(
						wp_kses( __( 'Enable the <a href="%s" target="_blank" rel="noopener">Search Console API</a> for that project.', 'fw' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ),
						'https://console.cloud.google.com/apis/library/searchconsole.googleapis.com'
					);
					?>
				</li>
				<li>
					<?php
					printf(
						wp_kses( __( 'On the <a href="%s" target="_blank" rel="noopener">OAuth consent screen</a>, choose <strong>External</strong>, give it any name and your email — then <strong>Publish app</strong>.', 'fw' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ], 'strong' => [] ] ),
						'https://console.cloud.google.com/apis/credentials/consent'
					);
					?>
					<p class="fw-seo-why">
						<?php esc_html_e( 'Do not leave it on "Testing". Google cancels access from a testing app after exactly seven days, and the connection here would stop working a week later for a reason that has nothing to do with your site.', 'fw' ); ?>
					</p>
				</li>
				<li>
					<?php
					printf(
						wp_kses( __( 'Under <a href="%s" target="_blank" rel="noopener">Credentials</a>, create an <strong>OAuth client ID</strong> of type <strong>Web application</strong>.', 'fw' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ], 'strong' => [] ] ),
						'https://console.cloud.google.com/apis/credentials'
					);
					?>
				</li>
				<li>
					<?php esc_html_e( 'Add this as an Authorised redirect URI — it must match exactly, including http or https:', 'fw' ); ?>
					<span class="fw-seo-copy-row">
						<code id="fw-seo-redirect-uri"><?php echo esc_html( $uri ); ?></code>
						<button type="button" class="button button-small fw-seo-copy" data-target="fw-seo-redirect-uri"><?php esc_html_e( 'Copy', 'fw' ); ?></button>
					</span>
				</li>
				<li><?php esc_html_e( 'Paste the client ID and secret into the two fields above, and press Save Changes.', 'fw' ); ?></li>
				<li>
					<?php esc_html_e( 'Press Connect, and choose the Google account that can see this site in Search Console.', 'fw' ); ?>
					<p class="fw-seo-why">
						<?php esc_html_e( 'Google will warn that the app is not verified. That is expected: the app is yours, made minutes ago, and used only by you. Choose Advanced, then continue.', 'fw' ); ?>
					</p>
				</li>
			</ol>
		</div>
		<style>
		.fw-seo-setup .fw-seo-steps{margin:0 0 1em 1.3em;max-width:46em}
		.fw-seo-setup .fw-seo-steps li{margin:0 0 .7em;padding-left:.2em;line-height:1.6}
		.fw-seo-setup .fw-seo-why{margin:.35em 0 0;padding:.5em .7em;background:#f6f7f7;border-left:3px solid #c3c4c7;font-size:12px;color:#50575e}
		.fw-seo-copy-row{display:flex;align-items:center;gap:.5em;margin-top:.4em;flex-wrap:wrap}
		.fw-seo-copy-row code{padding:4px 8px;background:#f0f0f1;border-radius:3px;font-size:12px;word-break:break-all}
		</style>
		<script>
		( function () {
			document.querySelectorAll( '.fw-seo-copy' ).forEach( function ( b ) {
				b.addEventListener( 'click', function () {
					var el = document.getElementById( b.getAttribute( 'data-target' ) );
					if ( ! el ) { return; }
					var done = function () { var t = b.textContent; b.textContent = '<?php echo esc_js( __( 'Copied', 'fw' ) ); ?>'; setTimeout( function () { b.textContent = t; }, 1600 ); };
					if ( navigator.clipboard ) { navigator.clipboard.writeText( el.textContent ).then( done, function(){} ); return; }
					// Older browsers, and any page not served over https, where
					// the clipboard API is unavailable: select it so one
					// keystroke copies rather than leaving the button dead.
					var r = document.createRange(); r.selectNodeContents( el );
					var s = window.getSelection(); s.removeAllRanges(); s.addRange( r );
					try { document.execCommand( 'copy' ); done(); } catch ( e ) {}
				} );
			} );
		}() );
		</script>
		<?php

		return (string) ob_get_clean();
	}
}
