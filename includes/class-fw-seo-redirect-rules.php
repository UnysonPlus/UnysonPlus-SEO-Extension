<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Redirect manager and 404 log.
 *
 * Manual rules cover what the automatic redirects (FW_SEO_Redirects) cannot:
 * content that moved before the site did, addresses from an old platform,
 * campaign links that should point somewhere new, content removed for good
 * (410 Gone). The 404 log is the evidence to make them from: every address that
 * came back "not found", with a button that turns it into a redirect.
 *
 * Rules only act on a request that would otherwise be a 404, like the automatic
 * redirects: content always wins, and an ordinary page view costs nothing.
 * Order on a 404: manual rules (priority 19), automatic redirects (20), then the
 * log (99) records whatever is still a 404.
 *
 * Every rule set can be exported to CSV (for people) or JSON and imported back
 * unchanged.
 *
 * Nothing reads settings during startup (the option-types trap).
 *
 * @since 2.0.44
 */
class FW_SEO_Redirect_Rules {

	const DB_VERSION        = 1;
	const DB_VERSION_OPTION = 'fw_seo_redirect_rules_db';
	const CRON_PRUNE        = 'fw_seo_404_prune';
	const KEEP_DAYS         = 90;
	const KEEP_ROWS         = 2000;
	const CODES             = [ 301, 302, 307, 410 ];
	const NONCE             = 'fw_seo_rules';
	const NOTICE            = 'fw_seo_rules_notice_';

	/** Swapped for the screens when the settings page is drawn. */
	const PLACEHOLDER     = '<!--fw-seo-redirect-rules-->';
	const PLACEHOLDER_404 = '<!--fw-seo-404-log-->';

	public static function rules_table() {
		global $wpdb;

		return $wpdb->prefix . 'fw_seo_redirect_rules';
	}

	public static function log_table() {
		global $wpdb;

		return $wpdb->prefix . 'fw_seo_404';
	}

	/**
	 * A copy of every rule, kept in an option so an AI change to the rules can be
	 * undone: the AI Assistant's undo restores options, and restoring this one
	 * rebuilds the table from it (see mirror() and _restore_from_mirror()).
	 */
	const MIRROR = 'fw_seo_redirect_rules_undo';

	/** @var bool Set while the mirror is being written, so that write does not rebuild the table. */
	protected static $mirroring = false;

	/** Copy the current rules into the mirror option. */
	public static function mirror() {
		self::$mirroring = true;
		update_option( self::MIRROR, array_map( static function ( $r ) {
			return [ 'source' => $r['source'], 'target' => $r['target'], 'code' => $r['code'], 'is_regex' => $r['is_regex'], 'grp' => $r['grp'] ];
		}, self::all() ), false );
		self::$mirroring = false;
	}

	/** @internal The mirror was restored (an undo): make the table match it. */
	public static function _restore_from_mirror( $old, $new ) {
		global $wpdb;

		if ( self::$mirroring || ! self::installed() || ! is_array( $new ) ) {
			return;
		}

		$wpdb->query( 'TRUNCATE TABLE ' . self::rules_table() ); // phpcs:ignore WordPress.DB
		foreach ( $new as $rule ) {
			self::save( (array) $rule );
		}
	}

	public static function init() {
		add_action( 'update_option_' . self::MIRROR, [ __CLASS__, '_restore_from_mirror' ], 10, 2 );
		add_action( 'template_redirect', [ __CLASS__, '_maybe_redirect' ], 19 );
		add_action( 'template_redirect', [ __CLASS__, '_maybe_log' ], 99 );
		add_action( self::CRON_PRUNE, [ __CLASS__, 'prune' ] );
		add_action( 'admin_init', [ __CLASS__, '_schedule' ] );

		foreach ( [ 'save', 'delete', 'export', 'import', 'clear_404', 'forget_404' ] as $action ) {
			add_action( 'admin_post_fw_seo_rules_' . $action, [ __CLASS__, '_action_' . $action ] );
		}
	}

	/** @internal */
	public static function _schedule() {
		if ( ! wp_next_scheduled( self::CRON_PRUNE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_PRUNE );
		}
	}

