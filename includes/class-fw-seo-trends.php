<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Google Trends import, and topic momentum.
 *
 * Trends has no API, but every chart exports a CSV. Those files are read here
 * ("Interest over time" and "Related queries"), each stored with the terms,
 * country and date range it was taken under, because two exports made under
 * different settings are not comparable and treating them as if they were
 * would be worse than not storing them. Trends never reports search volume:
 * its numbers are relative to the peak of whatever was asked.
 *
 * Topic momentum joins those terms with what the site already knows: Search
 * Console impressions for matching searches, a page that targets the term
 * (its title or focus keyphrase), and the site-search log. Each row lands in
 * one of the roadmap's cases, with the one thing to do about it, including
 * "nothing": a page whose topic is fading is not a page to repair.
 *
 * Every rule is deterministic. The AI companion, when on, is only offered for
 * turning a chosen row into a draft title and outline (FW_SEO_AI).
 *
 * @since 2.0.51
 */
class FW_SEO_Trends {

	const OPTION   = 'fw_seo_trends';
	const QUERIES  = 'fw_seo_trends_queries';
	const NONCE    = 'fw_seo_trends';
	const NOTICE   = 'fw_seo_trends_notice_';
	const CHANGE   = 0.2;

	/** Swapped for the screens when the settings page is drawn. */
	const PLACEHOLDER = '<!--fw-seo-momentum-report-->';

