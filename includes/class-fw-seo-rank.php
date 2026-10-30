<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Where your pages rank — read from Search Console, not scraped.
 *
 * Search Console already returns an average position for every query that
 * brought impressions; the shipped report fetched it and showed only clicks
 * and impressions. This class turns it into two things worth looking at:
 *
 *  - **Almost on page one**: query + page pairs sitting at average position
 *    8–15. Close enough to the first page that a small improvement pays, far
 *    enough down to be invisible — and nothing else in the admin points at them.
 *  - **Per page, in the editor**: which searches this page appears for, where,
 *    and whether that position moved since the previous four weeks.
 *
 * What it can never be is a rank tracker. The position is an average across
 * devices, places and personalisation, and a query the site has never appeared
 * for has no impressions and therefore no row at all.
 *
 * @since 2.0.33
 */
class FW_SEO_Rank {

	/** The band worth working on. */
	const BAND_FROM = 8.0;
	const BAND_TO   = 15.0;

	/**
	 * Impressions below which an average position is too thin to act on. A
	 * "position 9" made of four impressions is four people on one day.
	 */
	const MIN_IMPRESSIONS = 30;

	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/* ---------------------------------------------------------------------- *
	 * Pure shaping — tested without a connection
	 * ---------------------------------------------------------------------- */

	/**
	 * Keep the rows in the 8–15 band with enough impressions to mean something,
	 * most impressions first.
	 *
	 * @param array $rows Search Console rows with keys [page, query].
	 *
	 * @return array
	 */
	public static function near_page_one_rows( array $rows ) {
		$out = [];

		foreach ( $rows as $row ) {
			$position    = (float) ( $row['position'] ?? 0 );
			$impressions = (int) ( $row['impressions'] ?? 0 );

			if ( $impressions < self::MIN_IMPRESSIONS || $position < self::BAND_FROM || $position > self::BAND_TO ) {
				continue;
			}

			$out[] = [
				'page'        => (string) ( $row['keys'][0] ?? '' ),
				'query'       => (string) ( $row['keys'][1] ?? '' ),
				'position'    => round( $position, 1 ),
				'impressions' => $impressions,
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
			];
		}

		usort( $out, static function ( $a, $b ) {
			return $b['impressions'] <=> $a['impressions'];
		} );

		return $out;
	}

