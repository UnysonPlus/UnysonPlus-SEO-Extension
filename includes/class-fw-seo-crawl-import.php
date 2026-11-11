<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Import / Export a crawler's results.
 *
 * Someone who already runs a dedicated crawler can drop its export here: each
 * row is reduced to one shape (address, status, title, description, heading,
 * redirect, indexable, notes), matched to a page, and its problems are listed
 * in the Audit. Crawlers name their columns differently, so columns are found
 * by a list of names rather than by product.
 *
 * Both exports (CSV and JSON) import back unchanged.
 *
 * @since 2.0.57
 */
class FW_SEO_Crawl_Import {

	const OPTION   = 'fw_seo_crawl_import';
	const NOTICE   = 'fw_seo_crawl_notice_';
	const NONCE    = 'fw_seo_crawl';
	const MAX_ROWS = 20000;
	const FORMAT   = 'unysonplus-seo-crawl';

	/** The columns of the export, and of a stored row. */
	const COLUMNS = [ 'url', 'status', 'title', 'description', 'h1', 'redirect', 'indexable', 'notes' ];

	/** Swapped for screen_html() when the settings page is drawn. */
	const PLACEHOLDER = '<!--fw-seo-crawl-import-->';

	public static function init() {
		foreach ( [ 'upload', 'export', 'clear' ] as $a ) {
			add_action( 'admin_post_fw_seo_crawl_' . $a, [ __CLASS__, '_action_' . $a ] );
		}
	}

	/** @return array<string, string> issue id => what it means and what to do. */
	public static function issue_labels() {
		return [
			'crawl_error'       => __( 'Crawler: the address answered an error.', 'fw' ),
			'crawl_redirect'    => __( 'Crawler: the address redirects, so a link or the sitemap is pointing at an old address.', 'fw' ),
			'crawl_no_title'    => __( 'Crawler: no title found in the served page.', 'fw' ),
			'crawl_no_desc'     => __( 'Crawler: no description found in the served page.', 'fw' ),
			'crawl_no_h1'       => __( 'Crawler: no H1 heading found in the served page.', 'fw' ),
			'crawl_noindex'     => __( 'Crawler: the page cannot be indexed.', 'fw' ),
			'crawl_notes'       => __( 'Crawler: other findings.', 'fw' ),
		];
	}

	/* ------------------------------------------------------------------ *
	 * Reading a file — pure
	 * ------------------------------------------------------------------ */

	/** Column names as crawlers write them, reduced by norm_header(). */
	protected static function aliases() {
		return [
			'url'         => [ 'url', 'address', 'page', 'pageurl', 'loc', 'link' ],
			'status'      => [ 'status', 'statuscode', 'httpstatus', 'httpstatuscode', 'responsecode', 'code' ],
			'title'       => [ 'title', 'title1', 'pagetitle', 'metatitle' ],
			'description' => [ 'description', 'metadescription', 'metadescription1', 'metadesc' ],
			'h1'          => [ 'h1', 'h11', 'firsth1', 'heading1' ],
			'redirect'    => [ 'redirect', 'redirecturl', 'redirecturi', 'redirectsto', 'finalurl', 'location' ],
			'indexable'   => [ 'indexable', 'indexability', 'isindexable' ],
			'notes'       => [ 'notes', 'issue', 'issues', 'problems', 'warnings', 'errors' ],
		];
	}

	protected static function norm_header( $name ) {
		return (string) preg_replace( '#[^a-z0-9]#', '', strtolower( (string) $name ) );
	}

	/** One record, whatever its column names, in the stored shape. */
	protected static function shape( array $record ) {
		$keyed = [];
		foreach ( $record as $k => $v ) {
			$keyed[ self::norm_header( $k ) ] = is_array( $v ) ? implode( '; ', array_filter( array_map( static function ( $i ) {
				return is_scalar( $i ) ? (string) $i : '';
			}, $v ) ) ) : ( is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : (string) $v );
		}

		$row = [];
		foreach ( self::aliases() as $field => $names ) {
			$row[ $field ] = '';
			foreach ( $names as $name ) {
				if ( isset( $keyed[ $name ] ) && '' !== trim( $keyed[ $name ] ) ) {
					$row[ $field ] = trim( $keyed[ $name ] );
					break;
				}
			}
		}

		return $row;
	}

