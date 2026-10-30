<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Move SEO settings between sites as one JSON file.
 *
 * The Import / Export screen already moved per-page values (a spreadsheet) and
 * pulled them in from other plugins. What it could not do was the other half:
 * the site-wide settings — title templates, sitemap choices, sharing defaults,
 * structured data — which are the part set up once and then wanted again on
 * the next site. Every import here comes with its export, and what is exported
 * imports back unchanged.
 *
 * Two rules decide what crosses:
 *
 *  - **Only keys this version knows.** Keys from a newer or older version, or
 *    from somewhere else entirely, are reported and ignored rather than written,
 *    so a stray file cannot plant values nothing reads.
 *  - **Credentials stay behind unless asked for.** An API key and an OAuth
 *    secret in a file that gets emailed around is a leak waiting for an inbox.
 *    They are left out by default; including them is a deliberate choice, and
 *    the Search Console connection itself (its tokens) never travels at all —
 *    it belongs to one site's grant.
 *
 * @since 2.0.35
 */
class FW_SEO_Transfer {

	const FORMAT  = 'unysonplus-seo-settings';
	const VERSION = 1;

	/** Settings that are credentials: exported only on request. */
	const SECRETS = [ 'crux_api_key', 'console_client_id', 'console_client_secret' ];

	const PENDING_TRANSIENT = 'fw_seo_transfer_pending';

