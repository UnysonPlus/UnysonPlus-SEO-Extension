<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Index coverage — what Google actually did with each page.
 *
 * Search Console's searchAnalytics endpoint answers "how did pages perform".
 * This one answers the question that comes before it, and that nothing else in
 * WordPress answers at all: **is the page indexed?** A page Google never kept
 * cannot perform, and the admin currently gives no way to tell the difference
 * between "nobody searches for this" and "it was never in the index".
 *
 * It runs on the Search Console connection that is already set up: the same
 * OAuth client, the same `webmasters.readonly` scope, no second credential and
 * no second consent screen. Only the host differs, which is why
 * `FW_SEO_Console::request()` learned to take an absolute URL.
 *
 * Two things shape the design, and both come from the quota rather than taste:
 *
 *  - **It is a background job, not a button.** Google allows 2,000 inspections a
 *    day per property. A site with 800 pages cannot be swept on a page load, so
 *    a cron sweep takes a small batch at a time, oldest first, and the screen
 *    shows what has been gathered so far rather than blocking on a fetch.
 *  - **It spends a budget it can see.** Every call is counted against a daily
 *    cap held deliberately below Google's, so a sweep cannot exhaust the quota
 *    that the rest of the day's on-demand checks need.
 *
 * @since 2.0.29
 */
class FW_SEO_Inspect {

	/** The URL Inspection endpoint. A different host from the rest of the API. */
	const ENDPOINT = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

	/** Per-post cached result. */
	const META_KEY = '_fw_seo_inspect';

	/**
	 * Calls allowed per day.
	 *
	 * Google's limit is 2,000 per property. Sitting below it leaves room for the
	 * on-demand checks someone makes while actually working, which would
	 * otherwise be refused because a sweep had eaten the lot at 3am.
	 */
	const DAILY_CAP = 1800;

	/** How many URLs one cron run inspects. */
	const BATCH = 10;

	/** A result older than this is worth taking again. */
	const STALE_DAYS = 30;

	const CRON_HOOK     = 'fw_seo_inspect_sweep';
	const BUDGET_OPTION = 'fw_seo_inspect_budget';

	/* ---------------------------------------------------------------------- *
	 * Wiring
	 * ---------------------------------------------------------------------- */

	public static function init() {
		add_action( self::CRON_HOOK, [ __CLASS__, '_cron_sweep' ] );

		if ( is_admin() ) {
			add_action( 'admin_post_fw_seo_inspect_now', [ __CLASS__, '_action_inspect_now' ] );
			add_action( 'admin_init', [ __CLASS__, '_maybe_schedule' ] );
		}
	}

	/**
	 * @internal Schedule the sweep once the connection exists, and clear it when
	 * it does not — an hourly job calling an API nobody connected is pure noise
	 * in the cron table.
	 */
	public static function _maybe_schedule() {
		$wanted = class_exists( 'FW_SEO_Console' ) && FW_SEO_Console::connected();
		$next   = wp_next_scheduled( self::CRON_HOOK );

		if ( $wanted && ! $next ) {
			wp_schedule_event( time() + ( 10 * MINUTE_IN_SECONDS ), 'hourly', self::CRON_HOOK );
		} elseif ( ! $wanted && $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}
	}

	/* ---------------------------------------------------------------------- *
	 * The daily budget
	 * ---------------------------------------------------------------------- */

	/**
	 * Calls left today.
	 *
	 * @return int
	 */
	public static function budget_left() {
		$state = (array) get_option( self::BUDGET_OPTION, [] );
		$today = gmdate( 'Y-m-d' );

		if ( ( $state['date'] ?? '' ) !== $today ) {
			return self::DAILY_CAP;
		}

		return max( 0, self::DAILY_CAP - (int) ( $state['used'] ?? 0 ) );
	}

	/**
	 * Record calls made. Resets itself when the day rolls over.
	 *
	 * @param int $n
	 */
	protected static function spend( $n = 1 ) {
		$state = (array) get_option( self::BUDGET_OPTION, [] );
		$today = gmdate( 'Y-m-d' );
		$used  = ( ( $state['date'] ?? '' ) === $today ) ? (int) ( $state['used'] ?? 0 ) : 0;

		update_option( self::BUDGET_OPTION, [ 'date' => $today, 'used' => $used + (int) $n ], false );
	}

	/* ---------------------------------------------------------------------- *
	 * Asking Google
	 * ---------------------------------------------------------------------- */

