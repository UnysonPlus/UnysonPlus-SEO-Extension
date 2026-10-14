<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Bulk editing through a spreadsheet.
 *
 * A different job from the plugin importer next to it: that one reads Yoast and
 * friends, this one round-trips YOUR values so three hundred descriptions can be
 * written in a sheet — sorted, filtered, seen all at once — or handed to someone
 * who will never log in to write them.
 *
 * Two guarantees this class owes the site, both load-bearing:
 *
 *   1. It writes SEO META ONLY. It never creates a post, never deletes one, and
 *      never touches post content, title, status, dates or terms. A CSV is a
 *      list of edits to meta, not a description of what the site should contain.
 *   2. A post whose id is absent is NOT VISITED. Absence means "nothing to say
 *      about this one", never "remove it".
 */
class FW_SEO_CSV {

	const NONCE = 'fw_seo_csv';

	/** Rows read per pass when counting; keeps a 10k-post site off the memory limit. */
	const CHUNK = 500;

	/**
	 * The columns, in sheet order.
	 *
	 * `editable` false means the column is CONTEXT — written on export, ignored
	 * on import. Without the resolved values beside the editable ones you cannot
	 * tell which rows need touching, and you end up overriding pages that were
	 * already fine.
	 *
	 * @return array<string,array{label:string,editable:bool,field:string}>
	 */
	public static function columns() {
		$columns = [
			'id'                   => [ 'label' => 'ID', 'editable' => false, 'field' => '' ],
			'post_type'            => [ 'label' => 'Type', 'editable' => false, 'field' => '' ],
			'status'               => [ 'label' => 'Status', 'editable' => false, 'field' => '' ],
			'url'                  => [ 'label' => 'URL', 'editable' => false, 'field' => '' ],
			'current_title'        => [ 'label' => 'Current title (read-only)', 'editable' => false, 'field' => '' ],
			'seo_title'            => [ 'label' => 'SEO title', 'editable' => true, 'field' => 'title' ],
			'current_description'  => [ 'label' => 'Current description (read-only)', 'editable' => false, 'field' => '' ],
			'meta_description'     => [ 'label' => 'Meta description', 'editable' => true, 'field' => 'description' ],
			'canonical'            => [ 'label' => 'Canonical URL', 'editable' => true, 'field' => 'canonical' ],
			'noindex'              => [ 'label' => 'No index', 'editable' => true, 'field' => 'noindex' ],
			'nofollow'             => [ 'label' => 'No follow', 'editable' => true, 'field' => 'nofollow' ],
			'og_title'             => [ 'label' => 'Share title', 'editable' => true, 'field' => 'og_title' ],
			'og_description'       => [ 'label' => 'Share description', 'editable' => true, 'field' => 'og_description' ],
			'og_image'             => [ 'label' => 'Share image URL', 'editable' => true, 'field' => 'og_image' ],
		];

		/** Filters the columns of the SEO bulk-edit spreadsheet. */
		return apply_filters( 'fw_seo_csv_columns', $columns );
	}

	/**
	 * The editable columns, keyed by the store field they write.
	 *
	 * @return array<string,string> column key => store field
	 */
	public static function editable() {
		$map = [];

		foreach ( self::columns() as $key => $column ) {
			if ( ! empty( $column['editable'] ) && ! empty( $column['field'] ) ) {
				$map[ $key ] = $column['field'];
			}
		}

		return $map;
	}

	// -------------------------------------------------------------------------
	// Export
	// -------------------------------------------------------------------------

