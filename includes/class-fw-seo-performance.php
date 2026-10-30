<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Search performance — impressions, clicks, click-through and position per page,
 * and, more usefully, the change in each against the four weeks before.
 *
 * Three views of the same data: pages gaining, pages slipping, and pages people
 * see but do not choose (impressions without clicks: a title and description
 * problem, which is exactly what this extension edits). Every figure is the
 * site owner's own Search Console data.
 *
 * @since 2.0.51
 */
class FW_SEO_Performance {

	const CACHE = 'fw_seo_performance';
	const TTL   = 6 * HOUR_IN_SECONDS;

	/** Pages need this many impressions in one window before a change means anything. */
	const FLOOR = 50;

	/** Swapped for report_html() when the settings page is drawn. */
	const PLACEHOLDER = '<!--fw-seo-performance-report-->';

	/* ------------------------------------------------------------------ *
	 * Pure
	 * ------------------------------------------------------------------ */

	/**
	 * Per-page rows from two windows of page totals.
	 *
	 * @param array $now    url => {impressions, clicks, position}
	 * @param array $before same, the four weeks before.
	 *
	 * @return array[] url, clicks, impressions, ctr, position, and the change in each.
	 */
	public static function rows( array $now, array $before ) {
		$out = [];

		foreach ( array_unique( array_merge( array_keys( $now ), array_keys( $before ) ) ) as $url ) {
			$n = (array) ( $now[ $url ] ?? [] );
			$b = (array) ( $before[ $url ] ?? [] );
			$ni = (int) ( $n['impressions'] ?? 0 );
			$bi = (int) ( $b['impressions'] ?? 0 );

			if ( max( $ni, $bi ) < self::FLOOR ) {
				continue;
			}

			$nc = (int) ( $n['clicks'] ?? 0 );
			$bc = (int) ( $b['clicks'] ?? 0 );

			$out[] = [
				'url'          => (string) $url,
				'clicks'       => $nc,
				'clicks_d'     => $nc - $bc,
				'impressions'  => $ni,
				'impr_d'       => $ni - $bi,
				'ctr'          => $ni ? $nc / $ni : 0.0,
				'position'     => (float) ( $n['position'] ?? 0 ),
				// Lower is better, so a positive change means the page moved up.
				'position_d'   => isset( $n['position'], $b['position'] ) && $b['position'] && $n['position'] ? round( (float) $b['position'] - (float) $n['position'], 1 ) : null,
			];
		}

		return $out;
	}

	/**
	 * The three lists.
	 *
	 * @param array[] $rows From rows().
	 *
	 * @return array{gaining: array, slipping: array, unclicked: array}
	 */
	public static function lists( array $rows ) {
		$gaining  = array_values( array_filter( $rows, static function ( $r ) {
			return $r['clicks_d'] > 0 || $r['impr_d'] > 0;
		} ) );
		$slipping = array_values( array_filter( $rows, static function ( $r ) {
			return $r['clicks_d'] < 0 || $r['impr_d'] < 0;
		} ) );
		$unclicked = array_values( array_filter( $rows, static function ( $r ) {
			return $r['impressions'] >= 200 && $r['ctr'] < 0.01;
		} ) );

		usort( $gaining, static function ( $a, $b ) {
			return [ $b['clicks_d'], $b['impr_d'] ] <=> [ $a['clicks_d'], $a['impr_d'] ];
		} );
		usort( $slipping, static function ( $a, $b ) {
			return [ $a['clicks_d'], $a['impr_d'] ] <=> [ $b['clicks_d'], $b['impr_d'] ];
		} );
		usort( $unclicked, static function ( $a, $b ) {
			return $b['impressions'] <=> $a['impressions'];
		} );

		return [ 'gaining' => array_slice( $gaining, 0, 25 ), 'slipping' => array_slice( $slipping, 0, 25 ), 'unclicked' => array_slice( $unclicked, 0, 25 ) ];
	}

	/* ------------------------------------------------------------------ *
	 * Data
	 * ------------------------------------------------------------------ */

	/** @return array|null url => totals, null when Search Console did not answer. */
	protected static function totals( $start, $end ) {
		$result = FW_SEO_Console::query( [ 'startDate' => $start, 'endDate' => $end, 'dimensions' => [ 'page' ], 'rowLimit' => 1000 ] );
		if ( ! $result['ok'] ) {
			return null;
		}

		$out = [];
		foreach ( $result['rows'] as $row ) {
			$out[ (string) ( $row['keys'][0] ?? '' ) ] = [ 'impressions' => (int) ( $row['impressions'] ?? 0 ), 'clicks' => (int) ( $row['clicks'] ?? 0 ), 'position' => (float) ( $row['position'] ?? 0 ) ];
		}

		return $out;
	}

