<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Which pages are going stale, and which never had much on them.
 *
 * The highest-yield editorial work on an established site is rarely writing
 * new pages; it is finding the ones that quietly stopped being true. Paid
 * platforms sell this as "content decay" reporting, built on traffic history.
 *
 * Most of the signal is available locally without any of that: when a page was
 * last touched, and how much is actually on it. Those two answer "what should I
 * look at this month" well enough to be worth having, and they get sharper when
 * Search Console lands and can say which of them used to earn traffic.
 *
 * Word count comes from FW_SEO_Content, so a page-builder page is measured by
 * the prose in its TREE rather than by a post_content field that holds rendered
 * markup — on a builder site the naive count is meaningless.
 */
class FW_SEO_Freshness {

	/** Never scan more than this in one report; a report is not a crawler. */
	const MAX_SCAN = 300;

	/**
	 * @return int Months after which a page counts as stale.
	 */
	public static function stale_months() {
		return max( 1, (int) FW_SEO_Settings::get( 'freshness_months', 18 ) );
	}

	/**
	 * @return int Word count below which a page counts as thin.
	 */
	public static function thin_words() {
		return max( 0, (int) FW_SEO_Settings::get( 'freshness_thin', 150 ) );
	}

	/**
	 * Count the words a reader would actually see.
	 *
	 * @param int $post_id
	 *
	 * @return int
	 */
	public static function words( $post_id ) {
		$ctx  = FW_SEO_Context::for_post( $post_id );
		$text = FW_SEO_Content::text( $ctx );

		if ( '' === trim( $text ) ) {
			return 0;
		}

		// str_word_count is ASCII-minded and undercounts accented or non-Latin
		// prose badly; splitting on whitespace is cruder and far more honest.
		return count( preg_split( '/\s+/u', trim( $text ) ) ?: [] );
	}

	/**
	 * The oldest-touched pages, with what we can say about each.
	 *
	 * Ordered by when they were last modified, because that is the question
	 * being asked. The word count is computed only for the rows shown — doing
	 * it for every post on the site would turn a report into a crawl.
	 *
	 * @param int $limit
	 *
	 * @return array<int,array>
	 */
	public static function rows( $limit = 20 ) {
		$limit = max( 1, min( 100, (int) $limit ) );

		$ids = get_posts( [
			'post_type'        => array_keys( FW_SEO_Locations::post_types() ),
			'post_status'      => 'publish',
			'posts_per_page'   => $limit,
			'orderby'          => 'modified',
			'order'            => 'ASC',
			'fields'           => 'ids',
			'suppress_filters' => true,
		] );

		$stale_after = self::stale_months();
		$thin_under  = self::thin_words();
		$now         = time();
		$rows        = [];

		foreach ( $ids as $post_id ) {
			$modified = (int) get_post_modified_time( 'U', true, $post_id );
			$months   = $modified ? (int) floor( ( $now - $modified ) / ( 30 * DAY_IN_SECONDS ) ) : 0;
			$words    = self::words( $post_id );

			$flags = [];

			if ( $months >= $stale_after ) {
				$flags[] = 'stale';
			}

			if ( $words < $thin_under ) {
				$flags[] = 'thin';
			}

			// A deliberately hidden page is not a problem, and saying so stops
			// the report nagging about pages somebody already decided about.
			if ( FW_SEO_Store::get_post( $post_id, 'noindex' ) ) {
				$flags[] = 'noindex';
			}

			$rows[] = [
				'id'       => $post_id,
				'title'    => get_the_title( $post_id ),
				'type'     => get_post_type( $post_id ),
				'modified' => $modified,
				'months'   => $months,
				'words'    => $words,
				'flags'    => $flags,
			];
		}

		return $rows;
	}