	public static function install() {
		global $wpdb;

		if ( (int) get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$rules   = self::rules_table();
		$log     = self::log_table();

		dbDelta( "CREATE TABLE {$rules} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(1024) NOT NULL DEFAULT '',
			source_key char(32) NOT NULL DEFAULT '',
			is_regex tinyint(1) NOT NULL DEFAULT 0,
			target text NOT NULL,
			code smallint(5) unsigned NOT NULL DEFAULT 301,
			grp varchar(100) NOT NULL DEFAULT '',
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			last_hit bigint(20) unsigned NOT NULL DEFAULT 0,
			created bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY source_key (source_key),
			KEY is_regex (is_regex)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			path_key char(32) NOT NULL DEFAULT '',
			path varchar(1024) NOT NULL DEFAULT '',
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			first_seen bigint(20) unsigned NOT NULL DEFAULT 0,
			last_seen bigint(20) unsigned NOT NULL DEFAULT 0,
			referrer varchar(1024) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY path_key (path_key),
			KEY last_seen (last_seen)
		) {$charset};" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/** @return bool */
	protected static function installed() {
		return (int) get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION;
	}

	/* ------------------------------------------------------------------ *
	 * Matching — pure
	 * ------------------------------------------------------------------ */

	/**
	 * The comparable form of an address: its path (and query, when it has one),
	 * decoded, lower-case, without a trailing slash, host dropped.
	 *
	 * "https://old.example/About-Us/?page=2" → "/about-us?page=2".
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	public static function key_of( $url ) {
		$url   = trim( (string) $url );
		$path  = FW_SEO_Redirects::path( $url );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		if ( '' === $query ) {
			return $path;
		}

		parse_str( strtolower( $query ), $args );
		ksort( $args );

		return $path . '?' . http_build_query( $args );
	}

	/**
	 * A request as the site sees it: for a site in a sub-folder (/shop/), the
	 * request "/shop/old-page/?a=1" is "/old-page/?a=1", which is what rules and
	 * the log are written in.
	 *
	 * @param string $request_uri
	 * @param string $base The home path without a trailing slash; '' at the root.
	 *
	 * @return string
	 */
	public static function relative( $request_uri, $base = null ) {
		$base = null === $base ? rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' ) : rtrim( (string) $base, '/' );
		$uri  = (string) $request_uri;

		if ( '' !== $base && 0 === stripos( $uri, $base . '/' ) ) {
			return substr( $uri, strlen( $base ) );
		}

		if ( '' !== $base && 0 === strcasecmp( strtok( $uri, '?' ), $base ) ) {
			return '/' . substr( $uri, strlen( $base ) );
		}

		return $uri;
	}

	/**
	 * Check a rule before it is stored.
	 *
	 * @param array $rule {source, target, code, is_regex, grp}
	 *
	 * @return array|WP_Error The cleaned rule.
	 */
	public static function validate( array $rule ) {
		$source = trim( (string) ( $rule['source'] ?? '' ) );
		$target = trim( (string) ( $rule['target'] ?? '' ) );
		$code   = (int) ( $rule['code'] ?? 301 );
		$regex  = ! empty( $rule['is_regex'] ) && ! in_array( $rule['is_regex'], [ '0', 'no', 'false' ], true );

		if ( '' === $source ) {
			return new WP_Error( 'source', __( 'The old address is empty.', 'fw' ) );
		}

		if ( ! in_array( $code, self::CODES, true ) ) {
			return new WP_Error( 'code', __( 'Use 301, 302, 307 or 410.', 'fw' ) );
		}

		if ( 410 !== $code && '' === $target ) {
			return new WP_Error( 'target', __( 'Say where it should go, or choose 410 Gone.', 'fw' ) );
		}

		if ( $regex && false === @preg_match( self::delimit( $source ), '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'regex', __( 'The regular expression is not valid.', 'fw' ) );
		}

		if ( ! $regex && '/' === self::key_of( $source ) ) {
			return new WP_Error( 'home', __( 'The home page cannot be redirected here.', 'fw' ) );
		}

		if ( preg_match( '#[\r\n]#', $source . $target ) ) {
			return new WP_Error( 'newline', __( 'An address cannot contain a line break.', 'fw' ) );
		}

		if ( ! $regex && 410 !== $code && self::key_of( $source ) === self::key_of( $target ) && ! preg_match( '#^https?://#i', $target ) ) {
			return new WP_Error( 'loop', __( 'The old and new address are the same page.', 'fw' ) );
		}

		return [
			'source'     => $source,
			'source_key' => $regex ? '' : md5( self::key_of( $source ) ),
			'is_regex'   => $regex ? 1 : 0,
			'target'     => 410 === $code ? '' : $target,
			'code'       => $code,
			'grp'        => substr( sanitize_text_field( (string) ( $rule['grp'] ?? '' ) ), 0, 100 ),
		];
	}

	/** @return string A pattern with delimiters, case-insensitive. */
	public static function delimit( $pattern ) {
		return '#' . str_replace( '#', '\#', (string) $pattern ) . '#i';
	}

	/**
	 * Where a regex rule sends a request: its target with $1… filled in.
	 *
	 * @param string $pattern
	 * @param string $target
	 * @param string $request Path (and query).
	 *
	 * @return string|null null when it does not match.
	 */
	public static function regex_target( $pattern, $target, $request ) {
		if ( ! @preg_match( self::delimit( $pattern ), $request, $m ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return null;
		}

		return preg_replace_callback( '#\$(\d+)#', static function ( $g ) use ( $m ) {
			return $m[ (int) $g[1] ] ?? '';
		}, $target );
	}

	/* ------------------------------------------------------------------ *
	 * Rules — storage
	 * ------------------------------------------------------------------ */

	/**
	 * Add a rule, or replace the one with the same old address (or the same id).
	 *
	 * @param array $rule
	 * @param int   $id
	 *
	 * @return int|WP_Error The rule id.
	 */
	public static function save( array $rule, $id = 0 ) {
		global $wpdb;

		self::install();
		$clean = self::validate( $rule );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		if ( ! $id ) {
			$id = (int) $wpdb->get_var( $clean['is_regex']
				? $wpdb->prepare( 'SELECT id FROM ' . self::rules_table() . ' WHERE is_regex = 1 AND source = %s', $clean['source'] ) // phpcs:ignore WordPress.DB
				: $wpdb->prepare( 'SELECT id FROM ' . self::rules_table() . ' WHERE source_key = %s', $clean['source_key'] ) ); // phpcs:ignore WordPress.DB
		}

		if ( $id ) {
			$wpdb->update( self::rules_table(), $clean, [ 'id' => (int) $id ] );
		} else {
			$wpdb->insert( self::rules_table(), $clean + [ 'created' => time() ] );
			$id = (int) $wpdb->insert_id;
		}

		// The 404 this rule answers is resolved; take it off the log.
		if ( ! $clean['is_regex'] && self::installed() ) {
			$wpdb->delete( self::log_table(), [ 'path_key' => $clean['source_key'] ] );
		}

		return (int) $id;
	}

	/** @param int $id */
	public static function delete( $id ) {
		global $wpdb;

		if ( self::installed() ) {
			$wpdb->delete( self::rules_table(), [ 'id' => (int) $id ] );
		}
	}

	/** @return array */
	public static function all() {
		global $wpdb;

		if ( ! self::installed() ) {
			return [];
		}

		return array_map( static function ( $r ) {
			return [
				'id'       => (int) $r->id,
				'source'   => (string) $r->source,
				'is_regex' => (int) $r->is_regex,
				'target'   => (string) $r->target,
				'code'     => (int) $r->code,
				'grp'      => (string) $r->grp,
				'hits'     => (int) $r->hits,
				'last_hit' => (int) $r->last_hit,
			];
		}, (array) $wpdb->get_results( 'SELECT * FROM ' . self::rules_table() . ' ORDER BY grp, source LIMIT 5000' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * The rule that answers a request, and where it sends it.
	 *
	 * @param string $request_uri Path and query, as requested.
	 *
	 * @return array|null {id, code, target}
	 */
	public static function match( $request_uri ) {
		global $wpdb;

		if ( ! self::installed() ) {
			return null;
		}

		$t     = self::rules_table();
		$full  = self::key_of( $request_uri );
		$path  = FW_SEO_Redirects::path( $request_uri );
		$keys  = array_unique( [ md5( $full ), md5( $path ) ] );
		$query = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );

		// Exact first, the address with its query before the bare path.
		foreach ( $keys as $i => $key ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, code, target FROM {$t} WHERE source_key = %s LIMIT 1", $key ) ); // phpcs:ignore WordPress.DB
			if ( $row ) {
				$target = (string) $row->target;
				// Matched on the path alone: a tracked link should still be tracked.
				if ( $i > 0 && '' !== $query && '' !== $target && false === strpos( $target, '?' ) ) {
					$target .= '?' . $query;
				}

				return [ 'id' => (int) $row->id, 'code' => (int) $row->code, 'target' => $target ];
			}
		}

		$raw = '/' . ltrim( rawurldecode( (string) wp_parse_url( $request_uri, PHP_URL_PATH ) ), '/' ) . ( '' !== $query ? '?' . $query : '' );
		foreach ( (array) $wpdb->get_results( "SELECT id, code, source, target FROM {$t} WHERE is_regex = 1 ORDER BY id" ) as $row ) { // phpcs:ignore WordPress.DB
			$target = self::regex_target( $row->source, (string) $row->target, $raw );
			if ( null !== $target ) {
				return [ 'id' => (int) $row->id, 'code' => (int) $row->code, 'target' => $target ];
			}
		}

		return null;
	}

	/** @internal */
	public static function _maybe_redirect() {
		if ( ! is_404() || ! self::installed() ) {
			return;
		}

		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$match = self::match( self::relative( $uri ) );

		if ( ! $match ) {
			return;
		}

		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::rules_table() . ' SET hits = hits + 1, last_hit = %d WHERE id = %d', time(), $match['id'] ) ); // phpcs:ignore WordPress.DB

		if ( 410 === $match['code'] ) {
			// Gone: say so, and let the theme's not-found page show.
			status_header( 410 );
			$GLOBALS['fw_seo_gone'] = true;

			return;
		}

		$target = $match['target'];
		if ( '/' === ( $target[0] ?? '' ) && '/' !== ( $target[1] ?? '' ) ) {
			$target = home_url( $target );
		}

		// Not wp_safe_redirect: a rule may send a whole section to another domain,
		// and only administrators can write rules.
		wp_redirect( $target, $match['code'], 'Unyson+ SEO' ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * The 404 log
	 * ------------------------------------------------------------------ */

	/**
	 * Is this request worth logging? Not bots, not scanner probes for files the
	 * site never had, not missing images and scripts (a theme problem, not a
	 * moved page).
	 *
	 * @param string $path
	 * @param string $agent
	 *
	 * @return bool
	 */
	public static function worth_logging( $path, $agent ) {
		$path = (string) $path;

		if ( '' === $path || preg_match( '#/\.|\.(php\d?|asp|aspx|jsp|cgi|env|ini|sql|bak|log|xml|txt|js|mjs|css|map|json|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot|mp4|webm|zip|gz)$#i', $path ) ) {
			return false;
		}

		if ( preg_match( '#^/(wp-admin|wp-includes|wp-content|xmlrpc|cgi-bin|vendor|node_modules)(/|$)#i', $path ) ) {
			return false;
		}

		if ( class_exists( 'FW_SEO_Search_Log' ) && FW_SEO_Search_Log::is_bot_agent( $agent ) ) {
			return false;
		}

		return true;
	}

	/** @internal */
	public static function _maybe_log() {
		if ( ! is_404() || ! empty( $GLOBALS['fw_seo_gone'] ) || ! FW_SEO_Settings::flag( 'log_404', true ) ) {
			return;
		}

		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ref   = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$uri = self::relative( $uri );

		if ( ! self::worth_logging( (string) wp_parse_url( $uri, PHP_URL_PATH ), $agent ) ) {
			return;
		}

		self::log( $uri, $ref );
	}

	/**
	 * Record one 404.
	 *
	 * @param string $uri
	 * @param string $referrer
	 */
	public static function log( $uri, $referrer = '' ) {
		global $wpdb;

		self::install();
		$key  = self::key_of( $uri );
		$now  = time();
		$path = substr( $key, 0, 1024 );

		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . self::log_table() . ' (path_key, path, hits, first_seen, last_seen, referrer) VALUES (%s, %s, 1, %d, %d, %s)
			 ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), referrer = IF(VALUES(referrer) <> \'\', VALUES(referrer), referrer)', // phpcs:ignore WordPress.DB
			md5( $key ),
			$path,
			$now,
			$now,
			substr( (string) $referrer, 0, 1024 )
		) );
	}

	/** Drop old rows, and keep the table bounded. */
	public static function prune() {
		global $wpdb;

		if ( ! self::installed() ) {
			return;
		}

		$t = self::log_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE last_seen < %d", time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB
		if ( $count > self::KEEP_ROWS ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} ORDER BY hits ASC, last_seen ASC LIMIT %d", $count - self::KEEP_ROWS ) ); // phpcs:ignore WordPress.DB
		}
	}

	/** @return array */
	public static function log_rows( $limit = 200 ) {
		global $wpdb;

		if ( ! self::installed() ) {
			return [];
		}

		return array_map( static function ( $r ) {
			return [ 'path' => (string) $r->path, 'hits' => (int) $r->hits, 'first' => (int) $r->first_seen, 'last' => (int) $r->last_seen, 'referrer' => (string) $r->referrer ];
		}, (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::log_table() . ' ORDER BY hits DESC, last_seen DESC LIMIT %d', max( 1, (int) $limit ) ) ) ); // phpcs:ignore WordPress.DB
	}

	/* ------------------------------------------------------------------ *
	 * Import / Export
	 * ------------------------------------------------------------------ */

	const COLUMNS = [ 'source', 'target', 'code', 'regex', 'group' ];

	/**
	 * Rules as CSV rows, header first.
	 *
	 * @param array $rules
	 *
	 * @return string
	 */
	public static function to_csv( array $rules ) {
		$h = fopen( 'php://temp', 'r+' );
		fputcsv( $h, self::COLUMNS );
		foreach ( $rules as $r ) {
			fputcsv( $h, [ $r['source'], $r['target'], $r['code'], $r['is_regex'] ? 'yes' : 'no', $r['grp'] ] );
		}
		rewind( $h );
		$csv = stream_get_contents( $h );
		fclose( $h );

		return (string) $csv;
	}

	/**
	 * Read rules from CSV or JSON text.
	 *
	 * CSV columns by header name, in any order; without a header, the first two
	 * columns are old and new address (what most exports from other tools give).
	 *
	 * @param string $text
	 *
	 * @return array[] Rules for save().
	 */
	public static function parse_import( $text ) {
		$text = (string) $text;
		$text = preg_replace( '#^\xEF\xBB\xBF#', '', $text );

		if ( '' !== trim( $text ) && in_array( ltrim( $text )[0], [ '{', '[' ], true ) ) {
			$data  = json_decode( $text, true );
			$rules = is_array( $data ) ? ( isset( $data['rules'] ) ? (array) $data['rules'] : $data ) : [];

			return array_values( array_filter( array_map( static function ( $r ) {
				return is_array( $r ) ? [
					'source'   => (string) ( $r['source'] ?? '' ),
					'target'   => (string) ( $r['target'] ?? '' ),
					'code'     => (int) ( $r['code'] ?? 301 ),
					'is_regex' => ! empty( $r['regex'] ?? $r['is_regex'] ?? false ),
					'grp'      => (string) ( $r['group'] ?? $r['grp'] ?? '' ),
				] : null;
			}, $rules ) ) );
		}

		$lines = array_values( array_filter( preg_split( "#\r\n|\n|\r#", $text ), static function ( $l ) {
			return '' !== trim( $l );
		} ) );

		if ( ! $lines ) {
			return [];
		}

		$first  = array_map( static function ( $c ) {
			return strtolower( trim( $c ) );
		}, str_getcsv( $lines[0] ) );
		$header = in_array( 'source', $first, true ) || in_array( 'target', $first, true );
		$map    = $header ? array_flip( $first ) : [ 'source' => 0, 'target' => 1, 'code' => 2, 'regex' => 3, 'group' => 4 ];
		$out    = [];

		foreach ( array_slice( $lines, $header ? 1 : 0 ) as $line ) {
			$c     = str_getcsv( $line );
			$get   = static function ( $name, $default = '' ) use ( $c, $map ) {
				return isset( $map[ $name ], $c[ $map[ $name ] ] ) ? trim( $c[ $map[ $name ] ] ) : $default;
			};
			$out[] = [
				'source'   => $get( 'source' ),
				'target'   => $get( 'target' ),
				'code'     => (int) ( $get( 'code', '301' ) ?: 301 ),
				'is_regex' => in_array( strtolower( $get( 'regex', 'no' ) ), [ 'yes', '1', 'true' ], true ),
				'grp'      => $get( 'group' ),
			];
		}

		return $out;
	}

	/**
	 * Import rules; a rule for an old address that already has one replaces it.
	 *
	 * @param array[] $rules
	 *
	 * @return array{saved: int, skipped: array}
	 */
	public static function import( array $rules ) {
		$saved   = 0;
		$skipped = [];

		foreach ( $rules as $i => $rule ) {
			$result = self::save( $rule );
			if ( is_wp_error( $result ) ) {
				$skipped[] = sprintf( '%d: %s — %s', $i + 1, (string) ( $rule['source'] ?? '' ), $result->get_error_message() );
			} else {
				$saved ++;
			}
		}

		return [ 'saved' => $saved, 'skipped' => $skipped ];
	}

	/* ------------------------------------------------------------------ *
	 * Admin actions
	 * ------------------------------------------------------------------ */

	protected static function guard( $nonce_from_get = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'fw' ), 403 );
		}

