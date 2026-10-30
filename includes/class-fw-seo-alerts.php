<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Alerts — being told, instead of remembering to look.
 *
 * Every report in the extension is pull-based: a report nobody opens may as
 * well not exist. This collects, once a day, the few findings worth
 * interrupting someone for, and shows them where people already are: the
 * dashboard, the editor of the page concerned, and (opt-in) a weekly email.
 *
 * The test for an alert: it names a page, names an action, and is rare enough
 * to be believed. So every alert has a minimum-volume floor (a 30% drop on 50
 * impressions is noise), the list is capped, and the email goes weekly at most
 * and only when something changed: SEO moves in months.
 *
 * Detection needs no AI. The messages are already written as plain sentences
 * ("4,000 people saw this page and 36 clicked") rather than metrics.
 *
 * Nothing reads settings during startup (the option-types trap).
 *
 * @since 2.0.41
 */
class FW_SEO_Alerts {

	const OPTION     = 'fw_seo_alerts';
	const SENT       = 'fw_seo_alerts_sent';
	const CRON_DAILY = 'fw_seo_alerts_collect';
	const CRON_MAIL  = 'fw_seo_alerts_email';

	/** Swapped for report_html() when the settings page is drawn. */
	const PLACEHOLDER = '<!--fw-seo-alerts-report-->';

	/** The floors. Below these a number says nothing. */
	const LOW_CTR_IMPRESSIONS = 1000;
	const LOW_CTR_RATE        = 0.01;
	const LOW_CTR_POSITION    = 10.0;
	const DROP_FLOOR          = 300;
	const DROP_SHARE          = 0.5;
	const STALE_CLICKS        = 30;
	const NOINDEX_CLICKS      = 10;
	const SEARCH_HITS         = 5;

	/** At most this many alerts at once: a long list stops being read. */
	const CAP = 8;

	public static function init() {
		add_action( self::CRON_DAILY, [ __CLASS__, 'collect' ] );
		add_action( self::CRON_MAIL, [ __CLASS__, 'send_email' ] );
		add_action( 'admin_init', [ __CLASS__, '_schedule' ] );
		add_action( 'wp_dashboard_setup', [ __CLASS__, '_dashboard' ] );
		add_action( 'admin_post_fw_seo_alerts_refresh', [ __CLASS__, '_action_refresh' ] );
	}