	public static function init() {
		foreach ( [ 'upload', 'delete', 'export' ] as $a ) {
			add_action( 'admin_post_fw_seo_trends_' . $a, [ __CLASS__, '_action_' . $a ] );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Reading Trends' CSVs — pure
	 * ------------------------------------------------------------------ */

	/** "sourdough: (United Kingdom)" → [ 'sourdough', 'United Kingdom' ]. */
	public static function split_term( $cell ) {
		$cell = trim( (string) $cell );
		if ( preg_match( '#^(.*?):\s*\((.*)\)\s*$#u', $cell, $m ) ) {
			return [ trim( $m[1] ), trim( $m[2] ) ];
		}

		return [ $cell, '' ];
	}

	/** A Trends value: "45" → 45, "<1" → 0.5, "Breakout" → 5000, "+250%" → 250. */
	public static function value( $cell ) {
		$cell = trim( (string) $cell );
		if ( '<1' === $cell ) {
			return 0.5;
		}
		if ( 0 === strcasecmp( $cell, 'Breakout' ) ) {
			return 5000.0;
		}

		return (float) preg_replace( '#[^\d.]#', '', $cell );
	}

	/**
	 * Parse one exported file.
	 *
	 * @param string $csv
	 * @param string $filename Used to tell the kinds apart when the content is ambiguous.
	 *
	 * @return array|WP_Error {kind: timeline|related, terms, geo, from, to, series|top|rising}
	 */
	public static function parse( $csv, $filename = '' ) {
		$csv   = preg_replace( '#^\xEF\xBB\xBF#', '', (string) $csv );
		$lines = preg_split( "#\r\n|\n|\r#", $csv );

		// Interest over time: a header whose first cell is the period.
		foreach ( $lines as $i => $line ) {
			$cells = str_getcsv( $line );
			if ( count( $cells ) >= 2 && preg_match( '#^(day|week|month|time|date)$#i', trim( $cells[0] ) ) ) {
				$terms = $geo = [];
				foreach ( array_slice( $cells, 1 ) as $c ) {
					[ $t, $g ] = self::split_term( $c );
					$terms[]   = $t;
					$geo[]     = $g;
				}
				$series = array_fill_keys( $terms, [] );
				foreach ( array_slice( $lines, $i + 1 ) as $row ) {
					$r = str_getcsv( $row );
					if ( count( $r ) < 2 || ! preg_match( '#^\d{4}-\d{2}(-\d{2})?#', trim( $r[0] ) ) ) {
						continue;
					}
					foreach ( $terms as $k => $t ) {
						$series[ $t ][] = [ substr( trim( $r[0] ), 0, 10 ), self::value( $r[ $k + 1 ] ?? '0' ) ];
					}
				}
				$dates = array_column( reset( $series ) ?: [], 0 );
				if ( ! $dates ) {
					return new WP_Error( 'empty', __( 'The file has a header but no data rows.', 'fw' ) );
				}

				return [
					'kind'   => 'timeline',
					'terms'  => $terms,
					'geo'    => (string) ( array_values( array_filter( $geo ) )[0] ?? '' ),
					'from'   => $dates[0],
					'to'     => end( $dates ),
					'series' => $series,
				];
			}
		}

		// Related queries: TOP and RISING blocks of "query,value".
		$block = '';
		$top   = $rising = [];
		foreach ( $lines as $line ) {
			$t = trim( $line );
			if ( preg_match( '#^(TOP|RISING)$#i', $t ) ) {
				$block = strtolower( $t );
				continue;
			}
			if ( '' === $t || '' === $block ) {
				continue;
			}
			$c = str_getcsv( $t );
			if ( count( $c ) >= 2 && '' !== trim( $c[0] ) ) {
				if ( 'top' === $block ) {
					$top[] = [ trim( $c[0] ), self::value( $c[1] ) ];
				} else {
					$rising[] = [ trim( $c[0] ), self::value( $c[1] ) ];
				}
			}
		}

		if ( $top || $rising ) {
			// The related-queries file does not name the term it relates to; the filename sometimes does.
			$term = preg_match( '#related[_ ]?queries[_ -]*(.+?)\.csv$#i', (string) $filename, $m ) ? trim( str_replace( [ '_', '-' ], ' ', $m[1] ) ) : '';

			return [ 'kind' => 'related', 'terms' => '' !== $term ? [ $term ] : [], 'geo' => '', 'from' => '', 'to' => '', 'top' => $top, 'rising' => $rising ];
		}

		return new WP_Error( 'format', __( 'This does not look like a Google Trends export. Use the download button on the "Interest over time" or "Related queries" chart.', 'fw' ) );
	}

	/**
	 * How a term is moving.
	 *
	 * The last quarter of the series against the same months a year earlier when
	 * the export covers that far (which takes the season out), otherwise against
	 * the quarter before.
	 *
	 * @param array $points [ [date, value], … ] oldest first.
	 *
	 * @return array{direction: string, change: float, basis: string, peak_month: string}
	 */
	public static function momentum( array $points ) {
		$n = count( $points );
		if ( $n < 8 ) {
			return [ 'direction' => 'unknown', 'change' => 0.0, 'basis' => '', 'peak_month' => '' ];
		}

		$weekly = $n > 60 || ( $n >= 2 && strtotime( $points[1][0] ) - strtotime( $points[0][0] ) <= 8 * DAY_IN_SECONDS );
		$q      = max( 2, (int) round( $n / ( $weekly && $n >= 100 ? 8 : 4 ) ) );
		$q      = $weekly ? min( 13, $q ) : $q;
		$avg    = static function ( $slice ) {
			$v = array_column( $slice, 1 );

			return $v ? array_sum( $v ) / count( $v ) : 0.0;
		};
		$recent = $avg( array_slice( $points, -$q ) );

		$per_year = $weekly ? 52 : 12;
		if ( $n >= $per_year + $q ) {
			$base  = $avg( array_slice( $points, -$per_year - $q, $q ) );
			$basis = 'year';
		} else {
			$base  = $avg( array_slice( $points, -2 * $q, $q ) );
			$basis = 'previous';
		}

		$change = $base > 0 ? ( $recent - $base ) / $base : ( $recent > 0 ? 1.0 : 0.0 );

		$months = [];
		foreach ( $points as $p ) {
			$months[ (int) substr( $p[0], 5, 2 ) ][] = $p[1];
		}
		$peak = 0;
		$best = -1;
		foreach ( $months as $m => $vals ) {
			$a = array_sum( $vals ) / count( $vals );
			if ( $a > $best ) {
				$best = $a;
				$peak = $m;
			}
		}

		return [
			'direction'  => $change >= self::CHANGE ? 'rising' : ( $change <= -self::CHANGE ? 'falling' : 'steady' ),
			'change'     => round( $change, 2 ),
			'basis'      => $basis,
			'peak_month' => $peak ? date_i18n( 'F', mktime( 0, 0, 0, $peak, 1 ) ) : '',
		];
	}

	/**
	 * Which case a topic is in, and what to do.
	 *
	 * @return array{case: string, action: string, rank: int}
	 */
	public static function classify( $direction, $impressions, $has_page ) {
		if ( 'rising' === $direction && $impressions > 0 && $has_page ) {
			return [ 'case' => 'refresh', 'rank' => 0, 'action' => __( 'Rising, and your page is already being seen. Refresh it before the peak: the most valuable row here.', 'fw' ) ];
		}
		if ( 'rising' === $direction && $impressions > 0 ) {
			return [ 'case' => 'write', 'rank' => 1, 'action' => __( 'Rising, and you already get impressions for it without a page about it. Write the page you are half-ranking for.', 'fw' ) ];
		}
		if ( 'rising' === $direction && ! $has_page ) {
			return [ 'case' => 'gap', 'rank' => 2, 'action' => __( 'Rising, and nothing on the site covers it. A candidate for the calendar.', 'fw' ) ];
		}
		if ( 'rising' === $direction ) {
			return [ 'case' => 'unseen', 'rank' => 3, 'action' => __( 'Rising and you have a page, but it is not being seen for it yet. Strengthen it: link to it from related pages, answer the search more directly.', 'fw' ) ];
		}
		if ( 'falling' === $direction && $has_page ) {
			return [ 'case' => 'moved', 'rank' => 5, 'action' => __( 'Interest in the topic itself is falling. If the page lost traffic, the market moved, not the page: do not spend a week "fixing" it.', 'fw' ) ];
		}

		return [ 'case' => 'steady', 'rank' => 4, 'action' => '' ];
	}

	/* ------------------------------------------------------------------ *
	 * Storage
	 * ------------------------------------------------------------------ */

	/** @return array[] Stored imports, newest first. */
	public static function imports() {
		return array_values( (array) get_option( self::OPTION, [] ) );
	}

	/** @return array|WP_Error The stored import. */
	public static function add( array $parsed, $file = '' ) {
		$imports = (array) get_option( self::OPTION, [] );
		$item    = $parsed + [ 'id' => wp_generate_password( 8, false ), 'file' => sanitize_file_name( (string) $file ), 'imported' => time() ];
		array_unshift( $imports, $item );
		update_option( self::OPTION, array_slice( $imports, 0, 50 ), false );

		return $item;
	}

	/* ------------------------------------------------------------------ *
	 * Topic momentum
	 * ------------------------------------------------------------------ */

	/** @return array query => impressions (last 28 days), cached 6 hours; [] when not connected. */
	public static function console_queries() {
		$cached = get_transient( self::QUERIES );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( ! class_exists( 'FW_SEO_Console' ) || ! FW_SEO_Console::connected() || '' === FW_SEO_Console::property() ) {
			return [];
		}

		$w   = FW_SEO_Console::window( 28 );
		$r   = FW_SEO_Console::query( [ 'startDate' => $w['start'], 'endDate' => $w['end'], 'dimensions' => [ 'query' ], 'rowLimit' => 1000 ] );
		$out = [];
		foreach ( (array) ( $r['rows'] ?? [] ) as $row ) {
			$out[ (string) ( $row['keys'][0] ?? '' ) ] = (int) ( $row['impressions'] ?? 0 );
		}
		set_transient( self::QUERIES, $out, ! empty( $r['ok'] ) ? 6 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );

		return $out;
	}

	/** @return int|null The published page that targets a term: title or focus keyphrase. */
	public static function page_for( $term ) {
		global $wpdb;

		$like  = '%' . $wpdb->esc_like( $term ) . '%';
		$types = array_keys( FW_SEO_Locations::post_types() );
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$ids   = $types ? $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_fw_seo_focus'
			 WHERE p.post_status = 'publish' AND p.post_type IN ($in) AND ( p.post_title LIKE %s OR m.meta_value LIKE %s ) LIMIT 20", // phpcs:ignore WordPress.DB
			array_merge( $types, [ $like, $like ] )
		) ) : [];

		foreach ( $ids as $id ) {
			if ( FW_SEO_Analysis::has_phrase( get_the_title( $id ), $term ) || FW_SEO_Analysis::has_phrase( (string) get_post_meta( $id, '_fw_seo_focus', true ), $term ) ) {
				return (int) $id;
			}
		}

		return null;
	}