	/** @return array{ok: bool, rows: array, window: array, time: int} */
	public static function data( $refresh = false ) {
		$cached = $refresh ? null : get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! class_exists( 'FW_SEO_Console' ) || ! FW_SEO_Console::connected() || '' === FW_SEO_Console::property() ) {
			return [ 'ok' => false, 'rows' => [], 'window' => [], 'time' => 0 ];
		}

		$now    = FW_SEO_Console::window( 28 );
		$before = [ 'start' => gmdate( 'Y-m-d', strtotime( $now['start'] ) - 28 * DAY_IN_SECONDS ), 'end' => $now['start'] ];
		$a      = self::totals( $now['start'], $now['end'] );
		$b      = null === $a ? null : self::totals( $before['start'], $before['end'] );
		$out    = null === $a ? [ 'ok' => false, 'rows' => [], 'window' => $now, 'time' => time() ] : [ 'ok' => true, 'rows' => self::rows( $a, (array) $b ), 'window' => $now, 'time' => time() ];

		set_transient( self::CACHE, $out, $out['ok'] ? self::TTL : 10 * MINUTE_IN_SECONDS );

		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * The report
	 * ------------------------------------------------------------------ */

	protected static function delta( $n, $better_up = true, $decimals = 0 ) {
		if ( null === $n || 0.0 === (float) $n ) {
			return '<span class="description">–</span>';
		}
		$good = $better_up ? $n > 0 : $n < 0;

		return '<span style="color:' . ( $good ? '#00a32a' : '#d63638' ) . '">' . ( $n > 0 ? '+' : '' ) . esc_html( number_format_i18n( $n, $decimals ) ) . '</span>';
	}

	protected static function table( array $rows ) {
		$html = '<table class="widefat striped" style="max-width:1200px"><thead><tr><th>' . esc_html__( 'Page', 'fw' ) . '</th><th>' . esc_html__( 'Clicks', 'fw' ) . '</th><th>' . esc_html__( 'Impressions', 'fw' ) . '</th><th>' . esc_html__( 'Click-through', 'fw' ) . '</th><th>' . esc_html__( 'Position', 'fw' ) . '</th></tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$id    = (int) url_to_postid( $r['url'] );
			$label = $id ? '<a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>' : '<code>' . esc_html( (string) wp_parse_url( $r['url'], PHP_URL_PATH ) ) . '</code>';
			$html .= '<tr><td>' . $label . '</td>'
				. '<td>' . esc_html( number_format_i18n( $r['clicks'] ) ) . ' ' . self::delta( $r['clicks_d'] ) . '</td>'
				. '<td>' . esc_html( number_format_i18n( $r['impressions'] ) ) . ' ' . self::delta( $r['impr_d'] ) . '</td>'
				. '<td>' . esc_html( number_format_i18n( $r['ctr'] * 100, 1 ) ) . '%</td>'
				. '<td>' . esc_html( number_format_i18n( $r['position'], 1 ) ) . ' ' . self::delta( $r['position_d'], true, 1 ) . '</td></tr>';
		}

		return $html . '</tbody></table>';
	}

	/** @return string SEO → Search performance. */
	public static function report_html() {
		$data = self::data();

		if ( ! $data['ok'] ) {
			return '<p>' . esc_html__( 'Connect Search Console to see how each page is doing in Google, and how that changed.', 'fw' ) . ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=fw-seo-settings&view=console_tab' ) ) . '">' . esc_html__( 'Connect Search Console', 'fw' ) . '</a></p>';
		}

		$lists = self::lists( $data['rows'] );
		$html  = '<p>' . esc_html( sprintf(
			/* translators: 1: start date, 2: end date */
			__( 'The four weeks %1$s to %2$s, each figure with its change against the four weeks before. Pages with fewer than 50 impressions in both are left out: their changes are noise.', 'fw' ),
			mysql2date( get_option( 'date_format' ), $data['window']['start'] ),
			mysql2date( get_option( 'date_format' ), $data['window']['end'] )
		) ) . '</p>';

		foreach ( [
			'gaining'   => [ __( 'Gaining', 'fw' ), __( 'More clicks or impressions than the four weeks before.', 'fw' ) ],
			'slipping'  => [ __( 'Slipping', 'fw' ), __( 'Fewer clicks or impressions. Before changing a page, check Topic momentum: if interest in the topic itself fell, the page is not the problem.', 'fw' ) ],
			'unclicked' => [ __( 'Seen but not clicked', 'fw' ), __( 'At least 200 impressions and under 1 click in 100. The page ranks; its title and description do not make people choose it. Rewrite them.', 'fw' ) ],
		] as $key => $label ) {
			$html .= '<h3>' . esc_html( $label[0] ) . ' <span class="count">(' . count( $lists[ $key ] ) . ')</span></h3><p class="description">' . esc_html( $label[1] ) . '</p>';
			$html .= $lists[ $key ] ? self::table( $lists[ $key ] ) : '<p>' . esc_html__( 'None.', 'fw' ) . '</p>';
		}

		return $html;
	}
}
