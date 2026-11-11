<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Backlinks and search volumes, from Bing Webmaster Tools.
 *
 * Who links to a site needs a crawl of the web, which no plugin can do. Bing's
 * Webmaster API gives that data for a verified site, free, with a key the site
 * owner creates. It covers what Bing has crawled, not the whole web, and the
 * screens say so.
 *
 * A run (weekly, or on demand) walks GetLinkCounts for the pages that have
 * links and GetUrlLinks for each, a few calls at a time through cron, and
 * records each link with when it was first and last seen: a link missing from a
 * finished run is "lost". Links to pages that no longer answer get a one-click
 * redirect. With an optional Open PageRank key, each referring site shows a
 * rough 0–10 score.
 *
 * Keyword data (GetKeyword, GetRelatedKeywords) is the same connection: real
 * Bing search counts, small next to Google but honest against each other.
 *
 * @since 2.0.55
 */
class FW_SEO_Bing {

	const API        = 'https://ssl.bing.com/webmaster/api.svc/json/';
	const OPR_API    = 'https://openpagerank.keywordseverywhere.com/v1/domains/bulk';
	const DB_VERSION = 1;
	const DB_OPTION  = 'fw_seo_backlinks_db';
	const STATE      = 'fw_seo_bing_state';
	const DEAD       = 'fw_seo_bing_dead';
	const AUTHORITY  = 'fw_seo_bing_authority';
	const VOLUMES    = 'fw_seo_bing_volumes';
	const LOOKUP     = 'fw_seo_bing_lookup_';
	const CRON_WEEK  = 'fw_seo_bing_weekly';
	const CRON_BATCH = 'fw_seo_bing_batch';
	const CALLS      = 20;
	const MAX_PAGES  = 20;
	const MAX_DETAIL = 5;
	const NONCE      = 'fw_seo_bing';