	/**
	 * Inspect one URL.
	 *
	 * @param string $url      The page to ask about.
	 * @param string $property The Search Console property. Defaults to the connected one.
	 *
	 * @return array{ok:bool,reason:string,row:array}
	 */
	public static function inspect_url( $url, $property = '' ) {
		if ( ! class_exists( 'FW_SEO_Console' ) ) {
			return [ 'ok' => false, 'reason' => 'notconnected', 'row' => [] ];
		}

		$property = '' !== $property ? $property : FW_SEO_Console::property();

		if ( '' === $property ) {
			return [ 'ok' => false, 'reason' => 'noproperty', 'row' => [] ];
		}

		if ( self::budget_left() < 1 ) {
			return [ 'ok' => false, 'reason' => 'nobudget', 'row' => [] ];
		}

		$result = FW_SEO_Console::request( self::ENDPOINT, [
			'inspectionUrl' => $url,
			'siteUrl'       => $property,
			'languageCode'  => str_replace( '_', '-', get_locale() ),
		] );

		// Spent whether or not it succeeded: a refused call still counted
		// against the quota at Google's end, so pretending otherwise would walk
		// us straight into a rate limit.
		self::spend();

		if ( ! $result['ok'] ) {
			return [ 'ok' => false, 'reason' => $result['reason'], 'row' => [] ];
		}

		return [ 'ok' => true, 'reason' => '', 'row' => self::parse( (array) $result['data'] ) ];
	}

	/**
	 * Normalise the API response into the handful of fields worth keeping.
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	public static function parse( array $data ) {
		$index = (array) ( $data['inspectionResult']['indexStatusResult'] ?? [] );
		$rich  = (array) ( $data['inspectionResult']['richResultsResult'] ?? [] );

		$user   = (string) ( $index['userCanonical'] ?? '' );
		$google = (string) ( $index['googleCanonical'] ?? '' );

		return [
			'verdict'    => (string) ( $index['verdict'] ?? '' ),
			'coverage'   => (string) ( $index['coverageState'] ?? '' ),
			'robots'     => (string) ( $index['robotsTxtState'] ?? '' ),
			'indexing'   => (string) ( $index['indexingState'] ?? '' ),
			'fetch'      => (string) ( $index['pageFetchState'] ?? '' ),
			'crawled_as' => (string) ( $index['crawledAs'] ?? '' ),
			'last_crawl' => (string) ( $index['lastCrawlTime'] ?? '' ),
			'canonical'  => [ 'user' => $user, 'google' => $google ],
			// The single most useful derived fact: Google kept a DIFFERENT page
			// as the canonical one, so this URL is reporting on somebody else.
			'canonical_mismatch' => ( '' !== $user && '' !== $google && $user !== $google ),
			'rich_results' => (string) ( $rich['verdict'] ?? '' ),
			'link'       => (string) ( $data['inspectionResult']['inspectionResultLink'] ?? '' ),
			'checked'    => time(),
		];
	}

	/**
	 * What the row means, in one word a person can sort by.
	 *
	 * Google's own verdict is about the inspection, not the outcome, so it is
	 * read alongside the coverage string rather than trusted alone.
	 *
	 * @param array $row
	 *
	 * @return string indexed|excluded|missing|unknown
	 */
	public static function state( array $row ) {
		$verdict = strtoupper( (string) ( $row['verdict'] ?? '' ) );

		if ( 'PASS' === $verdict ) {
			return 'indexed';
		}

		// No verdict means we have not heard, and that is its own answer.
		// Checked last, this would read as "excluded on purpose", because an
		// absent indexingState is not INDEXING_ALLOWED either — which would
		// tell someone a page is deliberately blocked when nothing is known
		// about it at all.
		if ( '' === $verdict ) {
			return 'unknown';
		}

		$indexing = strtoupper( (string) ( $row['indexing'] ?? '' ) );
		$robots   = strtoupper( (string) ( $row['robots'] ?? '' ) );

		// An explicit noindex, a canonical pointing elsewhere or a robots block
		// are deliberate most of the time — a different problem from a page
		// Google tried to keep and did not. Empty is not a signal either way.
		if ( ( '' !== $indexing && 'INDEXING_ALLOWED' !== $indexing )
			|| 'DISALLOWED' === $robots
			|| ! empty( $row['canonical_mismatch'] ) ) {
			return 'excluded';
		}

		return 'missing';
	}