	/**
	 * Read a crawler's CSV or JSON export.
	 *
	 * @param string $text
	 * @param string $name The file name.
	 *
	 * @return array|WP_Error Rows in the stored shape.
	 */
	public static function parse( $text, $name = '' ) {
		$text = (string) preg_replace( '#^\xEF\xBB\xBF#', '', (string) $text );

		$records = in_array( substr( ltrim( $text ), 0, 1 ), [ '{', '[' ], true ) ? self::json_records( $text ) : self::csv_records( $text );

		$rows = [];
		foreach ( array_slice( $records, 0, self::MAX_ROWS ) as $record ) {
			$row = self::shape( (array) $record );
			if ( preg_match( '#^https?://#i', $row['url'] ) ) {
				$rows[] = $row;
			}
		}

		if ( ! $rows ) {
			return new WP_Error( 'empty', sprintf(
				/* translators: %s: file name */
				__( '%s: no rows with an address were found. The file needs a column named URL or Address.', 'fw' ),
				$name
			) );
		}

		return $rows;
	}

	protected static function json_records( $text ) {
		$data = json_decode( $text, true );

		if ( ! is_array( $data ) ) {
			return [];
		}
		foreach ( [ 'rows', 'results', 'pages', 'urls', 'data' ] as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				return array_values( array_filter( $data[ $key ], 'is_array' ) );
			}
		}