	/** Swapped for report_html() when the settings page is drawn. */
	const PLACEHOLDER = '<!--fw-seo-backlinks-report-->';

	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'fw_seo_backlinks';
	}

	public static function init() {
		add_action( self::CRON_WEEK, [ __CLASS__, 'start' ] );
		add_action( self::CRON_BATCH, [ __CLASS__, 'run_batch' ] );
		add_action( 'admin_init', [ __CLASS__, '_schedule' ] );
		foreach ( [ 'run', 'export', 'volumes' ] as $a ) {
			add_action( 'admin_post_fw_seo_bing_' . $a, [ __CLASS__, '_action_' . $a ] );
		}
	}

	/** @internal */
	public static function _schedule() {
		if ( '' !== self::key() && ! wp_next_scheduled( self::CRON_WEEK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON_WEEK );
		}
	}

	public static function key() {
		return trim( (string) FW_SEO_Settings::get( 'bing_api_key', '' ) );
	}

	public static function install() {
		global $wpdb;

		if ( (int) get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			link_key char(32) NOT NULL,
			source text NOT NULL,
			host varchar(191) NOT NULL DEFAULT '',
			target text NOT NULL,
			anchor varchar(255) NOT NULL DEFAULT '',
			first_seen bigint(20) unsigned NOT NULL DEFAULT 0,
			last_seen bigint(20) unsigned NOT NULL DEFAULT 0,
			lost_at bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY link_key (link_key),
			KEY host (host),
			KEY lost_at (lost_at)
		) {$wpdb->get_charset_collate()};" );

		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/* ------------------------------------------------------------------ *
	 * The APIs
	 * ------------------------------------------------------------------ */

	/**
	 * Call the Webmaster API.
	 *
	 * @return array{ok: bool, data: mixed, reason: string}
	 */
	public static function call( $method, array $args = [] ) {
		$url = add_query_arg( array_map( 'rawurlencode', $args + [ 'apikey' => self::key() ] ), self::API . $method );
		$r   = wp_remote_get( $url, [ 'timeout' => 20 ] );

		if ( is_wp_error( $r ) ) {
			return [ 'ok' => false, 'data' => null, 'reason' => $r->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $r );
		$body = json_decode( (string) wp_remote_retrieve_body( $r ), true );

		if ( 200 !== $code || ! is_array( $body ) ) {
			$msg = is_array( $body ) ? (string) ( $body['Message'] ?? '' ) : '';

			return [ 'ok' => false, 'data' => null, 'reason' => '' !== $msg ? $msg : sprintf( 'HTTP %d', $code ) ];
		}

		return [ 'ok' => true, 'data' => array_key_exists( 'd', $body ) ? $body['d'] : $body, 'reason' => '' ];
	}

	/** Lower-case host without www. */
	public static function host_of( $url ) {
		return preg_replace( '#^www\.#', '', strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) ) );
	}

	/**
	 * Scores for domains from Open PageRank, stored per host.
	 *
	 * @param string[] $hosts
	 */
	public static function refresh_authority( array $hosts ) {
		$key = trim( (string) FW_SEO_Settings::get( 'opr_api_key', '' ) );
		if ( '' === $key || ! $hosts ) {
			return;
		}

		$scores = (array) get_option( self::AUTHORITY, [] );
		foreach ( array_chunk( array_values( array_unique( $hosts ) ), 100 ) as $chunk ) {
			$r = wp_remote_post( self::OPR_API, [
				'timeout' => 20,
				'headers' => [ 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'domains' => $chunk, 'include_history' => false ] ),
			] );
			if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
				continue;
			}
			$scores = self::merge_scores( $scores, (array) json_decode( (string) wp_remote_retrieve_body( $r ), true ) );
		}
		update_option( self::AUTHORITY, $scores, false );
	}

	/** Pure: fold an Open PageRank response into host => score. */
	public static function merge_scores( array $scores, array $response ) {
		foreach ( (array) ( $response['results'] ?? [] ) as $row ) {
			if ( ! empty( $row['found'] ) && isset( $row['domain'], $row['open_page_rank'] ) ) {
				$scores[ strtolower( (string) $row['domain'] ) ] = (float) $row['open_page_rank'];
			}
		}

		return $scores;
	}

	/** The score for a host: its own, else its registered domain's. */
	public static function score_for( $host, array $scores ) {
		for ( $h = (string) $host; false !== strpos( $h, '.' ); $h = substr( $h, strpos( $h, '.' ) + 1 ) ) {
			if ( isset( $scores[ $h ] ) ) {
				return $scores[ $h ];
			}
		}

		return null;
	}

	/* ------------------------------------------------------------------ *
	 * The run
	 * ------------------------------------------------------------------ */

	public static function state() {
		$s = (array) get_option( self::STATE, [] );

		return [
			'status'    => (string) ( $s['status'] ?? 'new' ),
			'run'       => (int) ( $s['run'] ?? 0 ),
			'first_run' => (int) ( $s['first_run'] ?? 0 ),
			'page'      => (int) ( $s['page'] ?? 0 ),
			'targets'   => (array) ( $s['targets'] ?? [] ),
			'detail'    => (int) ( $s['detail'] ?? 0 ),
			'error'     => (string) ( $s['error'] ?? '' ),
			'time'      => (int) ( $s['time'] ?? 0 ),
			'capped'    => ! empty( $s['capped'] ),
		];
	}

	public static function start() {
		if ( '' === self::key() ) {
			return;
		}

		self::install();
		$s = self::state();
		update_option( self::STATE, [ 'status' => 'pages', 'run' => time(), 'first_run' => $s['first_run'] ?: time(), 'page' => 0, 'targets' => [], 'detail' => 0, 'error' => '', 'time' => $s['time'], 'capped' => false ], false );

		if ( ! wp_next_scheduled( self::CRON_BATCH ) ) {
			wp_schedule_single_event( time(), self::CRON_BATCH );
		}
	}

	/** One slice of a run, at most CALLS requests. Returns the state after it. */
	public static function run_batch() {
		$s = self::state();
		if ( ! in_array( $s['status'], [ 'pages', 'links' ], true ) ) {
			return $s;
		}

		$site  = home_url( '/' );
		$calls = 0;

		while ( 'pages' === $s['status'] && $calls < self::CALLS ) {
			$r = self::call( 'GetLinkCounts', [ 'siteUrl' => $site, 'page' => (string) $s['page'] ] );
			$calls ++;
			if ( ! $r['ok'] ) {
				return self::fail( $s, $r['reason'] );
			}
			foreach ( (array) ( $r['data']['Links'] ?? [] ) as $row ) {
				if ( ! empty( $row['Url'] ) ) {
					$s['targets'][] = (string) $row['Url'];
				}
			}
			$s['page'] ++;
			$s['capped'] = $s['capped'] || (int) ( $r['data']['TotalPages'] ?? 0 ) > self::MAX_PAGES;
			if ( $s['page'] >= min( self::MAX_PAGES, (int) ( $r['data']['TotalPages'] ?? 0 ) ) ) {
				$s['status'] = 'links';
				$s['page']   = 0;
			}
		}

		while ( 'links' === $s['status'] && $calls < self::CALLS ) {
			if ( ! $s['targets'] ) {
				$s = self::finish( $s );
				break;
			}

			$target = (string) $s['targets'][0];
			$r      = self::call( 'GetUrlLinks', [ 'siteUrl' => $site, 'link' => $target, 'page' => (string) $s['detail'] ] );
			$calls ++;
			if ( ! $r['ok'] ) {
				return self::fail( $s, $r['reason'] );
			}
			foreach ( (array) ( $r['data']['Details'] ?? [] ) as $row ) {
				if ( ! empty( $row['Url'] ) ) {
					self::record( (string) $row['Url'], $target, (string) ( $row['AnchorText'] ?? '' ), $s['run'] );
				}
			}
			$s['detail'] ++;
			$s['capped'] = $s['capped'] || (int) ( $r['data']['TotalPages'] ?? 0 ) > self::MAX_DETAIL;
			if ( $s['detail'] >= min( self::MAX_DETAIL, (int) ( $r['data']['TotalPages'] ?? 0 ) ) ) {
				array_shift( $s['targets'] );
				$s['detail'] = 0;
			}
		}

		update_option( self::STATE, $s, false );

		if ( in_array( $s['status'], [ 'pages', 'links' ], true ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_BATCH );
		}

		return $s;
	}

	protected static function fail( array $s, $reason ) {
		$s['status'] = 'error';
		$s['error']  = (string) $reason;
		update_option( self::STATE, $s, false );

		return $s;
	}

	/** Store one link as seen in this run. */
	public static function record( $source, $target, $anchor, $run ) {
		global $wpdb;

		$wpdb->query( $wpdb->prepare(
			'INSERT INTO ' . self::table() . ' (link_key, source, host, target, anchor, first_seen, last_seen, lost_at) VALUES (%s, %s, %s, %s, %s, %d, %d, 0)
			 ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen), anchor = VALUES(anchor), lost_at = 0', // phpcs:ignore WordPress.DB
			md5( $source . '|' . $target ),
			$source,
			substr( self::host_of( $source ), 0, 191 ),
			$target,
			mb_substr( $anchor, 0, 255 ),
			$run,
			$run
		) );
	}

	protected static function finish( array $s ) {
		global $wpdb;

		$t    = self::table();
		$seen = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE last_seen = %d", $s['run'] ) ); // phpcs:ignore WordPress.DB

		// A run that was cut short, or came back empty, says nothing about what is gone.
		if ( $seen && ! $s['capped'] ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET lost_at = %d WHERE last_seen < %d AND lost_at = 0", $s['run'], $s['run'] ) ); // phpcs:ignore WordPress.DB
		}

		// Which of the pages being linked to no longer answer?
		$dead = [];
		$own  = self::host_of( home_url() );
		foreach ( array_slice( (array) $wpdb->get_col( "SELECT DISTINCT target FROM {$t} WHERE lost_at = 0" ), 0, 200 ) as $target ) { // phpcs:ignore WordPress.DB
			if ( self::host_of( $target ) !== $own ) {
				continue;
			}
			$r    = wp_remote_head( (string) $target, [ 'timeout' => 5, 'redirection' => 3, 'sslverify' => false ] );
			$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			if ( in_array( $code, [ 404, 410 ], true ) ) {
				$dead[] = (string) $target;
			}
		}
		update_option( self::DEAD, $dead, false );

		self::refresh_authority( (array) $wpdb->get_col( "SELECT DISTINCT host FROM {$t} WHERE lost_at = 0" ) ); // phpcs:ignore WordPress.DB

		$s['status'] = 'ready';
		$s['time']   = time();

		return $s;
	}

	/* ------------------------------------------------------------------ *
	 * Questions
	 * ------------------------------------------------------------------ */

	public static function referring( $limit = 100 ) {
		global $wpdb;

		if ( (int) get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			return [];
		}

		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT host, COUNT(*) AS links, COUNT(DISTINCT target) AS pages, MIN(first_seen) AS first_seen FROM ' . self::table() . ' WHERE lost_at = 0 GROUP BY host ORDER BY links DESC LIMIT %d', // phpcs:ignore WordPress.DB
			(int) $limit
		) );
	}

	/** @return object[] Links first seen after the first run, or lost, within $days. */
	public static function changes( $kind, $days = 30 ) {
		global $wpdb;

		if ( (int) get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			return [];
		}

		$since = time() - $days * DAY_IN_SECONDS;
		$t     = self::table();

		if ( 'lost' === $kind ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE lost_at > %d ORDER BY lost_at DESC LIMIT 50", $since ) ); // phpcs:ignore WordPress.DB
		}

		// The first run finds everything; none of that is "new".
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE first_seen > %d AND first_seen > %d AND lost_at = 0 ORDER BY first_seen DESC LIMIT 50", $since, self::state()['first_run'] ) ); // phpcs:ignore WordPress.DB
	}

	/* ------------------------------------------------------------------ *
	 * Search volumes
	 * ------------------------------------------------------------------ */

	/** @return array{0: string, 1: string} [country, language] for the keyword calls. */
	public static function market() {
		$locale = str_replace( '_', '-', (string) get_locale() );
		$parts  = explode( '-', $locale );

		return [
			strtolower( trim( (string) FW_SEO_Settings::get( 'bing_country', '' ) ) ?: ( $parts[1] ?? 'us' ) ),
			trim( (string) FW_SEO_Settings::get( 'bing_language', '' ) ) ?: ( isset( $parts[1] ) ? $locale : 'en-US' ),
		];
	}

	/** The arguments every keyword call shares: the last 30 days Bing has figures for. */
	protected static function keyword_args( $phrase ) {
		[ $country, $language ] = self::market();

		return [
			'q'         => (string) $phrase,
			'country'   => $country,
			'language'  => $language,
			'startDate' => gmdate( 'Y-m-d', time() - 32 * DAY_IN_SECONDS ),
			'endDate'   => gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ),
		];
	}

	/** Pure: one Keyword record, as {phrase, exact, broad}. */
	public static function parse_keyword( $row ) {
		return is_array( $row ) && isset( $row['Query'] ) ? [
			'phrase' => (string) $row['Query'],
			'exact'  => (int) ( $row['Impressions'] ?? 0 ),
			'broad'  => (int) ( $row['BroadImpressions'] ?? 0 ),
		] : null;
	}

	/** Bing searches for a phrase over the last 30 days, or null. */
	public static function volume( $phrase ) {
		$r = self::call( 'GetKeyword', self::keyword_args( $phrase ) );
		$k = $r['ok'] ? self::parse_keyword( $r['data'] ) : null;

		return $k ? $k + [ 'time' => time() ] : null;
	}

	/** @return array[] Phrases Bing relates to this one, most searched first. */
	public static function related( $phrase ) {
		$r   = self::call( 'GetRelatedKeywords', self::keyword_args( $phrase ) );
		$out = array_values( array_filter( array_map( [ __CLASS__, 'parse_keyword' ], $r['ok'] ? (array) $r['data'] : [] ) ) );
		usort( $out, static function ( $a, $b ) {
			return $b['broad'] <=> $a['broad'];
		} );

		return array_slice( $out, 0, 20 );
	}

	/** @internal Look up one phrase, or the topics on the momentum screen. */
	public static function _action_volumes() {
		self::guard( 'fw_seo_bing_nonce' );

		$phrase = isset( $_POST['fw_seo_bing_phrase'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['fw_seo_bing_phrase'] ) ) ) : '';

		if ( '' !== $phrase && empty( $_POST['fw_seo_bing_all'] ) ) {
			set_transient( self::LOOKUP . get_current_user_id(), [ 'phrase' => $phrase, 'volume' => self::volume( $phrase ), 'related' => self::related( $phrase ) ], HOUR_IN_SECONDS );
		} else {
			$volumes = (array) get_option( self::VOLUMES, [] );
			foreach ( array_slice( FW_SEO_Trends::momentum_rows(), 0, 30 ) as $row ) {
				$v = self::volume( (string) $row['term'] );
				if ( $v ) {
					$volumes[ strtolower( (string) $row['term'] ) ] = $v;
				}
			}
			update_option( self::VOLUMES, $volumes, false );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=fw-seo-settings&view=momentum_tab' ) );
		exit;
	}

	/** The figure for a topic on the momentum screen, or null. */
	public static function stored_volume( $term ) {
		$volumes = (array) get_option( self::VOLUMES, [] );

		return $volumes[ strtolower( (string) $term ) ] ?? null;
	}

	/** @return string The look-up on SEO → Topic momentum. */
	public static function volumes_html() {
		if ( '' === self::key() ) {
			return '<h3 style="margin-top:24px">' . esc_html__( 'How much it is searched', 'fw' ) . '</h3><p class="description">' . esc_html__( 'Add a Bing Webmaster Tools API key under Backlinks to see how often a phrase is searched on Bing.', 'fw' ) . '</p>';
		}

		$key  = self::LOOKUP . get_current_user_id();
		$last = get_transient( $key );
		delete_transient( $key );
		$to   = esc_attr( admin_url( 'admin-post.php' ) . '?action=fw_seo_bing_volumes' );

		$html = '<h3 style="margin-top:24px">' . esc_html__( 'How much it is searched', 'fw' ) . '</h3><p class="description" style="max-width:52em">' . esc_html__( 'Searches on Bing over the last 30 days. Bing is a small share of all searching, so the numbers are low: read them against each other, not as a forecast of visitors.', 'fw' ) . '</p>'
			. wp_nonce_field( self::NONCE, 'fw_seo_bing_nonce', false, false )
			. '<p><input type="text" style="width:320px;max-width:100%" name="fw_seo_bing_phrase" placeholder="' . esc_attr__( 'A phrase to look up', 'fw' ) . '"> <button type="submit" class="button" formaction="' . $to . '" formnovalidate>' . esc_html__( 'Look up', 'fw' ) . '</button>';
		if ( FW_SEO_Trends::imports() ) {
			$html .= ' <button type="submit" class="button" name="fw_seo_bing_all" value="1" formaction="' . $to . '" formnovalidate>' . esc_html__( 'Look up every topic below', 'fw' ) . '</button>';
		}
		$html .= '</p>';

		if ( is_array( $last ) ) {
			$rows = array_merge( $last['volume'] ? [ $last['volume'] ] : [], (array) $last['related'] );
			if ( $rows ) {
				$html .= '<table class="widefat striped" style="max-width:700px"><thead><tr><th>' . esc_html__( 'Phrase', 'fw' ) . '</th><th>' . esc_html__( 'Searches, this exact phrase', 'fw' ) . '</th><th>' . esc_html__( 'Searches containing it', 'fw' ) . '</th></tr></thead><tbody>';
				foreach ( $rows as $r ) {
					$html .= '<tr><td>' . esc_html( $r['phrase'] ) . '</td><td>' . esc_html( number_format_i18n( $r['exact'] ) ) . '</td><td>' . esc_html( number_format_i18n( $r['broad'] ) ) . '</td></tr>';
				}
				$html .= '</tbody></table>';
			} else {
				$html .= '<p>' . esc_html( sprintf(
					/* translators: %s: the phrase */
					__( 'Bing has no figures for "%s" in this market.', 'fw' ),
					$last['phrase']
				) ) . '</p>';
			}
		}

		return $html;
	}

	/* ------------------------------------------------------------------ *
	 * Screens
	 * ------------------------------------------------------------------ */

	protected static function guard( $field = '_wpnonce' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'fw' ), 403 );
		}
		check_admin_referer( self::NONCE, $field );
	}

	/** Pure: text from other sites, kept from running as a formula in a spreadsheet. */
	public static function csv_safe( $value ) {
		return preg_match( '/^[=+\-@\t\r]/', (string) $value ) ? "'" . $value : (string) $value;
	}

	/** @internal */
	public static function _action_run() {
		self::guard();
		self::start();
		self::run_batch();
		wp_safe_redirect( admin_url( 'admin.php?page=fw-seo-settings&view=backlinks_tab' ) );
		exit;
	}

	/** @internal */
	public static function _action_export() {
		global $wpdb;

		self::guard();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="backlinks-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$h = fopen( 'php://output', 'w' );
		fputcsv( $h, [ 'source', 'site', 'target', 'text', 'first_seen', 'last_seen', 'lost' ] );
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY host' ) as $r ) { // phpcs:ignore WordPress.DB
			fputcsv( $h, [ self::csv_safe( $r->source ), self::csv_safe( $r->host ), $r->target, self::csv_safe( $r->anchor ), gmdate( 'Y-m-d', (int) $r->first_seen ), gmdate( 'Y-m-d', (int) $r->last_seen ), $r->lost_at ? gmdate( 'Y-m-d', (int) $r->lost_at ) : '' ] );
		}
		fclose( $h );
		exit;
	}

	protected static function link_rows( array $rows, $when ) {
		$html = '<table class="widefat striped" style="max-width:1200px"><thead><tr><th>' . esc_html__( 'From', 'fw' ) . '</th><th>' . esc_html__( 'To your page', 'fw' ) . '</th><th>' . esc_html__( 'Text', 'fw' ) . '</th><th>' . esc_html__( 'When', 'fw' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$html .= '<tr><td><a href="' . esc_url( $r->source ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $r->host ) . '</a></td><td><code>' . esc_html( (string) wp_parse_url( $r->target, PHP_URL_PATH ) ) . '</code></td><td>' . esc_html( $r->anchor ) . '</td><td>' . esc_html( human_time_diff( (int) $r->$when ) . ' ' . __( 'ago', 'fw' ) ) . '</td></tr>';
		}

		return $html . '</tbody></table>';
	}

	/** @return string SEO → Backlinks. */
	public static function report_html() {
		if ( '' === self::key() ) {
			return '<p>' . esc_html__( 'Add your Bing Webmaster Tools API key above and save.', 'fw' ) . '</p>';
		}

		$s    = self::state();
		$url  = static function ( $a ) {
			return wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_bing_' . $a ), self::NONCE );
		};
		$html = '';

		if ( 'error' === $s['status'] ) {
			$html .= '<div class="notice notice-error inline"><p>' . esc_html( sprintf(
				/* translators: %s: the error from Bing */
				__( 'Bing did not answer: %s. Check the key, and that this exact address is verified in Bing Webmaster Tools.', 'fw' ),
				$s['error']
			) ) . '</p></div>';
		}
		if ( in_array( $s['status'], [ 'pages', 'links' ], true ) ) {
			$html .= '<p>' . esc_html__( 'Reading your links from Bing, a few at a time, in the background. Come back in a few minutes.', 'fw' ) . '</p>';
		}

		if ( $s['capped'] ) {
			$html .= '<p class="description">' . esc_html__( 'Bing lists more links than one run reads, so links are not marked as lost on this site: a link missing from a partial read proves nothing.', 'fw' ) . '</p>';
		}

		$html .= '<p>' . ( $s['time'] ? esc_html( sprintf(
			/* translators: %s: how long ago */
			__( 'Last read %s ago; it runs again every week. This is what Bing has found, which is less than the whole web.', 'fw' ),
			human_time_diff( $s['time'] )
		) ) : '' ) . ' <a class="button" href="' . esc_url( $url( 'run' ) ) . '">' . esc_html__( 'Read now', 'fw' ) . '</a> <a class="button" href="' . esc_url( $url( 'export' ) ) . '">' . esc_html__( 'Export CSV', 'fw' ) . '</a></p>';

		$dead = (array) get_option( self::DEAD, [] );
		if ( $dead ) {
			$html .= '<h3 style="margin-top:24px">' . esc_html__( 'Links to pages that no longer exist', 'fw' ) . ' <span class="count">(' . count( $dead ) . ')</span></h3><p class="description">' . esc_html__( 'Other sites link here and visitors get "not found". A redirect wins that link back.', 'fw' ) . '</p><table class="widefat striped" style="max-width:900px"><tbody>';
			foreach ( array_slice( $dead, 0, 50 ) as $target ) {
				$path  = (string) wp_parse_url( $target, PHP_URL_PATH );
				$fix   = add_query_arg( [ 'page' => 'fw-seo-settings', 'view' => 'redirects_tab', 'fw_seo_src' => rawurlencode( class_exists( 'FW_SEO_Redirect_Rules' ) ? FW_SEO_Redirect_Rules::relative( $path ) : $path ) ], admin_url( 'admin.php' ) );
				$html .= '<tr><td><code>' . esc_html( $path ) . '</code></td><td style="width:8em"><a class="button button-small" href="' . esc_url( $fix ) . '">' . esc_html__( 'Redirect it', 'fw' ) . '</a></td></tr>';
			}
			$html .= '</tbody></table>';
		}

		$sites  = self::referring( 100 );
		$scores = (array) get_option( self::AUTHORITY, [] );
		$html  .= '<h3 style="margin-top:24px">' . esc_html__( 'Sites that link to you', 'fw' ) . ' <span class="count">(' . count( $sites ) . ')</span></h3>';
		if ( $sites ) {
			$html .= '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>' . esc_html__( 'Site', 'fw' ) . '</th><th>' . esc_html__( 'Links', 'fw' ) . '</th><th>' . esc_html__( 'To your pages', 'fw' ) . '</th>' . ( $scores ? '<th title="' . esc_attr__( 'Open PageRank, 0 to 10. A rough signal, not a target.', 'fw' ) . '">' . esc_html__( 'Rank (0–10)', 'fw' ) . '</th>' : '' ) . '<th>' . esc_html__( 'First seen', 'fw' ) . '</th></tr></thead><tbody>';
			foreach ( $sites as $r ) {
				$score = self::score_for( $r->host, $scores );
				$html .= '<tr><td>' . esc_html( $r->host ) . '</td><td>' . esc_html( number_format_i18n( (int) $r->links ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $r->pages ) ) . '</td>' . ( $scores ? '<td>' . ( null === $score ? '–' : esc_html( number_format_i18n( $score, 1 ) ) ) . '</td>' : '' ) . '<td>' . esc_html( date_i18n( get_option( 'date_format' ), (int) $r->first_seen ) ) . '</td></tr>';
			}
			$html .= '</tbody></table>';
		}

		foreach ( [ 'new' => [ __( 'New links, last 30 days', 'fw' ), 'first_seen' ], 'lost' => [ __( 'Lost links, last 30 days', 'fw' ), 'lost_at' ] ] as $kind => $label ) {
			$rows = self::changes( $kind );
			if ( $rows ) {
				$html .= '<h3 style="margin-top:24px">' . esc_html( $label[0] ) . ' <span class="count">(' . count( $rows ) . ')</span></h3>' . self::link_rows( $rows, $label[1] );
			}
		}

		return $html;
	}
}