	/* ---------------------------------------------------------------------- *
	 * Per-post storage
	 * ---------------------------------------------------------------------- */

	/**
	 * The stored result for a post, or an empty array.
	 *
	 * @param int $post_id
	 *
	 * @return array
	 */
	public static function stored( $post_id ) {
		$row = get_post_meta( (int) $post_id, self::META_KEY, true );

		return is_array( $row ) ? $row : [];
	}

	/**
	 * Inspect a post and store the result.
	 *
	 * @param int  $post_id
	 * @param bool $force Ignore a fresh stored result.
	 *
	 * @return array{ok:bool,reason:string,row:array}
	 */
	public static function inspect_post( $post_id, $force = false ) {
		$post_id = (int) $post_id;
		$stored  = self::stored( $post_id );

		if ( ! $force && $stored && ! self::is_stale( $stored ) ) {
			return [ 'ok' => true, 'reason' => 'cached', 'row' => $stored ];
		}

		$url    = get_permalink( $post_id );
		$result = $url ? self::inspect_url( $url ) : [ 'ok' => false, 'reason' => 'nourl', 'row' => [] ];

		if ( $result['ok'] ) {
			update_post_meta( $post_id, self::META_KEY, $result['row'] );
		}

		return $result;
	}

	/**
	 * @param array $row
	 *
	 * @return bool
	 */
	public static function is_stale( array $row ) {
		$checked = (int) ( $row['checked'] ?? 0 );

		return ! $checked || ( time() - $checked ) > ( self::STALE_DAYS * DAY_IN_SECONDS );
	}

	/* ---------------------------------------------------------------------- *
	 * The sweep
	 * ---------------------------------------------------------------------- */

	/**
	 * Published posts worth inspecting next — never checked first, then oldest.
	 *
	 * @param int $limit
	 *
	 * @return int[]
	 */
	public static function queue( $limit = self::BATCH ) {
		$types = class_exists( 'FW_SEO_Locations' ) ? array_keys( FW_SEO_Locations::post_types() ) : [ 'post', 'page' ];

		$never = get_posts( [
			'post_type'        => $types,
			'post_status'      => 'publish',
			'numberposts'      => $limit,
			'fields'           => 'ids',
			'orderby'          => 'modified',
			'order'            => 'DESC',
			'suppress_filters' => false,
			'meta_query'       => [ [ 'key' => self::META_KEY, 'compare' => 'NOT EXISTS' ] ],
		] );

		if ( count( $never ) >= $limit ) {
			return array_map( 'intval', $never );
		}

		// Then the stalest of the ones already seen.
		$stale = get_posts( [
			'post_type'        => $types,
			'post_status'      => 'publish',
			'numberposts'      => $limit - count( $never ),
			'fields'           => 'ids',
			'suppress_filters' => false,
			'meta_key'         => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'          => 'meta_value',
			'order'            => 'ASC',
			'post__not_in'     => $never ?: [ 0 ],
		] );

		return array_map( 'intval', array_merge( $never, $stale ) );
	}

	/**
	 * @internal cron: inspect one batch.
	 */
	public static function _cron_sweep() {
		if ( ! class_exists( 'FW_SEO_Console' ) || ! FW_SEO_Console::connected() ) {
			return;
		}

		$budget = min( self::BATCH, self::budget_left() );

		if ( $budget < 1 ) {
			return;
		}

		foreach ( self::queue( $budget ) as $post_id ) {
			$result = self::inspect_post( $post_id, true );

			// A refusal that will refuse every other URL too — stop rather than
			// burning the remaining budget discovering it nine more times.
			if ( ! $result['ok'] && in_array( $result['reason'], [ 'revoked', 'noaccess', 'ratelimited', 'nobudget', 'notconnected', 'noproperty' ], true ) ) {
				break;
			}
		}
	}