	/** @return int Site-search hits for the term (either containing the other). */
	public static function search_hits( $term ) {
		if ( ! class_exists( 'FW_SEO_Search_Log' ) || ! FW_SEO_Search_Log::enabled() ) {
			return 0;
		}

		$hits = 0;
		foreach ( FW_SEO_Search_Log::rows( [ 'gap' => 'all', 'limit' => 200 ] ) as $row ) {
			$q = (string) $row->term;
			if ( FW_SEO_Analysis::has_phrase( $q, $term ) || FW_SEO_Analysis::has_phrase( $term, $q ) ) {
				$hits += (int) $row->hits;
			}
		}

		return $hits;
	}

	/**
	 * The momentum rows: every term from the imports, joined with the site.
	 *
	 * @return array[]
	 */
	public static function momentum_rows() {
		$terms = [];

		foreach ( self::imports() as $imp ) {
			if ( 'timeline' === $imp['kind'] ) {
				foreach ( (array) $imp['series'] as $term => $points ) {
					$m = self::momentum( (array) $points );
					$terms[ strtolower( $term ) ] = [ 'term' => $term, 'geo' => $imp['geo'], 'range' => $imp['from'] . ' – ' . $imp['to'], 'source' => 'timeline' ] + $m;
				}
			} else {
				foreach ( (array) $imp['rising'] as $q ) {
					$key = strtolower( $q[0] );
					if ( ! isset( $terms[ $key ] ) ) {
						$terms[ $key ] = [ 'term' => $q[0], 'geo' => '', 'range' => '', 'source' => 'related', 'direction' => 'rising', 'change' => $q[1] >= 5000 ? 50.0 : $q[1] / 100, 'basis' => 'related', 'peak_month' => '' ];
					}
				}
			}
		}

		$queries = self::console_queries();
		$rows    = [];
		foreach ( $terms as $t ) {
			$impressions = 0;
			foreach ( $queries as $q => $imp ) {
				if ( FW_SEO_Analysis::has_phrase( $q, $t['term'] ) ) {
					$impressions += $imp;
				}
			}
			$page   = self::page_for( $t['term'] );
			$rows[] = $t + [ 'impressions' => $impressions, 'page' => $page, 'searches' => self::search_hits( $t['term'] ) ] + self::classify( $t['direction'], $impressions, (bool) $page );
		}

		usort( $rows, static function ( $a, $b ) {
			return [ $a['rank'], -$a['searches'], -$a['change'] ] <=> [ $b['rank'], -$b['searches'], -$b['change'] ];
		} );

		return $rows;
	}