	public static function init() {
		if ( is_admin() ) {
			add_action( 'admin_post_fw_seo_settings_export', [ __CLASS__, '_action_export' ] );
			add_action( 'admin_post_fw_seo_settings_upload', [ __CLASS__, '_action_upload' ] );
			add_action( 'admin_post_fw_seo_settings_apply', [ __CLASS__, '_action_apply' ] );
			add_action( 'admin_post_fw_seo_settings_cancel', [ __CLASS__, '_action_cancel' ] );
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Pure — tested without WordPress state
	 * ---------------------------------------------------------------------- */

	/**
	 * @param array $settings
	 * @param bool  $include_secrets
	 * @param array $meta site + version stamp
	 *
	 * @return array
	 */
	public static function payload( array $settings, $include_secrets = false, array $meta = [] ) {
		if ( ! $include_secrets ) {
			foreach ( self::SECRETS as $key ) {
				unset( $settings[ $key ] );
			}
		}

		ksort( $settings );

		return [
			'format'    => self::FORMAT,
			'version'   => self::VERSION,
			'exported'  => gmdate( 'c' ),
			'site'      => (string) ( $meta['site'] ?? '' ),
			'extension' => (string) ( $meta['extension'] ?? '' ),
			'secrets'   => (bool) $include_secrets,
			'settings'  => $settings,
		];
	}

	/**
	 * Is this a settings file we can read?
	 *
	 * @param mixed $data Decoded JSON.
	 *
	 * @return string '' when valid, otherwise a reason code.
	 */
	public static function validate( $data ) {
		if ( ! is_array( $data ) ) {
			return 'notjson';
		}

		if ( self::FORMAT !== ( $data['format'] ?? '' ) ) {
			return 'notours';
		}

		if ( (int) ( $data['version'] ?? 0 ) > self::VERSION ) {
			return 'newer';
		}

		if ( ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return 'empty';
		}

		return '';
	}

	/**
	 * What importing would do.
	 *
	 * @param array $current  Stored settings.
	 * @param array $incoming The file's settings.
	 * @param array $known    Option ids this version defines.
	 *
	 * @return array{change:array,same:int,unknown:array,values:array}
	 */
	public static function plan( array $current, array $incoming, array $known, array $display = [] ) {
		$known   = array_flip( $known );
		$display = array_flip( $display );
		$change = [];
		$same   = 0;
		$unk    = [];
		$values = [];

		foreach ( $incoming as $key => $value ) {
			// Headings and report panels are stored as empty strings by the
			// settings form, but they are not settings: skip them silently
			// rather than reporting them as something being thrown away.
			if ( isset( $display[ $key ] ) ) {
				continue;
			}

			if ( ! isset( $known[ $key ] ) ) {
				$unk[] = (string) $key;
				continue;
			}

			$values[ $key ] = $value;

			if ( array_key_exists( $key, $current ) && $current[ $key ] === $value ) {
				$same ++;
			} else {
				$change[] = (string) $key;
			}
		}

		sort( $unk );

		return [ 'change' => $change, 'same' => $same, 'unknown' => $unk, 'values' => $values ];
	}

	/* ---------------------------------------------------------------------- *
	 * WordPress side
	 * ---------------------------------------------------------------------- */

	/**
	 * Every option id the settings schema defines (no tabs, boxes or groups).
	 *
	 * @return string[]
	 */
	public static function known_keys( $display = false ) {
		$ext = fw()->extensions->get( 'seo' );

		if ( ! $ext ) {
			return [];
		}

		$keys = [];

		foreach ( fw_extract_only_options( (array) $ext->get_settings_options() ) as $id => $option ) {
			$is_display = in_array( $option['type'] ?? '', [ 'html', 'html-full' ], true );

			// Settings by default; with $display, the headings and report
			// panels the form stores as empty strings but that hold nothing.
			if ( $is_display === (bool) $display ) {
				$keys[] = (string) $id;
			}
		}

		return $keys;
	}

	/**
	 * The stored settings that are real settings — what an export carries.
	 *
	 * @return array
	 */
	public static function exportable() {
		return array_intersect_key( self::current(), array_flip( self::known_keys() ) );
	}

	/**
	 * @return array
	 */
	public static function current() {
		return (array) fw_get_db_ext_settings_option( 'seo' );
	}

	protected static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'fw' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( $action );
	}

	protected static function back( array $args = [] ) {
		$url = FW_SEO_Settings_Page::url( 'import_tab' );

		return ( $args ? add_query_arg( $args, $url ) : $url ) . '#fw-seo-transfer';
	}

	/** @internal */
	public static function _action_export() {
		self::guard( 'fw_seo_settings_export' );

		$secrets = ! empty( $_GET['secrets'] ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
		$ext     = fw()->extensions->get( 'seo' );

		$json = wp_json_encode(
			self::payload( self::exportable(), $secrets, [
				'site'      => home_url(),
				'extension' => $ext ? $ext->manifest->get_version() : '',
			] ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="seo-settings-' . sanitize_file_name( $host ) . '-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput -- a JSON download.
		exit;
	}

	/** @internal */
	public static function _action_upload() {
		self::guard( 'fw_seo_settings_upload' );

		$file = $_FILES['fw_seo_settings_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! $file || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_safe_redirect( self::back( [ 'fw-seo-transfer' => 'nofile' ] ) );
			exit;
		}

		// A settings file is a few kilobytes; anything near a megabyte is not one.
		if ( filesize( $file['tmp_name'] ) > MB_IN_BYTES ) {
			wp_safe_redirect( self::back( [ 'fw-seo-transfer' => 'toobig' ] ) );
			exit;
		}

		$data   = json_decode( (string) file_get_contents( $file['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$reason = self::validate( $data );

		if ( '' !== $reason ) {
			wp_safe_redirect( self::back( [ 'fw-seo-transfer' => $reason ] ) );
			exit;
		}

		set_transient( self::PENDING_TRANSIENT . '_' . get_current_user_id(), $data, HOUR_IN_SECONDS );

		wp_safe_redirect( self::back( [ 'fw-seo-transfer' => 'preview' ] ) );
		exit;
	}

	/** @internal */
	public static function _action_apply() {
		self::guard( 'fw_seo_settings_apply' );

		$key  = self::PENDING_TRANSIENT . '_' . get_current_user_id();
		$data = get_transient( $key );

		if ( '' !== self::validate( $data ) ) {
			wp_safe_redirect( self::back( [ 'fw-seo-transfer' => 'expired' ] ) );
			exit;
		}

		$before = self::current();
		$plan   = self::plan( $before, (array) $data['settings'], self::known_keys(), self::known_keys( true ) );

		// Merged over what is stored: a key the file does not carry — a
		// credential left out of the export, say — keeps its current value.
		fw_set_db_ext_settings_option( 'seo', null, array_merge( $before, $plan['values'] ) );
		FW_SEO_Settings::flush();
		delete_transient( $key );

		/** Same signal the settings form sends, so listeners react to an import too. */
		do_action( 'fw_extension_settings_form_saved:seo', $before );

		wp_safe_redirect( self::back( [ 'fw-seo-transfer' => 'done', 'changed' => count( $plan['change'] ) ] ) );
		exit;
	}

	/** @internal */
	public static function _action_cancel() {
		self::guard( 'fw_seo_settings_cancel' );
		delete_transient( self::PENDING_TRANSIENT . '_' . get_current_user_id() );
		wp_safe_redirect( self::back() );
		exit;
	}

	/**
	 * The panel on the Import / Export view.
	 *
	 * @return string
	 */
	public static function panel_html() {
		// phpcs:disable WordPress.Security.NonceVerification -- display only.
		$notice  = isset( $_GET['fw-seo-transfer'] ) ? sanitize_key( wp_unslash( $_GET['fw-seo-transfer'] ) ) : '';
		$changed = isset( $_GET['changed'] ) ? (int) $_GET['changed'] : 0;
		// phpcs:enable
		$pending = get_transient( self::PENDING_TRANSIENT . '_' . get_current_user_id() );
		$export  = wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_settings_export' ), 'fw_seo_settings_export' );

		$messages = [
			'nofile'  => __( 'No file arrived. Choose a .json settings file and try again.', 'fw' ),
			'toobig'  => __( 'That file is far too large to be a settings file.', 'fw' ),
			'notjson' => __( 'That file is not valid JSON.', 'fw' ),
			'notours' => __( 'That file is not an SEO settings export from Unyson+.', 'fw' ),
			'newer'   => __( 'That file comes from a newer version of the SEO extension. Update this site first, then import it.', 'fw' ),
			'empty'   => __( 'That file has no settings in it.', 'fw' ),
			'expired' => __( 'The upload had expired before it was applied. Upload the file again.', 'fw' ),
		];

		ob_start();
		?>
		<hr style="margin:2em 0 1.5em" />
		<h3 id="fw-seo-transfer" style="margin:0 0 .4em"><?php esc_html_e( 'Move your settings to another site', 'fw' ); ?></h3>
		<p class="description" style="max-width:46em">
			<?php esc_html_e( 'Every SEO setting on this site — title templates, sitemap choices, sharing defaults, structured data — in one file you can import on another site. Per-page values travel in the spreadsheet above instead.', 'fw' ); ?>
		</p>

		<?php if ( 'done' === $notice ) : ?>
			<div class="notice notice-success inline" style="margin:1em 0;max-width:46em"><p>
				<?php
				printf(
					/* translators: %s: number of settings. */
					esc_html( _n( 'Imported. %s setting changed.', 'Imported. %s settings changed.', $changed, 'fw' ) ),
					esc_html( number_format_i18n( $changed ) )
				);
				?>
			</p></div>
		<?php elseif ( isset( $messages[ $notice ] ) ) : ?>
			<div class="notice notice-error inline" style="margin:1em 0;max-width:46em"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div>
		<?php endif; ?>

		<p>
			<a class="button button-secondary" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export settings', 'fw' ); ?></a>
			<a class="button-link" style="margin-left:8px" href="<?php echo esc_url( add_query_arg( 'secrets', 1, $export ) ); ?>"
				onclick="return confirm( <?php echo esc_attr( wp_json_encode( __( 'Include the API key and the Search Console client secret in the file? Anyone who gets the file gets them too.', 'fw' ) ) ); ?> );">
				<?php esc_html_e( 'Export including API keys', 'fw' ); ?>
			</a>
		</p>
		<p class="description" style="max-width:46em">
			<?php esc_html_e( 'API keys and the Search Console client secret are left out unless you ask for them. The Search Console connection itself never moves: it belongs to this site, so the new site connects on its own.', 'fw' ); ?>
		</p>

		<?php if ( is_array( $pending ) && '' === self::validate( $pending ) ) :
			$plan = self::plan( self::current(), (array) $pending['settings'], self::known_keys(), self::known_keys( true ) );
			?>
			<div class="notice notice-warning inline" style="margin:1em 0;max-width:46em">
				<p><strong><?php esc_html_e( 'Ready to import — nothing has been written yet.', 'fw' ); ?></strong></p>
				<ul style="list-style:disc;margin-left:1.6em">
					<?php if ( ! empty( $pending['site'] ) ) : ?>
						<li><?php
							printf(
								/* translators: 1: site address, 2: date. */
								esc_html__( 'Exported from %1$s on %2$s.', 'fw' ),
								'<code>' . esc_html( $pending['site'] ) . '</code>',
								esc_html( wp_date( get_option( 'date_format' ), strtotime( (string) $pending['exported'] ) ) )
							);
						?></li>
					<?php endif; ?>
					<li><?php
						printf(
							/* translators: 1: settings that would change, 2: settings already the same. */
							esc_html( _n( '%1$s setting would change; %2$s are already the same here.', '%1$s settings would change; %2$s are already the same here.', count( $plan['change'] ), 'fw' ) ),
							esc_html( number_format_i18n( count( $plan['change'] ) ) ),
							esc_html( number_format_i18n( $plan['same'] ) )
						);
					?></li>
					<?php if ( $plan['unknown'] ) : ?>
						<li><?php
							printf(
								/* translators: %s: number of settings. */
								esc_html__( '%s settings in the file are not known to this version and will be ignored.', 'fw' ),
								esc_html( number_format_i18n( count( $plan['unknown'] ) ) )
							);
						?></li>
					<?php endif; ?>
					<li><?php esc_html_e( 'Settings the file does not mention keep their current value.', 'fw' ); ?></li>
				</ul>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_settings_apply' ), 'fw_seo_settings_apply' ) ); ?>"><?php esc_html_e( 'Apply these settings', 'fw' ); ?></a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_settings_cancel' ), 'fw_seo_settings_cancel' ) ); ?>"><?php esc_html_e( 'Discard', 'fw' ); ?></a>
				</p>
			</div>
		<?php else : ?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:.5em">
				<input type="hidden" name="action" value="fw_seo_settings_upload" />
				<?php wp_nonce_field( 'fw_seo_settings_upload' ); ?>
				<input type="file" name="fw_seo_settings_file" accept=".json,application/json" required />
				<button type="submit" class="button"><?php esc_html_e( 'Upload and preview', 'fw' ); ?></button>
			</form>
		<?php endif; ?>
		<?php

		return (string) ob_get_clean();
	}
}