	/**
	 * @internal admin-post: check one page now, from the report.
	 */
	public static function _action_inspect_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'fw' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'fw_seo_inspect_now' );

		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

		if ( $post_id ) {
			self::inspect_post( $post_id, true );
		}

		wp_safe_redirect( FW_SEO_Settings_Page::url( 'coverage_tab' ) );
		exit;
	}

	/* ---------------------------------------------------------------------- *
	 * Reading what was gathered
	 * ---------------------------------------------------------------------- */

	/**
	 * Counts by state across everything inspected so far.
	 *
	 * @return array
	 */
	public static function summary() {
		$rows  = self::rows( 0 );
		$total = self::publishable();
		$by    = [ 'indexed' => 0, 'excluded' => 0, 'missing' => 0, 'unknown' => 0 ];

		foreach ( $rows as $row ) {
			$by[ $row['state'] ] = ( $by[ $row['state'] ] ?? 0 ) + 1;
		}

		return [
			'total'     => $total,
			'checked'   => count( $rows ),
			'by_state'  => $by,
			'mismatch'  => count( array_filter( $rows, static function ( $r ) {
				return ! empty( $r['canonical_mismatch'] );
			} ) ),
			'budget'    => self::budget_left(),
			'scheduled' => (bool) wp_next_scheduled( self::CRON_HOOK ),
		];
	}

	/**
	 * How many published pages there are to cover.
	 *
	 * @return int
	 */
	public static function publishable() {
		$types = class_exists( 'FW_SEO_Locations' ) ? array_keys( FW_SEO_Locations::post_types() ) : [ 'post', 'page' ];
		$total = 0;

		foreach ( $types as $type ) {
			$counts = wp_count_posts( $type );
			$total += (int) ( $counts->publish ?? 0 );
		}

		return $total;
	}

	/**
	 * Inspected pages, problems first.
	 *
	 * @param int $limit 0 for all.
	 *
	 * @return array
	 */
	public static function rows( $limit = 50 ) {
		$types = class_exists( 'FW_SEO_Locations' ) ? array_keys( FW_SEO_Locations::post_types() ) : [ 'post', 'page' ];

		$ids = get_posts( [
			'post_type'        => $types,
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => false,
			'meta_key'         => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery
		] );

		$rows = [];

		foreach ( $ids as $id ) {
			$row = self::stored( $id );

			if ( ! $row ) {
				continue;
			}

			$row['post_id'] = (int) $id;
			$row['title']   = get_the_title( $id );
			$row['state']   = self::state( $row );
			$rows[]         = $row;
		}

		// Anything that is not plainly indexed first: that is what the screen is for.
		usort( $rows, static function ( $a, $b ) {
			$rank = [ 'missing' => 0, 'excluded' => 1, 'unknown' => 2, 'indexed' => 3 ];
			$ra   = $rank[ $a['state'] ] ?? 9;
			$rb   = $rank[ $b['state'] ] ?? 9;

			return $ra === $rb ? strcasecmp( $a['title'], $b['title'] ) : $ra <=> $rb;
		} );

		return $limit > 0 ? array_slice( $rows, 0, $limit ) : $rows;
	}

	/* ---------------------------------------------------------------------- *
	 * The report
	 * ---------------------------------------------------------------------- */

	/**
	 * Human labels for the four states.
	 *
	 * @return array
	 */
	public static function states() {
		return [
			'missing'  => __( 'Not indexed', 'fw' ),
			'excluded' => __( 'Excluded on purpose', 'fw' ),
			'unknown'  => __( 'Not known yet', 'fw' ),
			'indexed'  => __( 'Indexed', 'fw' ),
		];
	}

	/**
	 * @return string
	 */
	public static function report_html() {
		if ( ! class_exists( 'FW_SEO_Console' ) || ! FW_SEO_Console::connected() ) {
			ob_start();
			?>
			<p class="description">
				<?php esc_html_e( 'Connect Search Console to see whether Google is actually indexing your pages. It uses the connection on this screen — there is nothing further to set up.', 'fw' ); ?>
			</p>
			<?php
			return (string) ob_get_clean();
		}

		$summary = self::summary();
		$rows    = self::rows( 50 );
		$states  = self::states();

		ob_start();
		?>
		<div class="fw-seo-coverage">
			<?php if ( ! $summary['checked'] ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: pages on the site, 2: pages per hour. */
						esc_html__( 'Nothing checked yet. %1$s published pages will be worked through in the background, about %2$s an hour, starting within the hour.', 'fw' ),
						esc_html( number_format_i18n( $summary['total'] ) ),
						esc_html( number_format_i18n( self::BATCH ) )
					);
					?>
				</p>
			<?php else : ?>
				<div class="totals">
					<div>
						<strong><?php echo esc_html( number_format_i18n( $summary['by_state']['indexed'] ) ); ?></strong>
						<span><?php esc_html_e( 'Indexed', 'fw' ); ?></span>
					</div>
					<div>
						<strong><?php echo esc_html( number_format_i18n( $summary['by_state']['missing'] ) ); ?></strong>
						<span><?php esc_html_e( 'Not indexed', 'fw' ); ?></span>
					</div>
					<div>
						<strong><?php echo esc_html( number_format_i18n( $summary['mismatch'] ) ); ?></strong>
						<span><?php esc_html_e( 'Google chose another canonical', 'fw' ); ?></span>
					</div>
				</div>

				<p class="description">
					<?php
					printf(
						/* translators: 1: pages checked, 2: pages published. */
						esc_html__( '%1$s of %2$s published pages checked so far.', 'fw' ),
						esc_html( number_format_i18n( $summary['checked'] ) ),
						esc_html( number_format_i18n( $summary['total'] ) )
					);
					?>
					<?php
					printf(
						/* translators: %s: number of checks. */
						esc_html__( '%s checks left today — Google allows a fixed number per day, so the rest continue tomorrow.', 'fw' ),
						esc_html( number_format_i18n( $summary['budget'] ) )
					);
					?>
				</p>

				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Page', 'fw' ); ?></th>
							<th style="width:11em"><?php esc_html_e( 'State', 'fw' ); ?></th>
							<th><?php esc_html_e( 'What Google says', 'fw' ); ?></th>
							<th style="width:9em"><?php esc_html_e( 'Last crawled', 'fw' ); ?></th>
							<th style="width:7em"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( (string) get_edit_post_link( $row['post_id'] ) ); ?>"><?php echo esc_html( $row['title'] ); ?></a>
								</td>
								<td>
									<span class="fw-seo-flag fw-seo-flag-<?php echo esc_attr( $row['state'] ); ?>">
										<?php echo esc_html( $states[ $row['state'] ] ?? $row['state'] ); ?>
									</span>
								</td>
								<td>
									<?php echo esc_html( $row['coverage'] ); ?>
									<?php if ( ! empty( $row['canonical_mismatch'] ) ) : ?>
										<br /><small><?php
											printf(
												/* translators: %s: the URL Google treats as canonical. */
												esc_html__( 'Google treats %s as the original instead.', 'fw' ),
												esc_html( $row['canonical']['google'] )
											);
										?></small>
									<?php endif; ?>
								</td>
								<td>
									<?php
									$crawl = strtotime( (string) $row['last_crawl'] );
									echo $crawl ? esc_html( wp_date( get_option( 'date_format' ), $crawl ) ) : '&mdash;';
									?>
								</td>
								<td>
									<a class="button button-small" href="<?php
										echo esc_url( wp_nonce_url(
											admin_url( 'admin-post.php?action=fw_seo_inspect_now&post=' . (int) $row['post_id'] ),
											'fw_seo_inspect_now'
										) );
									?>"><?php esc_html_e( 'Re-check', 'fw' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( ! $summary['scheduled'] ) : ?>
				<div class="notice notice-warning inline" style="margin:1em 0 0"><p>
					<?php esc_html_e( 'The background check is not scheduled. It schedules itself on the next admin page load once Search Console is connected.', 'fw' ); ?>
				</p></div>
			<?php endif; ?>
		</div>

		<style>
		.fw-seo-coverage .totals{display:flex;gap:2.4em;margin:.2em 0 1em}
		.fw-seo-coverage .totals strong{display:block;font-size:22px;line-height:1.2;color:#1d2327}
		.fw-seo-coverage .totals span{font-size:12px;color:#646970}
		.fw-seo-coverage table{margin-top:.9em;max-width:62em}
		.fw-seo-coverage td,.fw-seo-coverage th{vertical-align:top}
		.fw-seo-coverage td:nth-child(4){white-space:nowrap}
		.fw-seo-coverage small{color:#646970}
		.fw-seo-flag{display:inline-block;padding:1px 7px;border-radius:9px;font-size:11px;background:#f0f0f1;color:#50575e}
		.fw-seo-flag-indexed{background:#eaf6ee;color:#1d6336}
		.fw-seo-flag-missing{background:#fcecec;color:#8a1f1f}
		.fw-seo-flag-excluded{background:#eef1f3;color:#50575e}
		.fw-seo-flag-unknown{background:#f6f3ea;color:#6b5a16}
		</style>
		<?php

		return (string) ob_get_clean();
	}
}