	/** @internal */
	public static function _schedule() {
		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
		}
		if ( ! wp_next_scheduled( self::CRON_MAIL ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_MAIL );
		}
	}

	/* ------------------------------------------------------------------ *
	 * The judgements — pure, so they can be tested without Google
	 * ------------------------------------------------------------------ */

	/**
	 * Seen often near the top, chosen rarely: the title and description are
	 * not telling people what they would get.
	 */
	public static function is_low_ctr( $impressions, $clicks, $position ) {
		return $impressions >= self::LOW_CTR_IMPRESSIONS
			&& $position > 0 && $position <= self::LOW_CTR_POSITION
			&& ( $clicks / max( 1, $impressions ) ) < self::LOW_CTR_RATE;
	}

	/** Impressions at least halved against the four weeks before. */
	public static function has_dropped( $now, $before ) {
		return $before >= self::DROP_FLOOR && $now <= $before * self::DROP_SHARE;
	}

	/** Old, and still bringing people in: the one kind of stale that matters. */
	public static function is_stale_with_traffic( $months, $clicks, $stale_after ) {
		return $months >= $stale_after && $clicks >= self::STALE_CLICKS;
	}

	/** Set to noindex while it was earning clicks: usually a mistake. */
	public static function is_lost_to_noindex( $noindex, $clicks_before ) {
		return (bool) $noindex && $clicks_before >= self::NOINDEX_CLICKS;
	}

	/**
	 * Build the alerts from data gathered elsewhere.
	 *
	 * @param array $pages    url => [ 'post_id', 'title', 'edit', 'now' => [impressions, clicks, position], 'before' => [...], 'months', 'noindex' ]
	 * @param array $near     Rows from FW_SEO_Rank::near_page_one(): page, query, position, impressions.
	 * @param array $searches Rows from the site-search log with no results: term, hits.
	 * @param array $extra    Already-built alerts (e.g. a broken connection).
	 * @param int   $stale_after Months.
	 *
	 * @return array
	 */
	public static function build( array $pages, array $near, array $searches, array $extra = [], $stale_after = 12 ) {
		$alerts = $extra;

		foreach ( $pages as $url => $p ) {
			$now    = (array) ( $p['now'] ?? [] );
			$before = (array) ( $p['before'] ?? [] );
			$imp    = (int) ( $now['impressions'] ?? 0 );
			$clk    = (int) ( $now['clicks'] ?? 0 );
			$pos    = (float) ( $now['position'] ?? 0 );
			$base   = [
				'url'     => (string) $url,
				'post_id' => (int) ( $p['post_id'] ?? 0 ),
				'title'   => (string) ( $p['title'] ?? $url ),
				'link'    => (string) ( $p['edit'] ?? '' ),
			];

			if ( self::is_lost_to_noindex( ! empty( $p['noindex'] ), (int) ( $before['clicks'] ?? 0 ) + $clk ) ) {
				$alerts[] = $base + [
					'type'     => 'noindex',
					'priority' => 0,
					'message'  => sprintf(
						/* translators: %d: clicks */
						__( 'Set to "No index", but it brought %d visitors from Google in the last two months. If that was not deliberate, switch "No index" off.', 'fw' ),
						(int) ( $before['clicks'] ?? 0 ) + $clk
					),
				];
				continue;
			}

			if ( self::has_dropped( $imp, (int) ( $before['impressions'] ?? 0 ) ) ) {
				$alerts[] = $base + [
					'type'     => 'drop',
					'priority' => 1,
					'message'  => sprintf(
						/* translators: 1: impressions before, 2: impressions now */
						__( 'Seen in Google %1$s times in the four weeks before, %2$s times in the last four. Check that it still answers what people search for, and that nothing newer on the site competes with it.', 'fw' ),
						number_format_i18n( (int) $before['impressions'] ),
						number_format_i18n( $imp )
					),
				];
				continue;
			}

			if ( self::is_low_ctr( $imp, $clk, $pos ) ) {
				$alerts[] = $base + [
					'type'     => 'ctr',
					'priority' => 2,
					'message'  => sprintf(
						/* translators: 1: impressions, 2: clicks */
						__( '%1$s people saw this page in Google and %2$s clicked. It ranks well enough to be seen; the title and description probably do not say what people would get. Rewrite them.', 'fw' ),
						number_format_i18n( $imp ),
						number_format_i18n( $clk )
					),
				];
				continue;
			}

			if ( isset( $p['months'] ) && self::is_stale_with_traffic( (int) $p['months'], $clk, $stale_after ) ) {
				$alerts[] = $base + [
					'type'     => 'stale',
					'priority' => 3,
					'message'  => sprintf(
						/* translators: 1: months since update, 2: clicks */
						__( 'Not updated for %1$d months and still brought %2$d visitors from Google in four weeks. Worth a refresh while it still ranks.', 'fw' ),
						(int) $p['months'],
						$clk
					),
				];
			}
		}

		$seen = [];
		foreach ( $near as $row ) {
			$url = (string) ( $row['page'] ?? '' );
			if ( isset( $seen[ $url ] ) || count( $seen ) >= 3 ) {
				continue;
			}
			$seen[ $url ] = true;
			$p            = $pages[ $url ] ?? [];
			$alerts[]     = [
				'url'      => $url,
				'post_id'  => (int) ( $p['post_id'] ?? 0 ),
				'title'    => (string) ( $p['title'] ?? $url ),
				'link'     => (string) ( $p['edit'] ?? '' ),
				'type'     => 'near',
				'priority' => 4,
				'message'  => sprintf(
					/* translators: 1: search phrase, 2: position */
					__( 'At position %2$s for "%1$s", just off the first page. A clearer answer to that search, or a link to it from a related page, can move it up.', 'fw' ),
					(string) ( $row['query'] ?? '' ),
					number_format_i18n( (float) ( $row['position'] ?? 0 ), 1 )
				),
			];
		}

		foreach ( $searches as $row ) {
			$row  = (array) $row;
			$hits = (int) ( $row['hits'] ?? 0 );
			if ( $hits < self::SEARCH_HITS ) {
				continue;
			}
			$alerts[] = [
				'url'      => '',
				'post_id'  => 0,
				'title'    => sprintf(
					/* translators: %s: search phrase */
					__( 'Site search: "%s"', 'fw' ),
					(string) ( $row['term'] ?? '' )
				),
				'link'     => admin_url( 'admin.php?page=fw-seo-settings&view=search_tab' ),
				'type'     => 'search',
				'priority' => 5,
				'message'  => sprintf(
					/* translators: %d: number of searches */
					__( 'Searched %d times on your site, and nothing was found. A page about it, or the word used on an existing page, would answer them.', 'fw' ),
					$hits
				),
			];
		}

		usort( $alerts, static function ( $a, $b ) {
			return ( $a['priority'] ?? 9 ) <=> ( $b['priority'] ?? 9 );
		} );

		return array_slice( $alerts, 0, self::CAP );
	}

	/* ------------------------------------------------------------------ *
	 * Gathering
	 * ------------------------------------------------------------------ */

	/**
	 * Per-page Search Console totals for a window.
	 *
	 * @return array|null url => [impressions, clicks, position], null on failure.
	 */
	protected static function page_totals( $start, $end ) {
		$result = FW_SEO_Console::query( [
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => [ 'page' ],
			'rowLimit'   => 500,
		] );

		if ( ! $result['ok'] ) {
			return null;
		}

		$out = [];
		foreach ( $result['rows'] as $row ) {
			$out[ (string) ( $row['keys'][0] ?? '' ) ] = [
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'position'    => (float) ( $row['position'] ?? 0 ),
			];
		}

		return $out;
	}

	/**
	 * Gather the data and store the alerts. Runs daily from cron.
	 *
	 * @return array{time: int, alerts: array}
	 */
	public static function collect() {
		$extra = [];
		$pages = [];
		$near  = [];

		$connected = class_exists( 'FW_SEO_Console' ) && FW_SEO_Console::connected();

		if ( $connected && '' === FW_SEO_Console::access_token() ) {
			$extra[] = [
				'url'      => '',
				'post_id'  => 0,
				'title'    => __( 'Search Console', 'fw' ),
				'link'     => admin_url( 'admin.php?page=fw-seo-settings&view=console_tab' ),
				'type'     => 'connection',
				'priority' => -1,
				'message'  => __( 'The connection has stopped working, so none of the alerts that use Google data can be checked. Connect again.', 'fw' ),
			];
		} elseif ( $connected && '' !== FW_SEO_Console::property() ) {
			$now    = FW_SEO_Console::window( 28 );
			$before = [
				'start' => gmdate( 'Y-m-d', strtotime( $now['start'] ) - 28 * DAY_IN_SECONDS ),
				'end'   => $now['start'],
			];
			$cur  = self::page_totals( $now['start'], $now['end'] );
			$prev = self::page_totals( $before['start'], $before['end'] );

			if ( null !== $cur ) {
				$stale_after = class_exists( 'FW_SEO_Freshness' ) ? FW_SEO_Freshness::stale_months() : 12;

				foreach ( array_unique( array_merge( array_keys( $cur ), array_keys( (array) $prev ) ) ) as $url ) {
					$post_id  = (int) url_to_postid( $url );
					$modified = $post_id ? (int) get_post_modified_time( 'U', true, $post_id ) : 0;

					$pages[ $url ] = [
						'post_id' => $post_id,
						'title'   => $post_id ? get_the_title( $post_id ) : $url,
						'edit'    => $post_id ? admin_url( 'post.php?post=' . $post_id . '&action=edit' ) : $url,
						'now'     => $cur[ $url ] ?? [],
						'before'  => (array) ( $prev[ $url ] ?? [] ),
						'months'  => $modified ? (int) floor( ( time() - $modified ) / ( 30 * DAY_IN_SECONDS ) ) : 0,
						'noindex' => $post_id && class_exists( 'FW_SEO_Store' ) ? (bool) FW_SEO_Store::get_post( $post_id, 'noindex' ) : false,
					];
				}

				if ( class_exists( 'FW_SEO_Rank' ) ) {
					$rank = FW_SEO_Rank::near_page_one();
					$near = $rank['ok'] ? (array) $rank['rows'] : [];
				}
			}
		}

		$searches = [];
		if ( class_exists( 'FW_SEO_Search_Log' ) && FW_SEO_Search_Log::enabled() ) {
			$searches = FW_SEO_Search_Log::rows( [ 'gap' => 'missing', 'limit' => 3 ] );
		}

		$stored = [
			'time'   => time(),
			'alerts' => self::build( $pages, $near, $searches, $extra, class_exists( 'FW_SEO_Freshness' ) ? FW_SEO_Freshness::stale_months() : 12 ),
		];

		update_option( self::OPTION, $stored, false );

		return $stored;
	}

	/** @return array{time: int, alerts: array} */
	public static function last() {
		$s = get_option( self::OPTION, [] );

		return [ 'time' => (int) ( $s['time'] ?? 0 ), 'alerts' => (array) ( $s['alerts'] ?? [] ) ];
	}

	/**
	 * @param int $post_id
	 *
	 * @return array
	 */
	public static function for_post( $post_id ) {
		return array_values( array_filter( self::last()['alerts'], static function ( $a ) use ( $post_id ) {
			return (int) ( $a['post_id'] ?? 0 ) === (int) $post_id && $post_id;
		} ) );
	}

	/* ------------------------------------------------------------------ *
	 * Email
	 * ------------------------------------------------------------------ */

	/**
	 * A fingerprint of what the alerts say, so the same list is not mailed twice.
	 *
	 * @param array $alerts
	 *
	 * @return string
	 */
	public static function fingerprint( array $alerts ) {
		return md5( wp_json_encode( array_map( static function ( $a ) {
			return [ $a['type'] ?? '', $a['url'] ?? '', $a['title'] ?? '' ];
		}, $alerts ) ) );
	}

	/**
	 * The weekly email, when it is switched on, there is something to say, and
	 * it is not the same as last week's.
	 *
	 * @return bool Whether an email was sent.
	 */
	public static function send_email() {
		if ( ! FW_SEO_Settings::flag( 'alerts_email', false ) ) {
			return false;
		}

		$alerts = self::last()['alerts'];
		$print  = self::fingerprint( $alerts );

		if ( ! $alerts || get_option( self::SENT ) === $print ) {
			return false;
		}

		$to = trim( (string) FW_SEO_Settings::get( 'alerts_email_to', '' ) ) ?: (string) get_option( 'admin_email' );

		$lines = [];
		foreach ( $alerts as $a ) {
			$lines[] = '• ' . $a['title'] . "\n  " . $a['message'] . ( '' !== $a['link'] ? "\n  " . $a['link'] : '' );
		}

		$sent = wp_mail(
			$to,
			sprintf(
				/* translators: %s: site name */
				__( '[%s] SEO: what needs attention this week', 'fw' ),
				wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
			),
			implode( "\n\n", $lines ) . "\n\n" . sprintf(
				/* translators: %s: settings URL */
				__( 'Sent weekly, and only when something changed. Turn it off under SEO → Alerts: %s', 'fw' ),
				admin_url( 'admin.php?page=fw-seo-settings&view=alerts_tab' )
			)
		);

		if ( $sent ) {
			update_option( self::SENT, $print, false );
		}

		return (bool) $sent;
	}

	/* ------------------------------------------------------------------ *
	 * Where they are shown
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $alerts
	 *
	 * @return string
	 */
	public static function list_html( array $alerts ) {
		$icons = [
			'connection' => 'dashicons-warning',
			'noindex'    => 'dashicons-hidden',
			'drop'       => 'dashicons-chart-line',
			'ctr'        => 'dashicons-edit',
			'stale'      => 'dashicons-clock',
			'near'       => 'dashicons-arrow-up-alt',
			'search'     => 'dashicons-search',
		];

		$html = '<ul class="fw-seo-alerts" style="margin:0">';
		foreach ( $alerts as $a ) {
			$title = esc_html( $a['title'] );
			// The edit link is made here, for the person looking: the daily run
			// has no logged-in user, and WordPress gives it no edit links.
			$link = ! empty( $a['post_id'] ) ? (string) get_edit_post_link( (int) $a['post_id'], 'raw' ) : '';
			$link = '' !== $link ? $link : (string) ( $a['link'] ?? '' );
			if ( '' !== $link ) {
				$title = '<a href="' . esc_url( $link ) . '">' . $title . '</a>';
			}
			$html .= '<li style="display:flex;gap:10px;margin:0 0 12px"><span class="dashicons ' . esc_attr( $icons[ $a['type'] ] ?? 'dashicons-flag' ) . '" style="color:#2271b1;flex:none"></span>'
				. '<span><strong>' . $title . '</strong><br>' . esc_html( $a['message'] ) . '</span></li>';
		}

		return $html . '</ul>';
	}

	/** @internal */
	public static function _dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget( 'fw_seo_alerts', __( 'SEO: what needs attention', 'fw' ), [ __CLASS__, '_dashboard_render' ] );
	}

	/** @internal */
	public static function _dashboard_render() {
		$last = self::last();

		if ( ! $last['alerts'] ) {
			echo '<p>' . esc_html( $last['time']
				? __( 'Nothing needs attention. The checks run every day.', 'fw' )
				: __( 'The first check runs within a day of the SEO extension being active.', 'fw' ) ) . '</p>';
		} else {
			echo self::list_html( $last['alerts'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in list_html().
		}

		echo '<p style="margin:12px 0 0"><a href="' . esc_url( admin_url( 'admin.php?page=fw-seo-settings&view=alerts_tab' ) ) . '">' . esc_html__( 'All alerts and settings', 'fw' ) . '</a></p>';
	}

	/**
	 * The note at the top of a post's SEO box: the moment to say "this is old
	 * and still gets visitors" is when the post is already open.
	 *
	 * @param int $post_id
	 *
	 * @return string
	 */
	public static function editor_html( $post_id ) {
		$alerts = self::for_post( $post_id );

		if ( ! $alerts ) {
			return '';
		}

		$html = '<div class="notice notice-info inline fw-seo-editor-alerts" style="margin:0 0 12px">';
		foreach ( $alerts as $a ) {
			$html .= '<p>' . esc_html( $a['message'] ) . '</p>';
		}

		return $html . '</div>';
	}

	/** @internal */
	public static function _action_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'fw' ), 403 );
		}

		check_admin_referer( 'fw_seo_alerts_refresh' );
		self::collect();

		wp_safe_redirect( admin_url( 'admin.php?page=fw-seo-settings&view=alerts_tab' ) );
		exit;
	}

	/**
	 * The full list on SEO → Alerts.
	 *
	 * @return string
	 */
	public static function report_html() {
		$last = self::last();
		$run  = wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_alerts_refresh' ), 'fw_seo_alerts_refresh' );

		$html = '<p>' . ( $last['time']
			? sprintf(
				/* translators: %s: how long ago */
				esc_html__( 'Checked %s ago. It runs by itself once a day.', 'fw' ),
				esc_html( human_time_diff( $last['time'] ) )
			)
			: esc_html__( 'Not checked yet. It runs by itself once a day.', 'fw' ) )
			. ' <a class="button" href="' . esc_url( $run ) . '">' . esc_html__( 'Check now', 'fw' ) . '</a></p>';

		if ( ! $last['alerts'] ) {
			$html .= '<p>' . esc_html__( 'Nothing needs attention.', 'fw' ) . '</p>';
		} else {
			$html .= self::list_html( $last['alerts'] );
		}

		if ( class_exists( 'FW_SEO_Console' ) && ! FW_SEO_Console::connected() ) {
			$html .= '<p class="description">' . esc_html__( 'Most alerts use Google data. Connect Search Console to get them; until then only site-search alerts can appear.', 'fw' ) . '</p>';
		}

		return $html;
	}
}
