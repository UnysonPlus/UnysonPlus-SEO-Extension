<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The SEO settings page, under Unyson+ rather than buried in the Extensions
 * manager.
 *
 * Settings are still the extension's own — the same `settings-options.php`
 * schema and the same `fw_get_db_ext_settings_option()` store. Only the place
 * you reach them from changes, so nothing that reads a setting has to know this
 * page exists.
 *
 * Follows the Site Converter's page shape (native WordPress nav-tabs, one panel
 * per tab, hash deep-linking) because a settings screen that styles its own
 * tabs sits next to one that doesn't and reads as broken.
 */
class FW_SEO_Settings_Page {

	const PARENT_SLUG = 'fw-extensions';
	const PAGE_SLUG   = 'fw-seo-settings';
	const CAPABILITY  = 'manage_options';
	const NONCE       = 'fw_seo_settings_save';

	/** @var FW_Extension_SEO */
	protected $extension;

	/** @var string|null */
	protected $hook_suffix = null;

	/**
	 * @param FW_Extension_SEO $extension
	 */
	public function __construct( $extension ) {
		$this->extension = $extension;

		add_action( 'admin_menu', [ $this, '_action_admin_menu' ], 20 );

		// Point the Extensions-manager card at this page too, so there is one
		// settings screen rather than two that can disagree.
		add_filter( 'fw_ext_manager_settings_url', [ $this, '_filter_manager_url' ], 10, 2 );
	}

	/**
	 * @return string
	 */
	public static function url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * @internal
	 *
	 * @param string $url
	 * @param string $name
	 *
	 * @return string
	 */
	public function _filter_manager_url( $url, $name ) {
		return 'seo' === $name ? self::url() : $url;
	}