	/**
	 * Neutralise a cell that a spreadsheet would execute.
	 *
	 * A value beginning `=`, `+`, `-` or `@` is a FORMULA to Excel, Sheets and
	 * LibreOffice — so an exported description starting with a dash can run code
	 * on the machine of whoever opens the file. Prefixing a tab makes it text
	 * again, and the tab is invisible in the cell.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	public static function safe_cell( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "\t" . $value : $value;
	}

	/**
	 * One post's row.
	 *
	 * @param int $post_id
	 *
	 * @return array<string,string>
	 */
	public static function row( $post_id ) {
		$ctx = FW_SEO_Context::for_post( $post_id );

		FW_SEO_Chain::flush( $ctx );

		$row = [];

		foreach ( self::columns() as $key => $column ) {
			switch ( $key ) {
				case 'id':
					$row[ $key ] = (string) $post_id;
					break;

				case 'post_type':
					$row[ $key ] = (string) get_post_type( $post_id );
					break;

				case 'status':
					$row[ $key ] = (string) get_post_status( $post_id );
					break;

				case 'url':
					$row[ $key ] = (string) get_permalink( $post_id );
					break;

				// The resolved values: what the page emits TODAY, so the person
				// editing can see what they would be replacing.
				case 'current_title':
					$row[ $key ] = FW_SEO_Chain::resolve( 'title', $ctx );
					break;

				case 'current_description':
					$row[ $key ] = FW_SEO_Chain::resolve( 'description', $ctx );
					break;

				default:
					$field = $column['field'] ?? '';

					if ( '' === $field ) {
						$row[ $key ] = '';
						break;
					}

					$stored = FW_SEO_Store::get_post( $post_id, $field );

					// Booleans travel as 1/0 rather than true/false: a sheet
					// turns "TRUE" into a locale-dependent boolean and hands it
					// back as "WAHR" on a German install.
					if ( is_bool( $stored ) ) {
						$row[ $key ] = $stored ? '1' : '0';
						break;
					}

					$row[ $key ] = (string) $stored;
			}
		}

		return $row;
	}

	/**
	 * The ids to export.
	 *
	 * @param array $args
	 *
	 * @return array<int,int>
	 */
	public static function ids( array $args = [] ) {
		$post_types = ! empty( $args['post_types'] )
			? (array) $args['post_types']
			: array_keys( FW_SEO_Locations::post_types() );

		return get_posts( [
			'post_type'        => $post_types,
			'post_status'      => [ 'publish', 'draft', 'pending', 'future', 'private' ],
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
		] );
	}

	/**
	 * Stream the sheet to the browser.
	 *
	 * Streamed rather than built in memory: a site with ten thousand posts would
	 * otherwise need the whole sheet resident before the first byte is sent.
	 *
	 * @param array $args
	 */
	public static function send( array $args = [] ) {
		$columns  = self::columns();
		$filename = 'seo-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );

		// The BOM is not decoration: without it Excel reads the file as the
		// system codepage and every accented character arrives mangled.
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, array_column( $columns, 'label' ) );

		foreach ( self::ids( $args ) as $post_id ) {
			$row = self::row( $post_id );

			fputcsv( $out, array_map( [ __CLASS__, 'safe_cell' ], array_values( $row ) ) );
		}