	/* ------------------------------------------------------------------ *
	 * Admin actions
	 * ------------------------------------------------------------------ */

	protected static function guard( $get = false ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'fw' ), 403 );
		}
		$get ? check_admin_referer( self::NONCE ) : check_admin_referer( self::NONCE, 'fw_seo_trends_nonce' );
	}

	protected static function back( $message, $type = 'success' ) {
		set_transient( self::NOTICE . get_current_user_id(), [ 'message' => $message, 'type' => $type ], 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=fw-seo-settings&view=momentum_tab' ) );
		exit;
	}

	/** @internal Upload one or more Trends CSVs, or a JSON export of earlier imports. */
	public static function _action_upload() {
		self::guard();

		$files = $_FILES['fw_seo_trends_files'] ?? null; // phpcs:ignore WordPress.Security
		if ( ! $files || empty( $files['name'] ) ) {
			self::back( __( 'Choose one or more files exported from Google Trends.', 'fw' ), 'error' );
		}

		$added  = 0;
		$errors = [];
		foreach ( (array) $files['name'] as $i => $name ) {
			$tmp = $files['tmp_name'][ $i ] ?? '';
			if ( ! empty( $files['error'][ $i ] ) || ! is_uploaded_file( $tmp ) ) {
				continue;
			}
			$text = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( preg_match( '#\.json$#i', (string) $name ) ) {
				$data = json_decode( $text, true );
				foreach ( (array) ( $data['imports'] ?? [] ) as $imp ) {
					if ( is_array( $imp ) && in_array( $imp['kind'] ?? '', [ 'timeline', 'related' ], true ) ) {
						self::add( array_intersect_key( $imp, array_flip( [ 'kind', 'terms', 'geo', 'from', 'to', 'series', 'top', 'rising' ] ) ), $imp['file'] ?? $name );
						$added ++;
					}
				}
				continue;
			}

			$parsed = self::parse( $text, (string) $name );
			if ( is_wp_error( $parsed ) ) {
				$errors[] = $name . ': ' . $parsed->get_error_message();
			} else {
				self::add( $parsed, (string) $name );
				$added ++;
			}
		}

		$message = sprintf(
			/* translators: %d: number of imports */
			_n( '%d Trends export imported.', '%d Trends exports imported.', $added, 'fw' ),
			$added
		) . ( $errors ? ' ' . implode( ' ', $errors ) : '' );

		self::back( $message, $errors ? ( $added ? 'warning' : 'error' ) : 'success' );
	}

	/** @internal */
	public static function _action_delete() {
		self::guard( true );
		$id = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		update_option( self::OPTION, array_values( array_filter( (array) get_option( self::OPTION, [] ), static function ( $i ) use ( $id ) {
			return ( $i['id'] ?? '' ) !== $id;
		} ) ), false );
		self::back( __( 'Import removed.', 'fw' ) );
	}

	/** @internal Every import as JSON, which imports back unchanged; or one as CSV. */
	public static function _action_export() {
		self::guard( true );
		nocache_headers();
		header( 'Content-Disposition: attachment; filename="trends-imports-' . gmdate( 'Y-m-d' ) . '.json"' );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( [ 'format' => 'unysonplus-seo-trends', 'version' => 1, 'imports' => self::imports() ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * The screen
	 * ------------------------------------------------------------------ */

	/** @return string SEO → Topic momentum: the import panel and the rows. */
	public static function report_html() {
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		delete_transient( $key );
		$url = static function ( $action, $args = [] ) {
			return wp_nonce_url( add_query_arg( array_merge( [ 'action' => 'fw_seo_trends_' . $action ], $args ), admin_url( 'admin-post.php' ) ), self::NONCE );
		};

		ob_start();
		if ( is_array( $notice ) ) {
			echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . ' inline"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
		wp_nonce_field( self::NONCE, 'fw_seo_trends_nonce', false );
		$imports = self::imports();
		$rows    = $imports ? self::momentum_rows() : [];
		?>
		<div class="fw-seo-trends">
			<h3 style="margin-top:0"><?php esc_html_e( 'Import / Export', 'fw' ); ?></h3>
			<ol style="max-width:52em">
				<li><?php echo wp_kses( __( 'In <a href="https://trends.google.com/trends/explore" target="_blank" rel="noopener">Google Trends</a>, compare up to five topics you write about.', 'fw' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ); ?></li>
				<li><strong><?php esc_html_e( 'Set "Past 12 months" (or "Past 5 years" to see the season) and one country.', 'fw' ); ?></strong> <?php esc_html_e( 'Trends opens on a short worldwide window made for news, which reads as noise for anything else.', 'fw' ); ?></li>
				<li><?php esc_html_e( 'Use the download button on "Interest over time", and on "Related queries" for each topic, and drop the files here.', 'fw' ); ?></li>
			</ol>
			<p>
				<input type="file" name="fw_seo_trends_files[]" multiple accept=".csv,.json,text/csv,application/json">
				<button type="submit" class="button button-primary" formaction="<?php echo esc_attr( admin_url( 'admin-post.php' ) ); ?>?action=fw_seo_trends_upload" formenctype="multipart/form-data" formnovalidate><?php esc_html_e( 'Import', 'fw' ); ?></button>
				<?php if ( $imports ) : ?>
					<a class="button" href="<?php echo esc_url( $url( 'export' ) ); ?>"><?php esc_html_e( 'Export all (JSON)', 'fw' ); ?></a>
				<?php endif; ?>
			</p>
			<p class="description"><?php esc_html_e( 'Trends numbers are relative to the peak of what you asked for, not search volumes, so each import keeps the terms, country and dates it was taken under. The JSON export holds every import and imports back unchanged.', 'fw' ); ?></p>

			<?php if ( $imports ) : ?>
				<table class="widefat striped" style="max-width:1100px"><thead><tr><th><?php esc_html_e( 'Imported', 'fw' ); ?></th><th><?php esc_html_e( 'Chart', 'fw' ); ?></th><th><?php esc_html_e( 'Terms', 'fw' ); ?></th><th><?php esc_html_e( 'Country', 'fw' ); ?></th><th><?php esc_html_e( 'Dates', 'fw' ); ?></th><th></th></tr></thead><tbody>
				<?php foreach ( $imports as $imp ) : ?>
					<tr>
						<td><?php echo esc_html( human_time_diff( (int) $imp['imported'] ) . ' ' . __( 'ago', 'fw' ) ); ?></td>
						<td><?php echo esc_html( 'timeline' === $imp['kind'] ? __( 'Interest over time', 'fw' ) : __( 'Related queries', 'fw' ) ); ?></td>
						<td><?php echo esc_html( $imp['terms'] ? implode( ', ', (array) $imp['terms'] ) : __( '(not named in the file)', 'fw' ) ); ?></td>
						<td><?php echo esc_html( $imp['geo'] ?: '–' ); ?></td>
						<td><?php echo esc_html( $imp['from'] ? $imp['from'] . ' – ' . $imp['to'] : '–' ); ?></td>
						<td><a href="<?php echo esc_url( $url( 'delete', [ 'id' => $imp['id'] ] ) ); ?>"><?php esc_html_e( 'Remove', 'fw' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>

				<h3><?php esc_html_e( 'Topic momentum', 'fw' ); ?></h3>
				<?php if ( ! self::console_queries() ) : ?>
					<p class="description"><?php esc_html_e( 'Connect Search Console to see which of these topics already bring impressions. Without it, every topic reads as having none.', 'fw' ); ?></p>
				<?php endif; ?>
				<table class="widefat striped" style="max-width:1300px"><thead><tr><th><?php esc_html_e( 'Topic', 'fw' ); ?></th><th><?php esc_html_e( 'Interest', 'fw' ); ?></th><th><?php esc_html_e( 'Your impressions', 'fw' ); ?></th><th><?php esc_html_e( 'Your page', 'fw' ); ?></th><th><?php esc_html_e( 'Searched on your site', 'fw' ); ?></th><th><?php esc_html_e( 'What to do', 'fw' ); ?></th></tr></thead><tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $r['term'] ); ?></strong><?php if ( $r['peak_month'] ) : ?><br><span class="description"><?php echo esc_html( sprintf( /* translators: %s: month */ __( 'peaks in %s', 'fw' ), $r['peak_month'] ) ); ?></span><?php endif; ?></td>
						<td><?php
						$label = [ 'rising' => __( 'Rising', 'fw' ), 'falling' => __( 'Falling', 'fw' ), 'steady' => __( 'Steady', 'fw' ), 'unknown' => __( 'Too little data', 'fw' ) ][ $r['direction'] ] ?? '';
						echo esc_html( $label );
						if ( 'related' === $r['basis'] ) {
							echo '<br><span class="description">' . esc_html__( 'a rising related search', 'fw' ) . '</span>';
						} elseif ( 'unknown' !== $r['direction'] ) {
							echo '<br><span class="description">' . esc_html( sprintf(
								/* translators: 1: signed percentage, 2: comparison basis */
								__( '%1$s%% %2$s', 'fw' ),
								( $r['change'] > 0 ? '+' : '' ) . round( $r['change'] * 100 ),
								'year' === $r['basis'] ? __( 'on the same months last year', 'fw' ) : __( 'on the months before', 'fw' )
							) ) . '</span>';
						}
						?></td>
						<td><?php echo $r['impressions'] ? esc_html( number_format_i18n( $r['impressions'] ) ) : '<span class="description">–</span>'; ?></td>
						<td><?php echo $r['page'] ? '<a href="' . esc_url( (string) get_edit_post_link( $r['page'] ) ) . '">' . esc_html( get_the_title( $r['page'] ) ) . '</a>' : '<span class="description">' . esc_html__( 'none', 'fw' ) . '</span>'; ?></td>
						<td><?php echo $r['searches'] ? '<strong>' . esc_html( number_format_i18n( $r['searches'] ) ) . '</strong>' : '<span class="description">–</span>'; ?></td>
						<td><?php echo esc_html( $r['action'] ); ?><?php if ( $r['searches'] && 'rising' === $r['direction'] ) : ?> <strong><?php esc_html_e( 'Your own visitors search for it too: about as strong a signal as a small site gets.', 'fw' ); ?></strong><?php endif; ?>
							<?php if ( class_exists( 'FW_SEO_AI' ) && FW_SEO_AI::ready() && in_array( $r['case'], [ 'write', 'gap', 'refresh' ], true ) ) : ?>
								<br><button type="button" class="button button-small fw-seo-ai-outline" data-topic="<?php echo esc_attr( $r['term'] ); ?>" data-page="<?php echo (int) $r['page']; ?>"><?php esc_html_e( 'Draft a title and outline', 'fw' ); ?></button><div class="fw-seo-ai-out"></div>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div>
		<?php
		if ( class_exists( 'FW_SEO_AI' ) ) {
			echo FW_SEO_AI::outline_script(); // phpcs:ignore WordPress.Security.EscapeOutput -- a static script.
		}

		return (string) ob_get_clean();
	}
}
