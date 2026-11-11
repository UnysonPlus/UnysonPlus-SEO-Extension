<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Broken link checker — what the server actually answers for every link.
 *
 * The link index (FW_SEO_Links) knows every link in the content and the page
 * builder; this fetches each address, slowly, and records the answer: broken,
 * redirected, a redirect chain or a loop. A site that blocks robots (401, 403,
 * 429) or a timeout is "could not check", never "broken": a false alarm about
 * someone else's server is worse than a gap. A server error counts as broken
 * only after failing twice, a week apart.
 *
 * Polite by design: a few addresses an hour, at most three per outside site in
 * a run, HEAD first, each address again after a week.
 *
 * @since 2.0.54
 */
class FW_SEO_Link_Check {

	const DB_VERSION        = 1;
	const DB_VERSION_OPTION = 'fw_seo_link_check_db';
	const CRON              = 'fw_seo_link_check';
	const BATCH             = 25;
	const PER_HOST          = 3;
	const RECHECK           = WEEK_IN_SECONDS;
	const MAX_HOPS          = 5;
	const BUDGET            = 40;
	const NONCE             = 'fw_seo_link_check';

	/** Swapped for report_html() when the settings page is drawn. */
	const PLACEHOLDER = '<!--fw-seo-link-check-report-->';

	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'fw_seo_link_status';
	}

	public static function init() {
		add_action( self::CRON, [ __CLASS__, 'run_batch' ] );
		add_action( 'admin_init', [ __CLASS__, '_schedule' ] );
		add_action( 'admin_post_fw_seo_link_check_run', [ __CLASS__, '_action_run' ] );
	}

	/** @internal */
	public static function _schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	public static function install() {
		global $wpdb;

		if ( (int) get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::table();
		dbDelta( "CREATE TABLE {$table} (
			url_key char(32) NOT NULL,
			url text NOT NULL,
			host varchar(191) NOT NULL DEFAULT '',
			state varchar(12) NOT NULL DEFAULT '',
			status smallint(5) unsigned NOT NULL DEFAULT 0,
			final_url text NOT NULL,
			hops tinyint(3) unsigned NOT NULL DEFAULT 0,
			fails tinyint(3) unsigned NOT NULL DEFAULT 0,
			checked bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (url_key),
			KEY checked (checked),
			KEY state (state)
		) {$wpdb->get_charset_collate()};" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/* ------------------------------------------------------------------ *
	 * Judging — pure
	 * ------------------------------------------------------------------ */

	/**
	 * What a walk through an address's redirects amounts to.
	 *
	 * @param array $hops  [ [url, status], … ] in order; status 0 = no answer.
	 * @param int   $fails Earlier failed checks of this address.
	 *
	 * @return string ok|redirected|chain|loop|broken|unknown
	 */
	public static function classify( array $hops, $fails = 0 ) {
		if ( ! $hops ) {
			return 'unknown';
		}

		$urls = array_column( $hops, 0 );
		if ( count( $urls ) !== count( array_unique( $urls ) ) ) {
			return 'loop';
		}

		$last = (int) end( $hops )[1];

		if ( in_array( $last, [ 404, 410 ], true ) ) {
			return 'broken';
		}
		if ( $last >= 500 ) {
			return $fails >= 1 ? 'broken' : 'unknown';
		}
		if ( 0 === $last || in_array( $last, [ 401, 403, 405, 406, 429, 451, 999 ], true ) || ( $last >= 300 && $last < 400 ) ) {
			return 'unknown';
		}
		if ( $last >= 400 ) {
			return 'broken';
		}

		$redirects = count( $hops ) - 1;

		return 0 === $redirects ? 'ok' : ( 1 === $redirects ? 'redirected' : 'chain' );
	}

	/**
	 * Redirect rules that lead to another rule, or round in a circle.
	 *
	 * @param array $rules From FW_SEO_Redirect_Rules::all().
	 * @param string $own_host
	 *
	 * @return array<int, array{steps: string[], loop: bool}>
	 */
	public static function rule_chains( array $rules, $own_host ) {
		$map = [];
		foreach ( $rules as $r ) {
			if ( empty( $r['is_regex'] ) && 410 !== (int) $r['code'] && '' !== (string) $r['target'] ) {
				$map[ FW_SEO_Redirect_Rules::key_of( $r['source'] ) ] = (string) $r['target'];
			}
		}

		$local = static function ( $target ) use ( $own_host ) {
			$host = (string) wp_parse_url( $target, PHP_URL_HOST );

			return '' === $host || 0 === strcasecmp( preg_replace( '#^www\.#i', '', $host ), preg_replace( '#^www\.#i', '', (string) $own_host ) );
		};

		$out     = [];
		$circles = [];
		foreach ( array_keys( $map ) as $start ) {
			$steps = [ $start ];
			$at    = $start;
			$loop  = false;

			while ( isset( $map[ $at ] ) && $local( $map[ $at ] ) ) {
				$next = FW_SEO_Redirect_Rules::key_of( $map[ $at ] );
				if ( in_array( $next, $steps, true ) ) {
					$steps[] = $next;
					$loop    = true;
					break;
				}
				$steps[] = $next;
				$at      = $next;
			}

			// A plain rule is two steps. Longer, or circular, is the problem.
			if ( $loop || count( $steps ) > 2 ) {
				// A circle reads the same from each of its rules: list it once.
				$circle = array_values( array_unique( $steps ) );
				sort( $circle );
				if ( $loop && in_array( $circle, $circles, true ) ) {
					continue;
				}
				$circles[] = $circle;
				$out[]     = [ 'steps' => $steps, 'loop' => $loop ];
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Checking
	 * ------------------------------------------------------------------ */

	/** @return array{0: int, 1: string} [status, Location header]. */
	protected static function ask( $url ) {
		$own  = 0 === strcasecmp( (string) wp_parse_url( $url, PHP_URL_HOST ), (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$args = [
			'timeout'     => 8,
			'redirection' => 0,
			'sslverify'   => ! $own,
			'user-agent'  => 'Mozilla/5.0 (compatible; Unyson+ link check)',
		];
		$send = static function ( $method ) use ( $url, $args, $own ) {
			$a = $args + [ 'method' => $method ];
			if ( 'GET' === $method ) {
				$a['limit_response_size'] = 4096;
			}

			// The safe variant refuses private addresses, so a link in the content
			// cannot make the server probe its own network. The site itself is exempt.
			return $own ? wp_remote_request( $url, $a ) : wp_safe_remote_request( $url, $a );
		};

		$r = $send( 'HEAD' );
		// Plenty of servers refuse HEAD and answer GET.
		if ( ! is_wp_error( $r ) && in_array( (int) wp_remote_retrieve_response_code( $r ), [ 403, 404, 405, 501 ], true ) ) {
			$r = $send( 'GET' );
		}

		return is_wp_error( $r ) ? [ 0, '' ] : [ (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_header( $r, 'location' ) ];
	}

	/**
	 * Follow an address through its redirects.
	 *
	 * @return array [ [url, status], … ]
	 */
	public static function walk( $url ) {
		$hops = [];

		for ( $i = 0; $i <= self::MAX_HOPS; $i ++ ) {
			[ $status, $location ] = self::ask( $url );
			$hops[]                = [ $url, $status ];

			if ( $status < 300 || $status >= 400 || '' === $location ) {
				break;
			}

			$next = WP_Http::make_absolute_url( $location, $url );
			if ( in_array( $next, array_column( $hops, 0 ), true ) ) {
				$hops[] = [ $next, $status ];
				break;
			}
			$url = $next;
		}

		return $hops;
	}

	/** Add addresses the link index knows and this table does not. */
	public static function refresh_queue() {
		global $wpdb;

		self::install();
		$t = self::table();
		$l = FW_SEO_Links::table();
		$wpdb->query( "INSERT IGNORE INTO {$t} (url_key, url, host, final_url) SELECT DISTINCT MD5(url), url, host, '' FROM {$l}" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$t} WHERE url_key NOT IN ( SELECT MD5(url) FROM {$l} )" ); // phpcs:ignore WordPress.DB
	}

	/** Check the next addresses due. Returns how many. */
	public static function run_batch() {
		global $wpdb;

		if ( ! class_exists( 'FW_SEO_Links' ) || 'ready' !== FW_SEO_Links::state()['status'] ) {
			return 0;
		}

		self::refresh_queue();
		$t    = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE checked < %d ORDER BY checked ASC LIMIT 200", time() - self::RECHECK ) ); // phpcs:ignore WordPress.DB

		$done  = 0;
		$hosts = [];
		$stop  = time() + self::BUDGET;
		foreach ( (array) $rows as $row ) {
			if ( $done >= self::BATCH || time() >= $stop ) {
				break;
			}
			$hosts[ $row->host ] = ( $hosts[ $row->host ] ?? 0 ) + 1;
			if ( $hosts[ $row->host ] > self::PER_HOST && $row->host !== preg_replace( '#^www\.#', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) ) {
				continue;
			}

			$hops  = self::walk( (string) $row->url );
			$state = self::classify( $hops, (int) $row->fails );
			$last  = end( $hops );
			$wpdb->update( $t, [
				'state'     => $state,
				'status'    => (int) $last[1],
				'final_url' => (string) $last[0],
				'hops'      => max( 0, count( $hops ) - 1 ),
				'fails'     => (int) $last[1] >= 500 || 0 === (int) $last[1] ? (int) $row->fails + 1 : 0,
				'checked'   => time(),
			], [ 'url_key' => $row->url_key ] );
			$done ++;
		}

		return $done;
	}

	/* ------------------------------------------------------------------ *
	 * The report
	 * ------------------------------------------------------------------ */

	/** @return array state => rows {url, status, final_url, hops, source_id, anchor}, one per page and link. */
	public static function findings() {
		global $wpdb;

		if ( (int) get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			return [];
		}

		$t    = self::table();
		$l    = FW_SEO_Links::table();
		$rows = $wpdb->get_results( "SELECT s.url, s.state, s.status, s.final_url, s.hops, l.source_id, l.anchor FROM {$t} s JOIN {$l} l ON MD5(l.url) = s.url_key WHERE s.state IN ('broken','loop','chain','redirected') ORDER BY s.state, s.url LIMIT 1000" ); // phpcs:ignore WordPress.DB

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ $r->state ][] = $r;
		}

		return $out;
	}

	/** @return array{total: int, checked: int, unknown: int} */
	public static function progress() {
		global $wpdb;

		if ( (int) get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			return [ 'total' => 0, 'checked' => 0, 'unknown' => 0 ];
		}

		$t = self::table();

		return [
			'total'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ), // phpcs:ignore WordPress.DB
			'checked' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE checked > 0" ), // phpcs:ignore WordPress.DB
			'unknown' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE state = 'unknown'" ), // phpcs:ignore WordPress.DB
		];
	}

	/** @internal */
	public static function _action_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'fw' ), 403 );
		}
		check_admin_referer( self::NONCE );
		self::run_batch();
		wp_safe_redirect( admin_url( 'admin.php?page=fw-seo-settings&view=broken_tab' ) );
		exit;
	}

	protected static function table_html( array $rows, $show_final ) {
		$html = '<table class="widefat striped" style="max-width:1200px"><thead><tr><th>' . esc_html__( 'On page', 'fw' ) . '</th><th>' . esc_html__( 'Link', 'fw' ) . '</th><th>' . esc_html__( 'Text', 'fw' ) . '</th><th>' . esc_html( $show_final ? __( 'Ends at', 'fw' ) : __( 'Answer', 'fw' ) ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $rows, 0, 100 ) as $r ) {
			$html .= '<tr><td><a href="' . esc_url( (string) get_edit_post_link( (int) $r->source_id ) ) . '">' . esc_html( get_the_title( (int) $r->source_id ) ) . '</a></td><td><code>' . esc_html( $r->url ) . '</code></td><td>' . esc_html( $r->anchor ) . '</td><td>'
				. ( $show_final ? '<code>' . esc_html( $r->final_url ) . '</code>' : esc_html( (string) $r->status ) ) . '</td></tr>';
		}

		return $html . '</tbody></table>';
	}

	/** @return string SEO → Broken links. */
	public static function report_html() {
		if ( ! class_exists( 'FW_SEO_Links' ) || 'ready' !== FW_SEO_Links::state()['status'] ) {
			return '<p>' . esc_html__( 'Waiting for the link report to finish reading the site. Open SEO → Links once to start it.', 'fw' ) . '</p>';
		}

		$p    = self::progress();
		$f    = self::findings();
		$run  = wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_link_check_run' ), self::NONCE );
		$html = '<p>' . esc_html( sprintf(
			/* translators: 1: addresses checked, 2: all addresses, 3: could not be checked */
			__( '%1$d of %2$d addresses checked so far. It works through them by itself, a few each hour, and checks each again after a week. %3$d could not be checked: those sites refuse automated requests or did not answer, which says nothing about the link.', 'fw' ),
			$p['checked'],
			$p['total'],
			$p['unknown']
		) ) . ' <a class="button" href="' . esc_url( $run ) . '">' . esc_html__( 'Check the next few now', 'fw' ) . '</a></p>';

		foreach ( [
			'broken'     => [ __( 'Broken links', 'fw' ), __( 'The address answers "not found" or an error. Update the link or remove it.', 'fw' ), false ],
			'loop'       => [ __( 'Redirect loops', 'fw' ), __( 'The address redirects in a circle and never arrives.', 'fw' ), true ],
			'chain'      => [ __( 'Redirect chains', 'fw' ), __( 'The link goes through two or more redirects. Point it straight at where it ends.', 'fw' ), true ],
			'redirected' => [ __( 'Redirected links', 'fw' ), __( 'The link works, through one redirect. Updating it saves a step; low priority.', 'fw' ), true ],
		] as $state => $label ) {
			$rows  = (array) ( $f[ $state ] ?? [] );
			$html .= '<h3 style="margin-top:24px">' . esc_html( $label[0] ) . ' <span class="count">(' . count( $rows ) . ')</span></h3>';
			if ( $rows ) {
				$html .= '<p class="description">' . esc_html( $label[1] ) . '</p>' . self::table_html( $rows, $label[2] );
			}
		}

		$chains = self::rule_chains( FW_SEO_Redirect_Rules::all(), (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( $chains ) {
			$html .= '<h3 style="margin-top:24px">' . esc_html__( 'Your own redirects that lead to another', 'fw' ) . ' <span class="count">(' . count( $chains ) . ')</span></h3><p class="description">' . esc_html__( 'Edit the first redirect to go straight to the end.', 'fw' ) . '</p><ul style="margin-left:18px;list-style:disc">';
			foreach ( $chains as $c ) {
				$html .= '<li><code>' . esc_html( implode( ' → ', $c['steps'] ) ) . '</code>' . ( $c['loop'] ? ' <strong>' . esc_html__( 'a loop', 'fw' ) . '</strong>' : '' ) . '</li>';
			}
			$html .= '</ul>';
		}

		return $html;
	}
}