		return array_values( array_filter( $data, 'is_array' ) );
	}

	protected static function csv_records( $text ) {
		$h = fopen( 'php://temp', 'r+' );
		fwrite( $h, $text );
		rewind( $h );

		$first = strtok( $text, "\r\n" );
		$sep   = ',';
		foreach ( [ "\t", ';' ] as $other ) {
			if ( substr_count( (string) $first, $other ) > substr_count( (string) $first, $sep ) ) {
				$sep = $other;
			}
		}

		$head = fgetcsv( $h, 0, $sep, '"', '' );
		$out  = [];
		while ( $head && count( $out ) < self::MAX_ROWS && false !== ( $line = fgetcsv( $h, 0, $sep, '"', '' ) ) ) {
			if ( count( $line ) === count( $head ) ) {
				$out[] = array_combine( $head, $line );
			}
		}
		fclose( $h );

		return $out;
	}

	/**
	 * The problems in one row.
	 *
	 * @param array $row In the stored shape.
	 *
	 * @return array<string, string> issue id => detail.
	 */
	public static function findings( array $row ) {
		$status = (int) $row['status'];
		$out    = [];

		if ( $status >= 400 ) {
			$out['crawl_error'] = (string) $status;
		} elseif ( $status >= 300 ) {
			$out['crawl_redirect'] = '' !== $row['redirect'] ? $status . ' → ' . $row['redirect'] : (string) $status;
		} elseif ( 200 === $status ) {
			// A crawler that has no such column leaves every row empty: that is no finding.
			foreach ( [ 'title' => 'crawl_no_title', 'description' => 'crawl_no_desc', 'h1' => 'crawl_no_h1' ] as $field => $issue ) {
				if ( '' === $row[ $field ] && ! empty( $row['_has'][ $field ] ) ) {
					$out[ $issue ] = '';
				}
			}
		}

		if ( preg_match( '#^(no|false|0|non-indexable|not indexable)$#i', $row['indexable'] ) ) {
			$out['crawl_noindex'] = '';
		}
		if ( '' !== $row['notes'] ) {
			$out['crawl_notes'] = $row['notes'];
		}

		return $out;
	}

	/** Mark which fields this file has at all, so a missing column is not read as a missing title. */
	public static function mark_columns( array $rows ) {
		$has = [];
		foreach ( [ 'title', 'description', 'h1' ] as $field ) {
			$has[ $field ] = (bool) array_filter( array_column( $rows, $field ), 'strlen' );
		}

		return array_map( static function ( $row ) use ( $has ) {
			return $row + [ '_has' => $has ];
		}, $rows );
	}

	/* ------------------------------------------------------------------ *
	 * The stored import
	 * ------------------------------------------------------------------ */

	/** @return array{file: string, time: int, rows: array[], matched: array<int, array>, unmatched: string[]} */
	public static function stored() {
		$s = (array) get_option( self::OPTION, [] );

		return [
			'file'      => (string) ( $s['file'] ?? '' ),
			'time'      => (int) ( $s['time'] ?? 0 ),
			'rows'      => (array) ( $s['rows'] ?? [] ),
			'matched'   => (array) ( $s['matched'] ?? [] ),
			'unmatched' => (array) ( $s['unmatched'] ?? [] ),
		];
	}

	/** Keep the rows, and work out which page each is about. */
	public static function save( array $rows, $file ) {
		$matched   = [];
		$unmatched = [];

		foreach ( self::mark_columns( $rows ) as $row ) {
			$id = url_to_postid( $row['url'] );
			if ( ! $id && untrailingslashit( $row['url'] ) === untrailingslashit( home_url() ) ) {
				$id = (int) get_option( 'page_on_front' );
			}
			if ( ! $id ) {
				$unmatched[] = $row['url'];
				continue;
			}
			foreach ( self::findings( $row ) as $issue => $detail ) {
				$matched[ $id ][ $issue ] = $detail;
			}
		}

		update_option( self::OPTION, [ 'file' => (string) $file, 'time' => time(), 'rows' => $rows, 'matched' => $matched, 'unmatched' => $unmatched ], false );

		return [ 'rows' => count( $rows ), 'pages' => count( $matched ), 'unmatched' => count( $unmatched ) ];
	}

	/** @return array<string, int[]> issue id => post ids. */
	public static function by_issue() {
		$out = [];
		foreach ( self::stored()['matched'] as $id => $issues ) {
			if ( 'publish' !== get_post_status( (int) $id ) ) {
				continue;
			}
			foreach ( array_keys( (array) $issues ) as $issue ) {
				$out[ $issue ][] = (int) $id;
			}
		}

		return $out;
	}

	public static function detail( $id, $issue ) {
		static $matched = null;
		if ( null === $matched ) {
			$matched = self::stored()['matched'];
		}

		return (string) ( $matched[ $id ][ $issue ] ?? '' );
	}

	/* ------------------------------------------------------------------ *
	 * The screen
	 * ------------------------------------------------------------------ */

	protected static function guard( $field = '_wpnonce' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'fw' ), 403 );
		}
		check_admin_referer( self::NONCE, $field );
	}

	protected static function back( $message, $type = 'success' ) {
		set_transient( self::NOTICE . get_current_user_id(), [ 'message' => $message, 'type' => $type ], MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=fw-seo-settings&view=audit_tab' ) );
		exit;
	}

	/** @internal */
	public static function _action_upload() {
		self::guard( 'fw_seo_crawl_nonce' );

		$file = $_FILES['fw_seo_crawl_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read as data below, never stored as a file.
		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::back( __( 'Choose a file to import.', 'fw' ), 'error' );
		}

		$name = sanitize_file_name( (string) $file['name'] );
		$rows = self::parse( (string) file_get_contents( $file['tmp_name'] ), $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( is_wp_error( $rows ) ) {
			self::back( $rows->get_error_message(), 'error' );
		}

		$r = self::save( $rows, $name );
		self::back( sprintf(
			/* translators: 1: rows, 2: pages with findings, 3: addresses that are not pages */
			__( 'Addresses imported: %1$d. Pages with findings: %2$d. Addresses that are not pages of this site (archives, files), kept but not listed in the audit: %3$d.', 'fw' ),
			$r['rows'],
			$r['pages'],
			$r['unmatched']
		) );
	}

	/** @internal Every row, as CSV or JSON. Either imports back unchanged. */
	public static function _action_export() {
		self::guard();

		$s    = self::stored();
		$kind = isset( $_GET['kind'] ) && 'json' === $_GET['kind'] ? 'json' : 'csv';
		nocache_headers();
		header( 'Content-Disposition: attachment; filename="crawl-import-' . gmdate( 'Y-m-d' ) . '.' . $kind . '"' );

		if ( 'json' === $kind ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( [ 'format' => self::FORMAT, 'version' => 1, 'file' => $s['file'], 'rows' => $s['rows'] ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			exit;
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		echo self::to_csv( $s['rows'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- a file download.
		exit;
	}

	/** Pure: rows as the CSV export. */
	public static function to_csv( array $rows ) {
		$h = fopen( 'php://temp', 'r+' );
		fputcsv( $h, self::COLUMNS, ',', '"', '' );
		foreach ( $rows as $row ) {
			fputcsv( $h, array_map( static function ( $c ) use ( $row ) {
				return (string) ( $row[ $c ] ?? '' );
			}, self::COLUMNS ), ',', '"', '' );
		}
		rewind( $h );
		$out = (string) stream_get_contents( $h );
		fclose( $h );

		return $out;
	}

	/** @internal */
	public static function _action_clear() {
		self::guard();
		delete_option( self::OPTION );
		self::back( __( 'Import removed.', 'fw' ) );
	}

	/** @return string SEO → Audit → Import / Export. */
	public static function screen_html() {
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		delete_transient( $key );

		$s    = self::stored();
		$url  = static function ( $action, $args = [] ) {
			return wp_nonce_url( add_query_arg( array_merge( [ 'action' => 'fw_seo_crawl_' . $action ], $args ), admin_url( 'admin-post.php' ) ), self::NONCE );
		};
		$html = '';

		if ( is_array( $notice ) ) {
			$html .= '<div class="notice notice-' . esc_attr( $notice['type'] ) . ' inline"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}

		$html .= wp_nonce_field( self::NONCE, 'fw_seo_crawl_nonce', false, false )
			. '<p><input type="file" name="fw_seo_crawl_file" accept=".csv,.tsv,.json,text/csv,application/json"> '
			. '<button type="submit" class="button button-primary" formaction="' . esc_attr( admin_url( 'admin-post.php' ) ) . '?action=fw_seo_crawl_upload" formenctype="multipart/form-data" formnovalidate>' . esc_html__( 'Import', 'fw' ) . '</button>';

		if ( $s['rows'] ) {
			$html .= ' <a class="button" href="' . esc_url( $url( 'export', [ 'kind' => 'csv' ] ) ) . '">' . esc_html__( 'Export CSV', 'fw' ) . '</a>'
				. ' <a class="button" href="' . esc_url( $url( 'export', [ 'kind' => 'json' ] ) ) . '">' . esc_html__( 'Export JSON', 'fw' ) . '</a>'
				. ' <a class="button-link-delete" style="margin-left:8px" href="' . esc_url( $url( 'clear' ) ) . '">' . esc_html__( 'Remove', 'fw' ) . '</a>';
		}
		$html .= '</p><p class="description">' . esc_html__( 'The file needs a column named URL or Address. Status, title, description, H1, redirect, indexability and issue columns are read when present. A new import replaces the last one.', 'fw' ) . '</p>';

		if ( $s['rows'] ) {
			$html .= '<p>' . esc_html( sprintf(
				/* translators: 1: file name, 2: rows, 3: time ago, 4: pages with findings, 5: not pages */
				__( '%1$s: %2$d addresses, imported %3$s ago. Pages with findings: %4$d. Addresses that are not pages of this site: %5$d.', 'fw' ),
				$s['file'],
				count( $s['rows'] ),
				human_time_diff( $s['time'] ),
				count( $s['matched'] ),
				count( $s['unmatched'] )
			) ) . '</p>';
		}

		return $html;
	}
}