	/**
	 * Join this window's query rows with the previous window's, so each query
	 * carries how its position moved.
	 *
	 * `change` is positive when the page moved UP (a smaller number is a better
	 * position), null when the query is new this window.
	 *
	 * @param array $now  Rows with keys [query].
	 * @param array $then Rows with keys [query].
	 *
	 * @return array
	 */
	public static function merge_windows( array $now, array $then ) {
		$before = [];

		foreach ( $then as $row ) {
			$before[ (string) ( $row['keys'][0] ?? '' ) ] = (float) ( $row['position'] ?? 0 );
		}

		$out = [];

		foreach ( $now as $row ) {
			$query    = (string) ( $row['keys'][0] ?? '' );
			$position = (float) ( $row['position'] ?? 0 );

			$out[] = [
				'query'       => $query,
				'position'    => round( $position, 1 ),
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'change'      => isset( $before[ $query ] ) ? round( $before[ $query ] - $position, 1 ) : null,
			];
		}

		usort( $out, static function ( $a, $b ) {
			return $b['impressions'] <=> $a['impressions'];
		} );

		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 * Fetching
	 * ---------------------------------------------------------------------- */

	/**
	 * @return bool
	 */
	protected static function ready() {
		return class_exists( 'FW_SEO_Console' ) && FW_SEO_Console::connected();
	}

	/**
	 * Query + page pairs at position 8–15, cached.
	 *
	 * @return array{ok:bool,reason:string,rows:array}
	 */
	public static function near_page_one() {
		if ( ! self::ready() ) {
			return [ 'ok' => false, 'reason' => 'notconnected', 'rows' => [] ];
		}

		$key    = 'fw_seo_rank_near';
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$window = FW_SEO_Console::window( 28 );
		$result = FW_SEO_Console::query( [
			'startDate'  => $window['start'],
			'endDate'    => $window['end'],
			'dimensions' => [ 'page', 'query' ],
			'rowLimit'   => 1000,
		] );

		$out = $result['ok']
			? [ 'ok' => true, 'reason' => '', 'rows' => self::near_page_one_rows( $result['rows'] ) ]
			: [ 'ok' => false, 'reason' => $result['reason'], 'rows' => [] ];

		// Failures are cached briefly, so a broken connection does not turn every
		// admin page view into another failing request.
		set_transient( $key, $out, $out['ok'] ? self::CACHE_TTL : 10 * MINUTE_IN_SECONDS );

		return $out;
	}

	/**
	 * The searches one URL appears for, with movement against the previous window.
	 *
	 * @param string $url
	 *
	 * @return array{ok:bool,reason:string,rows:array}
	 */
	public static function page_queries( $url ) {
		if ( ! self::ready() || '' === (string) $url ) {
			return [ 'ok' => false, 'reason' => 'notconnected', 'rows' => [] ];
		}

		$key    = 'fw_seo_rank_page_' . md5( (string) $url );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$now  = FW_SEO_Console::window( 28 );
		$then = [
			'start' => gmdate( 'Y-m-d', strtotime( $now['start'] ) - ( 28 * DAY_IN_SECONDS ) ),
			'end'   => $now['start'],
		];

		$body = static function ( $window ) use ( $url ) {
			return [
				'startDate'             => $window['start'],
				'endDate'               => $window['end'],
				'dimensions'            => [ 'query' ],
				'rowLimit'              => 50,
				'dimensionFilterGroups' => [ [
					'filters' => [ [ 'dimension' => 'page', 'operator' => 'equals', 'expression' => (string) $url ] ],
				] ],
			];
		};

		$a = FW_SEO_Console::query( $body( $now ) );

		if ( ! $a['ok'] ) {
			$out = [ 'ok' => false, 'reason' => $a['reason'], 'rows' => [] ];
			set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );

			return $out;
		}

		$b   = FW_SEO_Console::query( $body( $then ) );
		$out = [ 'ok' => true, 'reason' => '', 'rows' => self::merge_windows( $a['rows'], $b['ok'] ? $b['rows'] : [] ) ];

		set_transient( $key, $out, self::CACHE_TTL );

		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 * Rendering
	 * ---------------------------------------------------------------------- */

	/**
	 * @param float|null $change
	 *
	 * @return string
	 */
	public static function change_html( $change ) {
		if ( null === $change ) {
			return '<span class="fw-seo-rank-new">' . esc_html__( 'new', 'fw' ) . '</span>';
		}

		if ( abs( $change ) < 0.5 ) {
			return '<span class="fw-seo-rank-flat">&ndash;</span>';
		}

		return $change > 0
			? '<span class="fw-seo-rank-up">&uarr; ' . esc_html( number_format_i18n( $change, 1 ) ) . '</span>'
			: '<span class="fw-seo-rank-down">&darr; ' . esc_html( number_format_i18n( abs( $change ), 1 ) ) . '</span>';
	}

	/**
	 * @return string
	 */
	protected static function css() {
		static $done = false;

		if ( $done ) {
			return '';
		}

		$done = true;

		return '<style>'
			. '.fw-seo-rank table{margin-top:.6em;max-width:62em}'
			. '.fw-seo-rank td,.fw-seo-rank th{vertical-align:top}'
			. '.fw-seo-rank td:first-child{min-width:14em}'
			. '.fw-seo-rank .num{text-align:right;white-space:nowrap;width:7em}'
			. '.fw-seo-rank-up{color:#1d6336}.fw-seo-rank-down{color:#8a1f1f}'
			. '.fw-seo-rank-flat,.fw-seo-rank-new{color:#646970}'
			. '.fw-seo-rank-editor{margin:0 0 10px}'
			. '.fw-seo-rank-editor summary{cursor:pointer;font-weight:600}'
			. '</style>';
	}

	/**
	 * The "Almost on page one" report.
	 *
	 * @return string
	 */
	public static function report_html() {
		if ( ! self::ready() ) {
			return '<p class="description">' . esc_html__( 'Connect Search Console to see where your pages rank. It uses the connection on the Search Console screen — there is nothing further to set up.', 'fw' ) . '</p>';
		}

		$data = self::near_page_one();

		ob_start();
		echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
		?>
		<div class="fw-seo-rank">
			<?php if ( ! $data['ok'] ) : ?>
				<div class="notice notice-warning inline" style="margin:0"><p>
					<?php esc_html_e( 'Search Console did not answer just now. The Search Console screen says why; this view tries again in a few minutes.', 'fw' ); ?>
				</p></div>
			<?php elseif ( ! $data['rows'] ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: minimum impressions. */
						esc_html__( 'No search sits at positions 8 to 15 with at least %s impressions over the last four weeks. On a young site that is the usual answer — it fills in as traffic grows.', 'fw' ),
						esc_html( number_format_i18n( self::MIN_IMPRESSIONS ) )
					);
					?>
				</p>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'Searches where a page sits just below the first page of results, with the most-seen first. A clearer title, a section that answers the search directly, or a link from a related page is often enough to move these.', 'fw' ); ?>
				</p>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Search', 'fw' ); ?></th>
							<th><?php esc_html_e( 'Page', 'fw' ); ?></th>
							<th class="num"><?php esc_html_e( 'Position', 'fw' ); ?></th>
							<th class="num"><?php esc_html_e( 'Impressions', 'fw' ); ?></th>
							<th class="num"><?php esc_html_e( 'Clicks', 'fw' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $data['rows'], 0, 50 ) as $row ) :
							$id = url_to_postid( $row['page'] );
							?>
							<tr>
								<td><?php echo esc_html( $row['query'] ); ?></td>
								<td>
									<?php if ( $id ) : ?>
										<a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $row['page'] ); ?>
									<?php endif; ?>
								</td>
								<td class="num"><?php echo esc_html( number_format_i18n( $row['position'], 1 ) ); ?></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $row['impressions'] ) ); ?></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $row['clicks'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p class="description" style="margin-top:1em">
				<?php esc_html_e( 'Positions are Google\'s average across everyone who saw the result, so they are a trend rather than your rank on any one screen. A search the site has never appeared for has no row here.', 'fw' ); ?>
			</p>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * The collapsed "searches this page appears for" block in the editor.
	 *
	 * @param int $post_id
	 *
	 * @return string '' when there is nothing to show.
	 */
	public static function editor_html( $post_id ) {
		if ( ! self::ready() || 'publish' !== get_post_status( $post_id ) ) {
			return '';
		}

		$data = self::page_queries( (string) get_permalink( $post_id ) );

		if ( ! $data['ok'] || ! $data['rows'] ) {
			return '';
		}

		ob_start();
		echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
		?>
		<details class="fw-seo-rank fw-seo-rank-editor">
			<summary>
				<?php
				printf(
					/* translators: %s: number of searches. */
					esc_html( _n( 'Appears in Google for %s search', 'Appears in Google for %s searches', count( $data['rows'] ), 'fw' ) ),
					esc_html( number_format_i18n( count( $data['rows'] ) ) )
				);
				?>
			</summary>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Search', 'fw' ); ?></th>
						<th class="num"><?php esc_html_e( 'Position', 'fw' ); ?></th>
						<th class="num"><?php esc_html_e( 'Change', 'fw' ); ?></th>
						<th class="num"><?php esc_html_e( 'Impressions', 'fw' ); ?></th>
						<th class="num"><?php esc_html_e( 'Clicks', 'fw' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $data['rows'], 0, 10 ) as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['query'] ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $row['position'], 1 ) ); ?></td>
							<td class="num"><?php echo self::change_html( $row['change'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in change_html(). ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $row['impressions'] ) ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $row['clicks'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<?php

		return (string) ob_get_clean();
	}
}