		if ( $nonce_from_get ) {
			check_admin_referer( self::NONCE );
		} else {
			check_admin_referer( self::NONCE, 'fw_seo_rules_nonce' );
		}
	}

	protected static function back( $view, $message = '', $type = 'success', array $args = [] ) {
		if ( '' !== $message ) {
			set_transient( self::NOTICE . get_current_user_id(), [ 'message' => $message, 'type' => $type ], 60 );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=fw-seo-settings&view=' . $view ) ) );
		exit;
	}

	protected static function post( $key ) {
		return isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security
	}

	/** @internal */
	public static function _action_save() {
		self::guard();

		$result = self::save( [
			'source'   => self::post( 'fw_seo_rule_source' ),
			'target'   => self::post( 'fw_seo_rule_target' ),
			'code'     => (int) self::post( 'fw_seo_rule_code' ),
			'is_regex' => '' !== self::post( 'fw_seo_rule_regex' ),
			'grp'      => self::post( 'fw_seo_rule_group' ),
		], (int) self::post( 'fw_seo_rule_id' ) );

		if ( is_wp_error( $result ) ) {
			self::back( 'redirects_tab', $result->get_error_message(), 'error', [
				'fw_seo_src' => rawurlencode( self::post( 'fw_seo_rule_source' ) ),
			] );
		}

		self::back( 'redirects_tab', __( 'Redirect saved.', 'fw' ) );
	}

	/** @internal */
	public static function _action_delete() {
		self::guard( true );
		self::delete( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		self::back( 'redirects_tab', __( 'Redirect deleted.', 'fw' ) );
	}

	/** @internal */
	public static function _action_export() {
		self::guard( true );

		$rules  = self::all();
		$format = isset( $_GET['format'] ) && 'json' === $_GET['format'] ? 'json' : 'csv'; // phpcs:ignore WordPress.Security.NonceVerification
		$name   = 'redirects-' . sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . gmdate( 'Y-m-d' ) . '.' . $format;

		nocache_headers();
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );

		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( [
				'format'  => 'unysonplus-seo-redirects',
				'version' => 1,
				'rules'   => array_map( static function ( $r ) {
					return [ 'source' => $r['source'], 'target' => $r['target'], 'code' => $r['code'], 'regex' => (bool) $r['is_regex'], 'group' => $r['grp'] ];
				}, $rules ),
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} else {
			header( 'Content-Type: text/csv; charset=utf-8' );
			echo "\xEF\xBB\xBF" . self::to_csv( $rules ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		exit;
	}

	/** @internal */
	public static function _action_import() {
		self::guard();

		$file = $_FILES['fw_seo_rules_file'] ?? null; // phpcs:ignore WordPress.Security
		if ( ! $file || ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::back( 'redirects_tab', __( 'Choose a CSV or JSON file to import.', 'fw' ), 'error' );
		}

		$result = self::import( self::parse_import( (string) file_get_contents( $file['tmp_name'] ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$message = sprintf(
			/* translators: %d: number of redirects */
			_n( '%d redirect imported.', '%d redirects imported.', $result['saved'], 'fw' ),
			$result['saved']
		);
		if ( $result['skipped'] ) {
			$message .= ' ' . sprintf(
				/* translators: 1: number of rows, 2: the rows and why */
				_n( '%1$d row skipped: %2$s', '%1$d rows skipped: %2$s', count( $result['skipped'] ), 'fw' ),
				count( $result['skipped'] ),
				implode( '; ', array_slice( $result['skipped'], 0, 10 ) )
			);
		}

		self::back( 'redirects_tab', $message, $result['skipped'] ? 'warning' : 'success' );
	}

	/** @internal */
	public static function _action_clear_404() {
		global $wpdb;

		self::guard( true );
		if ( self::installed() ) {
			$wpdb->query( 'TRUNCATE TABLE ' . self::log_table() ); // phpcs:ignore WordPress.DB
		}
		self::back( 'notfound_tab', __( 'The 404 log is empty.', 'fw' ) );
	}

	/** @internal Ignore one logged address. */
	public static function _action_forget_404() {
		global $wpdb;

		self::guard( true );
		$path = isset( $_GET['path'] ) ? (string) wp_unslash( $_GET['path'] ) : ''; // phpcs:ignore WordPress.Security
		if ( self::installed() ) {
			$wpdb->delete( self::log_table(), [ 'path_key' => md5( self::key_of( $path ) ) ] );
		}
		self::back( 'notfound_tab' );
	}

	/* ------------------------------------------------------------------ *
	 * Screens
	 * ------------------------------------------------------------------ */

	protected static function notice_html() {
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) ) {
			return '';
		}

		delete_transient( $key );

		return '<div class="notice notice-' . esc_attr( $notice['type'] ) . ' inline"><p>' . esc_html( $notice['message'] ) . '</p></div>';
	}

	/**
	 * The manual rules on SEO → Redirects.
	 *
	 * The settings page is one form, so the controls here submit it to their own
	 * admin-post action with formaction; the handlers read only their own fields.
	 *
	 * @return string
	 */
	public static function rules_html() {
		$rules  = self::all();
		$prefil = isset( $_GET['fw_seo_src'] ) ? (string) wp_unslash( $_GET['fw_seo_src'] ) : ''; // phpcs:ignore WordPress.Security
		$post   = esc_url( admin_url( 'admin-post.php' ) );
		$url    = static function ( $action, array $args = [] ) {
			return wp_nonce_url( add_query_arg( array_merge( [ 'action' => 'fw_seo_rules_' . $action ], $args ), admin_url( 'admin-post.php' ) ), self::NONCE );
		};
		$groups = array_values( array_unique( array_filter( array_column( $rules, 'grp' ) ) ) );
		sort( $groups );

		$codes = [
			301 => __( '301 Moved permanently', 'fw' ),
			302 => __( '302 Found (temporary)', 'fw' ),
			307 => __( '307 Temporary redirect', 'fw' ),
			410 => __( '410 Gone (removed for good)', 'fw' ),
		];

		ob_start();
		echo self::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in notice_html().
		wp_nonce_field( self::NONCE, 'fw_seo_rules_nonce', false );
		?>
		<div class="fw-seo-rules">
			<h3 style="margin-top:0"><?php esc_html_e( 'Add a redirect', 'fw' ); ?></h3>
			<input type="hidden" name="fw_seo_rule_id" id="fw-seo-rule-id" value="0">
			<table class="form-table" role="presentation" style="max-width:960px;margin-top:0">
				<tr><th><label for="fw-seo-rule-source"><?php esc_html_e( 'Old address', 'fw' ); ?></label></th>
					<td><input type="text" class="regular-text code" style="width:100%" id="fw-seo-rule-source" name="fw_seo_rule_source" value="<?php echo esc_attr( $prefil ); ?>" placeholder="/old-page/  or  https://old-site.example/page.php?id=4">
					<p class="description"><?php esc_html_e( 'A path or a full address. Letter case and a trailing slash are ignored, and a query string (?id=4) is matched when you include one.', 'fw' ); ?></p>
					<label style="display:block;margin-top:6px"><input type="checkbox" name="fw_seo_rule_regex" id="fw-seo-rule-regex" value="1"> <?php esc_html_e( 'Regular expression', 'fw' ); ?></label>
					<p class="description"><?php esc_html_e( 'For many addresses at once: ^/blog/(\d+)/(.*)$ → /news/$2/. Matched against the path and query, letter case ignored.', 'fw' ); ?></p></td></tr>
				<tr><th><label for="fw-seo-rule-target"><?php esc_html_e( 'New address', 'fw' ); ?></label></th>
					<td><input type="text" class="regular-text code" style="width:100%" id="fw-seo-rule-target" name="fw_seo_rule_target" placeholder="/new-page/  or  https://…"></td></tr>
				<tr><th><label for="fw-seo-rule-code"><?php esc_html_e( 'Type', 'fw' ); ?></label></th>
					<td><select id="fw-seo-rule-code" name="fw_seo_rule_code"><?php foreach ( $codes as $c => $label ) : ?><option value="<?php echo (int) $c; ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
					<p class="description"><?php esc_html_e( 'Use 301 for a page that has moved, 410 for one removed on purpose with nothing to replace it: a clearer signal than redirecting it somewhere unrelated.', 'fw' ); ?></p></td></tr>
				<tr><th><label for="fw-seo-rule-group"><?php esc_html_e( 'Group', 'fw' ); ?></label></th>
					<td><input type="text" class="regular-text" id="fw-seo-rule-group" name="fw_seo_rule_group" list="fw-seo-rule-groups" placeholder="<?php esc_attr_e( 'Optional, e.g. Old shop', 'fw' ); ?>">
					<datalist id="fw-seo-rule-groups"><?php foreach ( $groups as $g ) : ?><option value="<?php echo esc_attr( $g ); ?>"><?php endforeach; ?></datalist></td></tr>
			</table>
			<p><button type="submit" class="button button-primary" formaction="<?php echo esc_attr( $post ); ?>?action=fw_seo_rules_save" formnovalidate><?php esc_html_e( 'Save redirect', 'fw' ); ?></button></p>

			<h3><?php echo esc_html( sprintf(
				/* translators: %d: number of redirects */
				_n( '%d redirect', '%d redirects', count( $rules ), 'fw' ),
				count( $rules )
			) ); ?></h3>
			<?php if ( $rules ) : ?>
				<p>
					<input type="search" id="fw-seo-rules-search" placeholder="<?php esc_attr_e( 'Search addresses…', 'fw' ); ?>" style="min-width:260px">
					<?php if ( $groups ) : ?>
						<select id="fw-seo-rules-group" style="width:auto;min-width:180px;display:inline-block"><option value=""><?php esc_html_e( 'All groups', 'fw' ); ?></option><?php foreach ( $groups as $g ) : ?><option value="<?php echo esc_attr( $g ); ?>"><?php echo esc_html( $g ); ?></option><?php endforeach; ?></select>
					<?php endif; ?>
				</p>
				<table class="widefat striped fw-seo-rules-table" style="max-width:1200px">
					<thead><tr><th><?php esc_html_e( 'Old address', 'fw' ); ?></th><th><?php esc_html_e( 'Goes to', 'fw' ); ?></th><th><?php esc_html_e( 'Type', 'fw' ); ?></th><th><?php esc_html_e( 'Group', 'fw' ); ?></th><th><?php esc_html_e( 'Used', 'fw' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $rules as $r ) : ?>
						<tr data-group="<?php echo esc_attr( $r['grp'] ); ?>" data-rule="<?php echo esc_attr( wp_json_encode( $r ) ); ?>">
							<td><code><?php echo esc_html( $r['source'] ); ?></code><?php if ( $r['is_regex'] ) : ?> <span class="description">(<?php esc_html_e( 'regex', 'fw' ); ?>)</span><?php endif; ?></td>
							<td><?php echo 410 === $r['code'] ? '<em>' . esc_html__( 'Gone', 'fw' ) . '</em>' : '<code>' . esc_html( $r['target'] ) . '</code>'; ?></td>
							<td><?php echo (int) $r['code']; ?></td>
							<td><?php echo esc_html( $r['grp'] ); ?></td>
							<td><?php echo $r['hits'] ? esc_html( sprintf(
								/* translators: 1: number of times, 2: how long ago */
								_n( '%1$s time, last %2$s ago', '%1$s times, last %2$s ago', $r['hits'], 'fw' ),
								number_format_i18n( $r['hits'] ),
								human_time_diff( $r['last_hit'] )
							) ) : '<span class="description">' . esc_html__( 'Not yet', 'fw' ) . '</span>'; ?></td>
							<td style="white-space:nowrap"><a href="#" class="fw-seo-rule-edit"><?php esc_html_e( 'Edit', 'fw' ); ?></a> · <a href="<?php echo esc_url( $url( 'delete', [ 'id' => $r['id'] ] ) ); ?>" class="fw-seo-rule-delete"><?php esc_html_e( 'Delete', 'fw' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( '"Used" counts how often each redirect carried a visitor, so you can tell which still matter. A redirect only acts on an address that would otherwise be "not found": a page published at that address always wins.', 'fw' ); ?></p>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Import / Export', 'fw' ); ?></h3>
			<p>
				<a class="button" href="<?php echo esc_url( $url( 'export', [ 'format' => 'csv' ] ) ); ?>"><?php esc_html_e( 'Export CSV', 'fw' ); ?></a>
				<a class="button" href="<?php echo esc_url( $url( 'export', [ 'format' => 'json' ] ) ); ?>"><?php esc_html_e( 'Export JSON', 'fw' ); ?></a>
			</p>
			<p>
				<input type="file" name="fw_seo_rules_file" accept=".csv,.json,text/csv,application/json">
				<button type="submit" class="button" formaction="<?php echo esc_attr( $post ); ?>?action=fw_seo_rules_import" formenctype="multipart/form-data" formnovalidate><?php esc_html_e( 'Import', 'fw' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'CSV columns: source, target, code, regex (yes/no), group. A file with only two columns, old and new address, works too, which is what most other redirect tools export. A redirect for an old address that already has one replaces it.', 'fw' ); ?></p>
		</div>
		<script>
		( function () {
			var root = document.querySelector( '.fw-seo-rules' ); if ( ! root ) { return; }
			var search = root.querySelector( '#fw-seo-rules-search' ), group = root.querySelector( '#fw-seo-rules-group' );
			function filter() {
				var q = search ? search.value.toLowerCase() : '', g = group ? group.value : '';
				root.querySelectorAll( '.fw-seo-rules-table tbody tr' ).forEach( function ( tr ) {
					tr.style.display = ( ! q || tr.textContent.toLowerCase().indexOf( q ) > -1 ) && ( ! g || tr.dataset.group === g ) ? '' : 'none';
				} );
			}
			if ( search ) { search.addEventListener( 'input', filter ); search.addEventListener( 'keydown', function ( e ) { if ( 'Enter' === e.key ) { e.preventDefault(); } } ); }
			if ( group ) { group.addEventListener( 'change', filter ); }
			root.querySelectorAll( '.fw-seo-rule-edit' ).forEach( function ( a ) {
				a.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					var r = JSON.parse( a.closest( 'tr' ).dataset.rule );
					root.querySelector( '#fw-seo-rule-id' ).value = r.id;
					root.querySelector( '#fw-seo-rule-source' ).value = r.source;
					root.querySelector( '#fw-seo-rule-target' ).value = r.target;
					root.querySelector( '#fw-seo-rule-code' ).value = r.code;
					root.querySelector( '#fw-seo-rule-group' ).value = r.grp;
					root.querySelector( '#fw-seo-rule-regex' ).checked = !! r.is_regex;
					root.querySelector( '#fw-seo-rule-source' ).scrollIntoView( { block: 'center' } );
					root.querySelector( '#fw-seo-rule-source' ).focus();
				} );
			} );
			root.querySelectorAll( '.fw-seo-rule-delete' ).forEach( function ( a ) {
				a.addEventListener( 'click', function ( e ) { if ( ! window.confirm( <?php echo wp_json_encode( __( 'Delete this redirect? Visitors to the old address will get "not found".', 'fw' ) ); ?> ) ) { e.preventDefault(); } } );
			} );
		} )();
		</script>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * SEO → 404s.
	 *
	 * @return string
	 */
	public static function log_html() {
		$rows  = self::log_rows( 200 );
		$clear = wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_rules_clear_404' ), self::NONCE );

		$html  = self::notice_html();
		$html .= '<p>' . esc_html( sprintf(
			/* translators: %d: days */
			__( 'Addresses visitors asked for that do not exist, most requested first. Kept for %d days. Search engines, scanner probes for files the site never had, and missing images or scripts are left out.', 'fw' ),
			self::KEEP_DAYS
		) ) . '</p>';

		if ( ! $rows ) {
			return $html . '<p><strong>' . esc_html__( 'Nothing logged yet.', 'fw' ) . '</strong></p>';
		}

		$html .= '<table class="widefat striped" style="max-width:1200px"><thead><tr><th>' . esc_html__( 'Address', 'fw' ) . '</th><th>' . esc_html__( 'Requests', 'fw' ) . '</th><th>' . esc_html__( 'Last', 'fw' ) . '</th><th>' . esc_html__( 'Came from', 'fw' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$make   = add_query_arg( [ 'page' => 'fw-seo-settings', 'view' => 'redirects_tab', 'fw_seo_src' => rawurlencode( $r['path'] ) ], admin_url( 'admin.php' ) );
			$forget = wp_nonce_url( add_query_arg( [ 'action' => 'fw_seo_rules_forget_404', 'path' => rawurlencode( $r['path'] ) ], admin_url( 'admin-post.php' ) ), self::NONCE );
			$ref    = '' !== $r['referrer'] ? '<a href="' . esc_url( $r['referrer'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) wp_parse_url( $r['referrer'], PHP_URL_HOST ) . (string) wp_parse_url( $r['referrer'], PHP_URL_PATH ) ) . '</a>' : '<span class="description">' . esc_html__( 'typed or unknown', 'fw' ) . '</span>';

			$html .= '<tr><td><code>' . esc_html( $r['path'] ) . '</code></td><td>' . esc_html( number_format_i18n( $r['hits'] ) ) . '</td><td>' . esc_html( sprintf( /* translators: %s: how long ago */ __( '%s ago', 'fw' ), human_time_diff( $r['last'] ) ) ) . '</td><td>' . $ref . '</td>'
				. '<td style="white-space:nowrap"><a class="button button-small" href="' . esc_url( $make ) . '">' . esc_html__( 'Redirect it', 'fw' ) . '</a> <a href="' . esc_url( $forget ) . '">' . esc_html__( 'Ignore', 'fw' ) . '</a></td></tr>';
		}

		$html .= '</tbody></table>';
		$html .= '<p><a class="button" href="' . esc_url( $clear ) . '" onclick="return window.confirm(' . esc_attr( wp_json_encode( __( 'Empty the 404 log?', 'fw' ) ) ) . ')">' . esc_html__( 'Empty the log', 'fw' ) . '</a></p>';

		return $html;
	}
}
