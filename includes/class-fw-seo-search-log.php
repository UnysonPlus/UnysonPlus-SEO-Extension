<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * What visitors look for on this site, and what they did not find.
 *
 * WordPress throws this away. That is a waste, because it is the only keyword
 * data a site owner gets that is unambiguously about their OWN audience, costs
 * nothing, and is not an estimate. A search that returns no results is a
 * visitor saying, in their own words, that a page is missing.
 *
 * Paid keyword tools approximate this from outside with licensed clickstream
 * data. The site has the real thing and discards it on every request.
 *
 * Aggregated by term rather than logged per search: one row per phrase with a
 * counter. A row per search would grow without limit and answer no question
 * better — "forty people searched for this and found nothing" is the finding,
 * not the forty timestamps.
 */
class FW_SEO_Search_Log {

	/** Bumped when the schema changes, so dbDelta runs again. */
	const DB_VERSION = '1';

	const DB_VERSION_OPTION = 'fw_seo_search_log_db';

	/** A phrase longer than this is not a search, it is a paste. */
	const MAX_TERM = 180;

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'fw_seo_search_terms';
	}

	/**
	 * Create or update the table. Cheap to call; dbDelta no-ops when current.
	 */
	public static function install() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// `term` is indexed UNIQUE at 191 chars — the maximum a utf8mb4 index
		// allows on older MySQL — which is also why terms are truncated on the
		// way in rather than silently colliding here.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			term varchar(191) NOT NULL DEFAULT '',
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			results bigint(20) unsigned NOT NULL DEFAULT 0,
			last_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY term (term),
			KEY results_hits (results, hits),
			KEY last_seen (last_seen)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * @return bool
	 */
	public static function enabled() {
		return (bool) FW_SEO_Settings::flag( 'search_log_enabled', true );
	}

	/**
	 * Normalise a phrase so "Blue Widgets", "blue widgets " and "blue  widgets"
	 * are one row rather than three.
	 *
	 * @param string $term
	 *
	 * @return string '' when the phrase is not worth recording.
	 */
	public static function normalise( $term ) {
		$term = wp_strip_all_tags( (string) $term );
		$term = preg_replace( '/\s+/u', ' ', $term );
		$term = trim( (string) $term );

		if ( '' === $term || mb_strlen( $term ) > self::MAX_TERM ) {
			return '';
		}

		// Lowercased with mb_*, so an accented or non-Latin phrase folds the
		// same way an ASCII one does.
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $term, 'UTF-8' ) : strtolower( $term );
	}

	/**
	 * Does this user-agent string look like software rather than a person?
	 *
	 * Pure, and separate from is_human(), so it can be exercised directly — the
	 * context guards in is_human() short-circuit under WP-CLI, which otherwise
	 * makes the interesting half of this untestable.
	 *
	 * Deliberately crude: the cost of missing a bot is one noisy row, the cost
	 * of a clever check is dropping real visitors.
	 *
	 * @param string $agent
	 *
	 * @return bool
	 */
	public static function is_bot_agent( $agent ) {
		$agent = strtolower( trim( (string) $agent ) );

		if ( '' === $agent ) {
			return true;
		}

		foreach ( [ 'bot', 'crawl', 'spider', 'slurp', 'curl', 'wget', 'python-', 'headless', 'monitor', 'preview' ] as $needle ) {
			if ( false !== strpos( $agent, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Does this request look like a person searching?
	 *
	 * @return bool
	 */
	public static function is_human() {
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}

		if ( self::is_bot_agent( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) {
			return false;
		}

		/** Filters whether the current request counts as a human search. */
		return (bool) apply_filters( 'fw_seo_search_log_is_human', true );
	}

	/**
	 * Record one search.
	 *
	 * @param string $term
	 * @param int    $results
	 *
	 * @return bool Whether a row was written.
	 */
	public static function record( $term, $results ) {
		global $wpdb;

		$term = self::normalise( $term );

		if ( '' === $term ) {
			return false;
		}

		$table = self::table();

		// One statement: INSERT ... ON DUPLICATE KEY UPDATE. A read-then-write
		// would race on a site with real traffic and lose counts.
		$sql = $wpdb->prepare(
			"INSERT INTO `{$table}` (term, hits, results, last_seen) VALUES (%s, 1, %d, %s)
			 ON DUPLICATE KEY UPDATE hits = hits + 1, results = VALUES(results), last_seen = VALUES(last_seen)", // phpcs:ignore WordPress.DB
			$term,
			max( 0, (int) $results ),
			current_time( 'mysql', true )
		);

		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Forget rows nobody has searched for in a while.
	 *
	 * Retention is the whole privacy story here: search phrases are text a
	 * visitor typed, and people paste things they did not mean to. Keeping them
	 * for ever is a liability with no analytical value — a phrase last seen two
	 * years ago is not a content gap any more.
	 *
	 * @param int|null $days
	 *
	 * @return int Rows removed.
	 */
	public static function prune( $days = null ) {
		global $wpdb;

		$days = null === $days ? (int) FW_SEO_Settings::get( 'search_log_days', 90 ) : (int) $days;

		if ( $days < 1 ) {
			return 0;
		}

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE last_seen < %s", $cutoff ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @return int Rows removed.
	 */
	public static function clear() {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * The report rows.
	 *
	 * @param array $args {
	 *     @type string $gap   'missing' (no results), 'thin' (1-2), 'all'
	 *     @type int    $limit
	 * }
	 *
	 * @return array<int,object>
	 */
	public static function rows( array $args = [] ) {
		global $wpdb;

		$gap   = $args['gap'] ?? 'missing';
		$limit = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) );
		$table = self::table();

		$where = '1=1';

		if ( 'missing' === $gap ) {
			$where = 'results = 0';
		} elseif ( 'thin' === $gap ) {
			$where = 'results BETWEEN 1 AND 2';
		}

		// Ordered by how many people asked, not by recency: one person
		// searching yesterday is noise, forty over a month is a missing page.
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT term, hits, results, last_seen FROM `{$table}` WHERE {$where} ORDER BY hits DESC, last_seen DESC LIMIT %d", // phpcs:ignore WordPress.DB
			$limit
		) );
	}

	/**
	 * @return array{terms:int,searches:int,missing:int}
	 */
	public static function summary() {
		global $wpdb;

		$table = self::table();

		$row = $wpdb->get_row( "SELECT COUNT(*) AS terms, COALESCE(SUM(hits),0) AS searches, COALESCE(SUM(results = 0),0) AS missing FROM `{$table}`" ); // phpcs:ignore WordPress.DB

		return [
			'terms'    => (int) ( $row->terms ?? 0 ),
			'searches' => (int) ( $row->searches ?? 0 ),
			'missing'  => (int) ( $row->missing ?? 0 ),
		];
	}

	// -------------------------------------------------------------------------
	// Wiring
	// -------------------------------------------------------------------------

	public static function init() {
		// `wp` rather than `pre_get_posts`: by then the query has run, so the
		// result count is known — and the count is the entire point. Recording
		// before the query would log the phrase and not whether it found
		// anything.
		add_action( 'wp', [ __CLASS__, '_action_record' ], 20 );

		add_action( 'fw_seo_search_log_prune', [ __CLASS__, 'prune' ] );

		if ( is_admin() ) {
			add_action( 'admin_init', [ __CLASS__, 'install' ] );
			add_action( 'admin_post_fw_seo_search_clear', [ __CLASS__, '_action_clear' ] );

			if ( ! wp_next_scheduled( 'fw_seo_search_log_prune' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'fw_seo_search_log_prune' );
			}
		}
	}

	/**
	 * @internal
	 */
	public static function _action_record() {
		if ( is_admin() || ! is_search() || ! self::enabled() || ! self::is_human() ) {
			return;
		}

		// Only the first page of results: paging through is one search, and
		// counting each page would make a popular phrase look commoner than it is.
		if ( get_query_var( 'paged' ) > 1 ) {
			return;
		}

		global $wp_query;

		self::record( get_search_query( false ), (int) $wp_query->found_posts );
	}

	/**
	 * @internal
	 */
	public static function _action_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'fw' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'fw_seo_search_clear' );

		self::clear();

		wp_safe_redirect( add_query_arg( 'fw-seo-search', 'cleared', FW_SEO_Settings_Page::url( 'search_tab' ) ) );
		exit;
	}

	/**
	 * The report, as markup for an `html-full` option.
	 *
	 * Rendered here rather than in the settings page because the queries and
	 * their meaning live together: what counts as a gap is this class's
	 * business, not the page's.
	 *
	 * @return string
	 */
	public static function report_html() {
		$summary = self::summary();
		$missing = self::rows( [ 'gap' => 'missing', 'limit' => 25 ] );
		$thin    = self::rows( [ 'gap' => 'thin', 'limit' => 10 ] );

		ob_start();
		?>
		<div class="fw-seo-search-report">
			<?php if ( ! $summary['searches'] ) : ?>
				<p class="description">
					<?php esc_html_e( 'Nothing recorded yet. Searches appear here once visitors start using the site\'s search box — give it a few days on a live site.', 'fw' ); ?>
				</p>
			<?php else : ?>
				<p class="fw-seo-search-summary">
					<?php
					printf(
						/* translators: 1: searches, 2: distinct phrases, 3: phrases with no results. */
						esc_html__( '%1$s searches, %2$s different phrases, %3$s of which found nothing.', 'fw' ),
						'<strong>' . esc_html( number_format_i18n( $summary['searches'] ) ) . '</strong>',
						'<strong>' . esc_html( number_format_i18n( $summary['terms'] ) ) . '</strong>',
						'<strong>' . esc_html( number_format_i18n( $summary['missing'] ) ) . '</strong>'
					);
					?>
				</p>

				<h4><?php esc_html_e( 'Found nothing', 'fw' ); ?></h4>
				<?php if ( $missing ) : ?>
					<p class="description"><?php esc_html_e( 'Ranked by how many people asked. One person searching once is noise; the same phrase forty times is a page you have not written.', 'fw' ); ?></p>
					<?php echo self::table_html( $missing, true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Every phrase searched for so far found something.', 'fw' ); ?></p>
				<?php endif; ?>

				<?php if ( $thin ) : ?>
					<h4><?php esc_html_e( 'Barely found anything', 'fw' ); ?></h4>
					<p class="description"><?php esc_html_e( 'One or two results. Often a page exists but does not say the words people are using for it.', 'fw' ); ?></p>
					<?php echo self::table_html( $thin, false ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php endif; ?>

				<p>
					<a class="button"
					   href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_search_clear' ), 'fw_seo_search_clear' ) ); ?>"
					   onclick="return confirm('<?php echo esc_js( __( 'Delete every recorded search phrase?', 'fw' ) ); ?>');">
						<?php esc_html_e( 'Clear recorded searches', 'fw' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<style>
		.fw-seo-search-report h4{margin:1.6em 0 .3em;font-size:13px}
		.fw-seo-search-report .fw-seo-search-summary{margin:.2em 0 1em}
		.fw-seo-search-report table{border-collapse:collapse;max-width:46em;width:100%}
		.fw-seo-search-report th,.fw-seo-search-report td{text-align:left;padding:6px 10px;border-bottom:1px solid #e6e6ea;font-size:13px}
		.fw-seo-search-report th{font-weight:600;color:#50575e}
		.fw-seo-search-report td.num{text-align:right;width:6em;color:#50575e}
		.fw-seo-search-report .term{font-weight:500}
		</style>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * @param array $rows
	 * @param bool  $offer_page Show the "write this page" link.
	 *
	 * @return string
	 */
	protected static function table_html( array $rows, $offer_page ) {
		ob_start();
		?>
		<table>
			<thead>
				<tr>
					<th><?php esc_html_e( 'Searched for', 'fw' ); ?></th>
					<th class="num"><?php esc_html_e( 'Times', 'fw' ); ?></th>
					<th class="num"><?php esc_html_e( 'Results', 'fw' ); ?></th>
					<?php if ( $offer_page ) : ?><th></th><?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td class="term"><?php echo esc_html( $row->term ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $row->hits ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $row->results ) ); ?></td>
						<?php if ( $offer_page ) : ?>
							<td>
								<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=page&post_title=' . rawurlencode( $row->term ) ) ); ?>">
									<?php esc_html_e( 'Write this page', 'fw' ); ?>
								</a>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php

		return (string) ob_get_clean();
	}
}