		fclose( $out );
	}

	// -------------------------------------------------------------------------
	// Import
	// -------------------------------------------------------------------------

	/**
	 * Undo a `safe_cell()` prefix on the way back in.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	public static function unsafe_cell( $value ) {
		$value = (string) $value;

		return ( '' !== $value && "\t" === $value[0] ) ? substr( $value, 1 ) : $value;
	}

	/**
	 * Read a CSV into [ headers, rows ].
	 *
	 * Headers are matched back to column KEYS by their label, so a sheet whose
	 * columns were reordered or partly deleted still lands correctly — and a
	 * column that is ABSENT stays absent, which is what makes a partial sheet
	 * safe to import.
	 *
	 * @param string $path
	 *
	 * @return array{headers:array<int,string>,rows:array<int,array>,error:string}
	 */
	public static function parse( $path ) {
		$result = [ 'headers' => [], 'rows' => [], 'error' => '' ];

		if ( ! is_readable( $path ) ) {
			$result['error'] = __( 'The file could not be read.', 'fw' );

			return $result;
		}

		$handle = fopen( $path, 'r' );

		if ( ! $handle ) {
			$result['error'] = __( 'The file could not be opened.', 'fw' );

			return $result;
		}

		$columns  = self::columns();
		$by_label = [];

		foreach ( $columns as $key => $column ) {
			$by_label[ self::normalise_header( $column['label'] ) ] = $key;
			// Accept the raw key too, so a hand-built sheet can use `seo_title`.
			$by_label[ self::normalise_header( $key ) ] = $key;
		}

		$header_row = fgetcsv( $handle );

		if ( ! $header_row ) {
			fclose( $handle );
			$result['error'] = __( 'The file is empty.', 'fw' );

			return $result;
		}

		// Strip the BOM our own export writes.
		$header_row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header_row[0] );

		$headers = [];

		foreach ( $header_row as $i => $label ) {
			$key = $by_label[ self::normalise_header( (string) $label ) ] ?? '';

			if ( '' !== $key ) {
				$headers[ $i ] = $key;
			}
		}

		if ( ! in_array( 'id', $headers, true ) ) {
			fclose( $handle );
			$result['error'] = __( 'The file has no ID column. Rows are matched on post ID, so without it nothing can be applied safely.', 'fw' );

			return $result;
		}

		while ( false !== ( $line = fgetcsv( $handle ) ) ) {
			// fgetcsv hands back [ null ] for a blank line.
			if ( ! array_filter( $line, static function ( $v ) {
				return null !== $v && '' !== trim( (string) $v );
			} ) ) {
				continue;
			}

			$row = [];

			foreach ( $headers as $i => $key ) {
				$row[ $key ] = self::unsafe_cell( $line[ $i ] ?? '' );
			}

			$result['rows'][] = $row;
		}

		fclose( $handle );

		$result['headers'] = array_values( $headers );

		return $result;
	}

	/**
	 * @param string $label
	 *
	 * @return string
	 */
	protected static function normalise_header( $label ) {
		$label = strtolower( trim( (string) $label ) );
		$label = preg_replace( '/\s*\(read-only\)\s*$/', '', $label );

		return preg_replace( '/[^a-z0-9]+/', '_', (string) $label );
	}

	/**
	 * Work out what a file WOULD do, or do it.
	 *
	 * One method for both so the preview can never disagree with the apply —
	 * a preview computed by separate code is a preview of something else.
	 *
	 * Only columns PRESENT in the file are considered. Within a present column a
	 * blank cell CLEARS the override (empty means "follow the template"
	 * everywhere else in this extension, and the export writes the current
	 * override into the cell, so a blank-in/blank-out round trip changes
	 * nothing). A column the file does not contain is never touched at all,
	 * which is what makes a hand-built two-column sheet safe.
	 *
	 * @param array $rows
	 * @param array $headers Column keys present in the file.
	 * @param bool  $apply   False = count only, write nothing.
	 *
	 * @return array{changes:int,clears:int,rows:int,skipped:int,missing:array,notes:array}
	 */
	public static function process( array $rows, array $headers, $apply = false ) {
		$editable = array_intersect_key( self::editable(), array_flip( $headers ) );

		$result = [
			'changes' => 0,
			'clears'  => 0,
			'rows'    => 0,
			'skipped' => 0,
			'missing' => [],
			'notes'   => [],
		];

		if ( ! $editable ) {
			$result['notes'][] = __( 'The file has no editable columns, so there is nothing to apply.', 'fw' );

			return $result;
		}

		foreach ( $rows as $row ) {
			$post_id = isset( $row['id'] ) ? (int) trim( (string) $row['id'] ) : 0;

			if ( ! $post_id ) {
				$result['skipped'] ++;
				continue;
			}

			$post = get_post( $post_id );

			// A row for a post that is not here is REPORTED, never acted on —
			// and never, under any reading, a reason to remove anything.
			if ( ! $post ) {
				$result['missing'][] = $post_id;
				$result['skipped'] ++;
				continue;
			}

			if ( ! FW_SEO_Locations::has_post_type( $post->post_type ) ) {
				$result['skipped'] ++;
				continue;
			}

			if ( $apply && ! current_user_can( 'edit_post', $post_id ) ) {
				$result['skipped'] ++;
				continue;
			}

			$touched = 0;

			foreach ( $editable as $column => $field ) {
				$incoming = trim( (string) ( $row[ $column ] ?? '' ) );
				$current  = FW_SEO_Store::get_post( $post_id, $field );

				// Compare in the shape the store will hold, so "1" against a
				// stored true does not read as a change on every single import.
				$current_str = is_bool( $current ) ? ( $current ? '1' : '0' ) : (string) $current;
				$incoming_cmp = self::normalise_bool( $field, $incoming );

				if ( $incoming_cmp === $current_str ) {
					continue;
				}

				if ( '' === $incoming_cmp && '' !== $current_str && '0' !== $current_str ) {
					$result['clears'] ++;
				}

				$touched ++;

				if ( $apply ) {
					FW_SEO_Store::set_post( $post_id, $field, $incoming_cmp );
				}
			}

			if ( $touched ) {
				$result['changes'] += $touched;
				$result['rows'] ++;
			}
		}

		return $result;
	}

	/**
	 * A boolean column arrives as 1/0, yes/no, true/false or a localised word.
	 *
	 * @param string $field
	 * @param string $value
	 *
	 * @return string
	 */
	protected static function normalise_bool( $field, $value ) {
		if ( ! in_array( $field, [ 'noindex', 'nofollow' ], true ) ) {
			return $value;
		}

		$value = strtolower( trim( $value ) );

		if ( '' === $value ) {
			return '0';
		}

		return in_array( $value, [ '1', 'yes', 'true', 'y', 'on', 'wahr', 'vrai', 'si', 'sí' ], true ) ? '1' : '0';
	}

	// -------------------------------------------------------------------------
	// Admin
	// -------------------------------------------------------------------------

	const PENDING = 'fw_seo_csv_pending';

	public static function init() {
		add_action( 'admin_post_fw_seo_csv_export', [ __CLASS__, '_action_export' ] );
		add_action( 'admin_post_fw_seo_csv_upload', [ __CLASS__, '_action_upload' ] );
		add_action( 'admin_post_fw_seo_csv_apply', [ __CLASS__, '_action_apply' ] );
		add_action( 'admin_post_fw_seo_csv_cancel', [ __CLASS__, '_action_cancel' ] );
	}

	/**
	 * @return string
	 */
	protected static function back( array $args = [] ) {
		$url = admin_url( 'admin.php?page=fw-seo-settings' );

		return $args ? add_query_arg( $args, $url ) : $url;
	}

	protected static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'fw' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( $action );
	}

	/**
	 * @internal
	 */
	public static function _action_export() {
		self::guard( 'fw_seo_csv_export' );

		self::send();
		exit;
	}

	/**
	 * Where an uploaded sheet waits between the preview and the apply.
	 *
	 * On disk rather than in a transient: a ten-thousand-row sheet is megabytes,
	 * and parking that in the options table to survive one redirect is the kind
	 * of thing that quietly bloats a database.
	 *
	 * @return string
	 */
	protected static function pending_path() {
		$dir = function_exists( 'fw_upw_uploads_dir' )
			? fw_upw_uploads_dir( 'seo-imports' )['path']
			: get_temp_dir();

		wp_mkdir_p( $dir );

		return $dir . '/pending-' . get_current_user_id() . '.csv';
	}

	/**
	 * @internal
	 */
	public static function _action_upload() {
		self::guard( 'fw_seo_csv_upload' );

		$file = $_FILES['fw_seo_csv_file'] ?? null;

		if ( ! $file || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_safe_redirect( self::back( [ 'fw-seo-csv' => 'nofile' ] ) );
			exit;
		}

		$path = self::pending_path();

		if ( ! @move_uploaded_file( $file['tmp_name'], $path ) ) {
			wp_safe_redirect( self::back( [ 'fw-seo-csv' => 'nofile' ] ) );
			exit;
		}

		wp_safe_redirect( self::back( [ 'fw-seo-csv' => 'preview' ] ) );
		exit;
	}

	/**
	 * @internal
	 */
	public static function _action_apply() {
		self::guard( 'fw_seo_csv_apply' );

		$path   = self::pending_path();
		$parsed = self::parse( $path );

		if ( '' !== $parsed['error'] ) {
			@unlink( $path );
			wp_safe_redirect( self::back( [ 'fw-seo-csv' => 'error' ] ) );
			exit;
		}

		$result = self::process( $parsed['rows'], $parsed['headers'], true );

		@unlink( $path );

		wp_safe_redirect( self::back( [
			'fw-seo-csv' => 'done',
			'rows'       => (int) $result['rows'],
			'changes'    => (int) $result['changes'],
		] ) );
		exit;
	}

	/**
	 * @internal
	 */
	public static function _action_cancel() {
		self::guard( 'fw_seo_csv_cancel' );

		@unlink( self::pending_path() );

		wp_safe_redirect( self::back() );
		exit;
	}

	/**
	 * The parsed sheet waiting for confirmation, or null.
	 *
	 * @return array|null
	 */
	public static function pending() {
		$path = self::pending_path();

		if ( ! file_exists( $path ) ) {
			return null;
		}

		return self::parse( $path );
	}
}