	/**
	 * How much of the site is stale or thin.
	 *
	 * Counted over the whole site for staleness (a date query, cheap) but NOT
	 * for thinness, which would need every post's prose parsed. Reporting a
	 * number we cannot afford to compute honestly is worse than not reporting it.
	 *
	 * @return array{total:int,stale:int,scanned:int}
	 */
	public static function summary() {
		$types = array_keys( FW_SEO_Locations::post_types() );

		$total = new WP_Query( [
			'post_type'        => $types,
			'post_status'      => 'publish',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => false,
			'suppress_filters' => true,
		] );

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::stale_months() * 30 * DAY_IN_SECONDS ) );

		$stale = new WP_Query( [
			'post_type'        => $types,
			'post_status'      => 'publish',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'no_found_rows'    => false,
			'suppress_filters' => true,
			'date_query'       => [ [ 'column' => 'post_modified_gmt', 'before' => $cutoff ] ],
		] );

		return [
			'total'   => (int) $total->found_posts,
			'stale'   => (int) $stale->found_posts,
			'scanned' => 0,
		];
	}

	/**
	 * @return string
	 */
	public static function report_html() {
		$summary = self::summary();
		$rows    = self::rows( 20 );
		$months  = self::stale_months();
		$thin    = self::thin_words();

		ob_start();
		?>
		<div class="fw-seo-fresh-report">
			<?php if ( ! $rows ) : ?>
				<p class="description"><?php esc_html_e( 'No published pages yet.', 'fw' ); ?></p>
			<?php else : ?>
				<p class="fw-seo-fresh-summary">
					<?php
					printf(
						/* translators: 1: stale count, 2: total pages, 3: months. */
						esc_html__( '%1$s of %2$s published pages have not been touched in %3$s months.', 'fw' ),
						'<strong>' . esc_html( number_format_i18n( $summary['stale'] ) ) . '</strong>',
						'<strong>' . esc_html( number_format_i18n( $summary['total'] ) ) . '</strong>',
						esc_html( number_format_i18n( $months ) )
					);
					?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Oldest first. Age and length are the two things that can be known without traffic history — they say what to look at, not what is wrong. A page can be four years old and perfectly correct.', 'fw' ); ?>
				</p>
				<table>
					<thead>
						<tr>
							<th><?php esc_html_e( 'Page', 'fw' ); ?></th>
							<th class="num"><?php esc_html_e( 'Last touched', 'fw' ); ?></th>
							<th class="num"><?php esc_html_e( 'Words', 'fw' ); ?></th>
							<th><?php esc_html_e( 'Notes', 'fw' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>"><?php echo esc_html( $row['title'] ?: __( '(no title)', 'fw' ) ); ?></a>
								</td>
								<td class="num">
									<?php
									echo $row['modified']
										? esc_html( sprintf( _n( '%s month ago', '%s months ago', $row['months'], 'fw' ), number_format_i18n( $row['months'] ) ) )
										: esc_html__( 'unknown', 'fw' );
									?>
								</td>
								<td class="num"><?php echo esc_html( number_format_i18n( $row['words'] ) ); ?></td>
								<td>
									<?php foreach ( $row['flags'] as $flag ) : ?>
										<span class="fw-seo-flag fw-seo-flag-<?php echo esc_attr( $flag ); ?>"><?php
											if ( 'stale' === $flag ) {
												esc_html_e( 'going stale', 'fw' );
											} elseif ( 'thin' === $flag ) {
												printf( esc_html__( 'under %s words', 'fw' ), esc_html( number_format_i18n( $thin ) ) );
											} else {
												esc_html_e( 'not indexed', 'fw' );
											}
										?></span>
									<?php endforeach; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<style>
		.fw-seo-fresh-report .fw-seo-fresh-summary{margin:.2em 0 .6em}
		.fw-seo-fresh-report table{border-collapse:collapse;width:100%;max-width:52em;margin-top:.8em}
		.fw-seo-fresh-report th,.fw-seo-fresh-report td{text-align:left;padding:6px 10px;border-bottom:1px solid #e6e6ea;font-size:13px;vertical-align:top}
		.fw-seo-fresh-report th{font-weight:600;color:#50575e}
		.fw-seo-fresh-report td.num,.fw-seo-fresh-report th.num{text-align:right;width:9em;color:#50575e;white-space:nowrap}
		.fw-seo-flag{display:inline-block;margin:0 4px 2px 0;padding:1px 7px;border-radius:9px;font-size:11px;background:#f0f0f1;color:#50575e}
		.fw-seo-flag-stale{background:#fcf3e6;color:#8a6116}
		.fw-seo-flag-thin{background:#f3f0fc;color:#50437a}
		.fw-seo-flag-noindex{background:#eef1f3;color:#50575e}
		</style>
		<?php

		return (string) ob_get_clean();
	}
}