	/**
	 * @internal
	 */
	public function _action_admin_menu() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$this->hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'SEO Settings', 'fw' ),
			__( 'SEO', 'fw' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);

		if ( ! $this->hook_suffix ) {
			return;
		}

		// Saving happens on `load-`, before any output, so the redirect works.
		add_action( 'load-' . $this->hook_suffix, [ $this, '_action_maybe_save' ] );
		add_action( 'admin_enqueue_scripts', [ $this, '_action_enqueue' ] );
	}

	/**
	 * @internal
	 *
	 * @param string $hook
	 */
	public function _action_enqueue( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		fw()->backend->enqueue_options_static( $this->extension->get_settings_options() );

		wp_enqueue_script(
			'fw-ext-seo-import',
			$this->extension->get_declared_URI( '/static/js/import.js' ),
			[],
			$this->extension->manifest->get_version(),
			true
		);

		wp_localize_script( 'fw-ext-seo-import', 'fwSeoImport', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( FW_SEO_Import::NONCE ),
			'i18n'    => [
				'working'          => __( 'Imported %1$s of %2$s…', 'fw' ),
				'done'             => __( 'Finished. %1$s posts updated, %2$s values imported.', 'fw' ),
				'failed'           => __( 'The import stopped before finishing. Nothing already imported was lost — run it again to continue.', 'fw' ),
				'unknownTitle'     => __( 'Some template tags had no equivalent here', 'fw' ),
				'unknownBody'      => __( 'They were left in the imported text exactly as they were, so nothing is lost — but they will render as nothing until you replace them. Search for them in the fields you have imported.', 'fw' ),
				'confirmOverwrite' => __( 'This will replace SEO values you have already written here. Continue?', 'fw' ),
			],
		] );
	}

	/**
	 * @internal
	 */
	public function _action_maybe_save() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$before = (array) fw_get_db_ext_settings_option( 'seo' );

		// Merged over what is stored, not written wholesale: the schema rendered
		// here is the whole settings tree, but a future partial form (or a
		// filtered-out tab) must not silently drop the keys it never showed.
		$values = array_merge(
			$before,
			fw_get_options_values_from_input( $this->extension->get_settings_options() )
		);

		fw_set_db_ext_settings_option( 'seo', null, $values );

		// The settings reader caches the whole option array for the request.
		FW_SEO_Settings::flush();

		/**
		 * Preserved from the Extensions-manager save path, so anything already
		 * listening for the settings to change keeps working now that the form
		 * lives somewhere else.
		 */
		do_action( 'fw_extension_settings_form_saved:seo', $before );

		wp_safe_redirect( add_query_arg( 'fw-saved', '1', self::url() ) );
		exit;
	}

	/**
	 * @return array<string,array> Top-level tab entries only.
	 */
	protected function tabs() {
		$tabs = [];

		foreach ( (array) $this->extension->get_settings_options() as $key => $entry ) {
			if ( is_array( $entry ) && isset( $entry['type'] ) && 'tab' === $entry['type'] ) {
				$tabs[ $key ] = $entry;
			}
		}

		return $tabs;
	}

	/**
	 * @internal
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tabs   = $this->tabs();
		$values = (array) fw_get_db_ext_settings_option( 'seo' );
		$first  = true;
		?>
		<div class="wrap fw-ext-seo-settings">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'SEO Settings', 'fw' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Titles, descriptions, sharing cards, structured data and sitemaps. Every field has a working default — you only need to change what you want to differ from what the site already produces.', 'fw' ); ?>
			</p>

			<?php if ( isset( $_GET['fw-saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'fw' ); ?></p>
				</div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper fw-seo-tabs" style="margin:.4em 0 1.4em">
				<?php foreach ( $tabs as $tab_id => $tab ) : ?>
					<a href="#<?php echo esc_attr( $tab_id ); ?>"
					   class="nav-tab<?php echo $first ? ' nav-tab-active' : ''; ?>"
					   data-tab="<?php echo esc_attr( $tab_id ); ?>"><?php echo esc_html( $tab['title'] ?? $tab_id ); ?></a>
					<?php $first = false; ?>
				<?php endforeach; ?>
				<a href="#import_tab" class="nav-tab" data-tab="import_tab"><?php esc_html_e( 'Import/Export', 'fw' ); ?></a>
			</h2>

			<form method="post" action="" id="fw-seo-settings-form">
				<?php wp_nonce_field( self::NONCE ); ?>
				<?php $first = true; foreach ( $tabs as $tab_id => $tab ) : ?>
					<div class="fw-seo-panel<?php echo $first ? ' is-active' : ''; ?>" id="panel-<?php echo esc_attr( $tab_id ); ?>">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput -- the options renderer escapes.
						echo fw()->backend->render_options( (array) ( $tab['options'] ?? [] ), $values );
						?>
					</div>
					<?php $first = false; ?>
				<?php endforeach; ?>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'fw' ); ?></button>
				</p>
			</form>

			<div class="fw-seo-panel" id="panel-import_tab">
				<?php echo $this->import_panel(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the panel. ?>
			</div>
		</div>

		<style>
		.fw-ext-seo-settings .fw-seo-tabs{margin-top:.4em;border-bottom:1px solid #c3c4c7}
		.fw-ext-seo-settings .fw-seo-tabs .nav-tab{border-radius:.25rem .25rem 0 0}
		.fw-ext-seo-settings .fw-seo-panel{display:none}
		.fw-ext-seo-settings .fw-seo-panel.is-active{display:block}

		/* Location headings on Titles & Meta.
		   They are html-full options, so the skin renders each one like an input
		   row: a grey input column and field-sized padding around an 18px
		   heading, which reads as a filled bar rather than a label. Undo the row
		   chrome and let the heading be a quiet section rule instead. */
		body .fw-ext-seo-settings .fw-backend-option-type-html-full{margin:0;padding:0;border:0}
		/* `body` to match the Admin Skin, which styles every html-full option as a
		   callout panel — with !important, so matching it needs one too. That
		   styling is right for a callout and wrong for a heading. */
		body .fw-ext-seo-settings .fw-backend-option-type-html-full .fw-inner{background:transparent!important;border-color:transparent!important}
		.fw-ext-seo-settings .fw-seo-settings-heading{
			margin:0;padding:1.6em 20px .7em;
			border-top:1px solid #e6e6ea;
			font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;
			color:#646970;
		}
		/* The first one sits directly under the box title; a rule there would
		   double up with the box's own border. */
		.fw-ext-seo-settings .fw-backend-option-type-html-full:first-child .fw-seo-settings-heading{
			border-top:0;padding-top:1em;
		}
		.fw-ext-seo-settings .fw-seo-import-bar{height:6px;border-radius:3px;background:#dcdcde;overflow:hidden}
		.fw-ext-seo-settings .fw-seo-import-bar span{display:block;height:100%;width:0;background:var(--fw-accent, #3858e9);transition:width .2s ease}
		</style>
		<script>
		( function () {
			var wrap = document.querySelector( '.fw-ext-seo-settings' );

			if ( ! wrap ) { return; }

			function each( list, fn ) { Array.prototype.forEach.call( list, fn ); }

			function activate( tab ) {
				each( wrap.querySelectorAll( '.fw-seo-tabs .nav-tab' ), function ( a ) {
					a.classList.toggle( 'nav-tab-active', a.getAttribute( 'data-tab' ) === tab );
				} );
				each( wrap.querySelectorAll( '.fw-seo-panel' ), function ( p ) {
					p.classList.toggle( 'is-active', p.id === 'panel-' + tab );
				} );

				// Hide the whole settings form on Import/Export.
				//
				// Its panels hide with everything else, but "Save Changes" lives
				// on the FORM, not in a panel — so it used to sit at the top of a
				// tab with nothing to save. That tab has its own buttons; a
				// Save Changes there is answering a question nobody asked, and
				// disabling it would only raise the same question more quietly.
				var form = document.getElementById( 'fw-seo-settings-form' );

				if ( form ) {
					form.style.display = ( 'import_tab' === tab ) ? 'none' : '';
				}
			}

			each( wrap.querySelectorAll( '.fw-seo-tabs .nav-tab' ), function ( a ) {
				a.addEventListener( 'click', function ( e ) {
					e.preventDefault();

					var tab = a.getAttribute( 'data-tab' );

					activate( tab );

					// Deep-linkable, so "check the Sitemap tab" can be a URL.
					if ( window.history && window.history.replaceState ) {
						window.history.replaceState( null, '', '#' + tab );
					}
				} );
			} );

			var hash = ( window.location.hash || '' ).replace( /^#/, '' );

			if ( hash && wrap.querySelector( '#panel-' + hash ) ) {
				activate( hash );
			} else if ( /[?&]fw-seo-csv=/.test( window.location.search ) ) {
				// Arriving back from a CSV upload or apply. Without this the
				// redirect lands on the first tab and the preview — the whole
				// reason for the round trip — is sitting on a tab nobody opened.
				activate( 'import_tab' );
			}
		}() );
		</script>
		<?php
	}

	/**
	 * The Import panel.
	 *
	 * Not part of `settings-options.php`: it is an action, not a setting, and
	 * putting a button that rewrites three hundred posts inside a form whose
	 * other control is "Save Changes" invites pressing it by accident.
	 *
	 * @return string
	 */
	protected function import_panel() {
		$available = FW_SEO_Import::available();

		ob_start();
		?>
		<div class="fw-seo-import">
			<?php if ( ! $available ) : ?>
				<div class="notice notice-info inline" style="margin:0">
					<p>
						<?php esc_html_e( 'No SEO data from another plugin was found on this site. Yoast SEO, Rank Math, SEOPress and All in One SEO can all be imported — the plugin does not need to be active, only its data still present.', 'fw' ); ?>
					</p>
				</div>
			<?php else : ?>
				<p class="description" style="max-width:46em">
					<?php esc_html_e( 'Titles, descriptions, canonical URLs, indexing switches and sharing cards are brought across, and each plugin\'s template tags are translated into ours. Anything already written here is left alone unless you tick the box below.', 'fw' ); ?>
				</p>

				<table class="widefat striped" style="max-width:46em;margin:1em 0">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Found', 'fw' ); ?></th>
							<th style="width:9em"><?php esc_html_e( 'Posts', 'fw' ); ?></th>
							<th style="width:11em"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $available as $id => $info ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $info['label'] ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $info['count'] ) ); ?></td>
								<td>
									<button type="button" class="button fw-seo-import-run"
											data-source="<?php echo esc_attr( $id ); ?>">
										<?php esc_html_e( 'Import', 'fw' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<label>
						<input type="checkbox" id="fw-seo-import-overwrite" />
						<?php esc_html_e( 'Overwrite values already set here', 'fw' ); ?>
					</label>
				</p>

				<div class="fw-seo-import-progress" style="display:none;max-width:46em">
					<div class="fw-seo-import-bar"><span></span></div>
					<p class="fw-seo-import-status" style="margin:.5em 0 0"></p>
				</div>

				<div class="fw-seo-import-report" style="display:none;max-width:46em"></div>
			<?php endif; ?>

			<?php echo $this->csv_panel(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the panel. ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * The spreadsheet panel, below the plugin importer.
	 *
	 * @return string
	 */
	protected function csv_panel() {
		$pending = FW_SEO_CSV::pending();
		$notice  = isset( $_GET['fw-seo-csv'] ) ? sanitize_key( wp_unslash( $_GET['fw-seo-csv'] ) ) : '';

		ob_start();
		?>
		<hr style="margin:2em 0 1.5em" />

		<h3 style="margin:0 0 .4em"><?php esc_html_e( 'Bulk edit in a spreadsheet', 'fw' ); ?></h3>
		<p class="description" style="max-width:46em">
			<?php esc_html_e( 'Export every page to a CSV, edit the titles and descriptions where you can see them all at once — or hand the file to whoever writes them — then import it back. Each row carries the value the page emits today beside the one you can change, so you can tell what is worth overriding.', 'fw' ); ?>
		</p>

		<?php if ( 'done' === $notice ) : ?>
			<div class="notice notice-success inline" style="margin:1em 0;max-width:46em"><p>
				<?php
				printf(
					/* translators: 1: pages updated, 2: values changed. */
					esc_html__( 'Imported. %1$s pages updated, %2$s values changed.', 'fw' ),
					esc_html( number_format_i18n( (int) ( $_GET['rows'] ?? 0 ) ) ),
					esc_html( number_format_i18n( (int) ( $_GET['changes'] ?? 0 ) ) )
				);
				?>
			</p></div>
		<?php elseif ( 'nofile' === $notice ) : ?>
			<div class="notice notice-error inline" style="margin:1em 0;max-width:46em"><p>
				<?php esc_html_e( 'No file arrived. Choose a .csv and try again.', 'fw' ); ?>
			</p></div>
		<?php endif; ?>

		<p>
			<a class="button button-secondary"
			   href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_csv_export' ), 'fw_seo_csv_export' ) ); ?>">
				<?php esc_html_e( 'Export CSV', 'fw' ); ?>
			</a>
		</p>

		<?php if ( $pending && '' === $pending['error'] ) :
			$preview  = FW_SEO_CSV::process( $pending['rows'], $pending['headers'], false );
			$editable = array_intersect_key( FW_SEO_CSV::editable(), array_flip( $pending['headers'] ) );
			?>
			<div class="notice notice-warning inline" style="margin:1em 0;max-width:46em">
				<p><strong><?php esc_html_e( 'Ready to import — nothing has been written yet.', 'fw' ); ?></strong></p>
				<ul style="list-style:disc;margin-left:1.6em">
					<li><?php
						printf(
							/* translators: 1: rows read, 2: pages that would change, 3: values. */
							esc_html__( '%1$s rows read. %2$s pages would change, %3$s values in all.', 'fw' ),
							esc_html( number_format_i18n( count( $pending['rows'] ) ) ),
							esc_html( number_format_i18n( $preview['rows'] ) ),
							esc_html( number_format_i18n( $preview['changes'] ) )
						);
					?></li>
					<?php if ( $preview['clears'] ) : ?>
						<li><strong><?php
							printf(
								/* translators: %s: number of values. */
								esc_html__( '%s values would be CLEARED (the cell is empty), putting those pages back on their template.', 'fw' ),
								esc_html( number_format_i18n( $preview['clears'] ) )
							);
						?></strong></li>
					<?php endif; ?>
					<li><?php
						printf(
							/* translators: %s: comma-separated column names. */
							esc_html__( 'Columns that will be written: %s. Anything not in the file is left alone.', 'fw' ),
							esc_html( implode( ', ', array_keys( $editable ) ) ?: __( 'none', 'fw' ) )
						);
					?></li>
					<?php if ( $preview['missing'] ) : ?>
						<li><?php
							printf(
								/* translators: %s: number of rows. */
								esc_html__( '%s rows refer to posts that are not on this site. They are ignored — nothing is created or removed.', 'fw' ),
								esc_html( number_format_i18n( count( $preview['missing'] ) ) )
							);
						?></li>
					<?php endif; ?>
				</ul>
				<p>
					<a class="button button-primary"
					   href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_csv_apply' ), 'fw_seo_csv_apply' ) ); ?>">
						<?php esc_html_e( 'Apply these changes', 'fw' ); ?>
					</a>
					<a class="button"
					   href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_csv_cancel' ), 'fw_seo_csv_cancel' ) ); ?>">
						<?php esc_html_e( 'Discard', 'fw' ); ?>
					</a>
				</p>
			</div>
		<?php elseif ( $pending ) : ?>
			<div class="notice notice-error inline" style="margin:1em 0;max-width:46em">
				<p><?php echo esc_html( $pending['error'] ); ?></p>
				<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_csv_cancel' ), 'fw_seo_csv_cancel' ) ); ?>"><?php esc_html_e( 'Discard', 'fw' ); ?></a></p>
			</div>
		<?php else : ?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:.5em">
				<input type="hidden" name="action" value="fw_seo_csv_upload" />
				<?php wp_nonce_field( 'fw_seo_csv_upload' ); ?>
				<input type="file" name="fw_seo_csv_file" accept=".csv,text/csv" required />
				<button type="submit" class="button"><?php esc_html_e( 'Upload and preview', 'fw' ); ?></button>
			</form>
			<p class="description" style="max-width:46em;margin-top:.6em">
				<?php esc_html_e( 'Rows are matched on post ID. A row for a post that is not here is ignored — importing never creates or deletes a post, and never touches anything but its SEO fields.', 'fw' ); ?>
			</p>
		<?php endif; ?>
		<?php

		return (string) ob_get_clean();
	}
}
