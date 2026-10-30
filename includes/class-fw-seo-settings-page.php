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
 * One view at a time, chosen from the sidebar in `FW_SEO_App` and addressed by
 * `&view=<tab>`. It used to be every tab rendered at once and switched with
 * JavaScript, which meant a row of labels that wrapped, a URL that said nothing
 * about where you were, and — the real cost — four reports queried on every
 * load of the page, including the three you were not looking at.
 */
class FW_SEO_Settings_Page {

	const PARENT_SLUG = 'fw-extensions';
	const PAGE_SLUG   = 'fw-seo-settings';
	const CAPABILITY  = 'manage_options';

	/** The view a bare page URL lands on. */
	const DEFAULT_VIEW = 'general_tab';
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
	 * @param string $view A view to open, or '' for the default one.
	 *
	 * @return string
	 */
	public static function url( $view = '' ) {
		return FW_SEO_App::app()->url( $view );
	}

	/**
	 * The view this request is for, or null when the request is not this page.
	 *
	 * Deliberately reads the request rather than taking a parameter: the options
	 * schema is built by a file that has no idea which screen asked for it, and
	 * the reports inside it are queries, not settings. See `wants()`.
	 *
	 * @return string|null
	 */
	protected static function requested_view() {
		return FW_SEO_App::app()->requested_view();
	}

	/**
	 * Whether a report belonging to `$view` is worth generating for this request.
	 *
	 * Each report in `settings-options.php` is a query — site-search totals, a
	 * freshness scan, a Chrome UX Report fetch, a Search Console call — run while
	 * the schema is built. With one view on screen, running the other three is
	 * work nobody asked for. Anything that is not this page (the Extensions
	 * manager, a programmatic read) still gets the whole tree.
	 *
	 * @param string $view
	 *
	 * @return bool
	 */
	public static function wants( $view ) {
		$current = self::requested_view();

		return null === $current || $view === $current;
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

		// SEO is promoted to the TOP LEVEL (beside the other apps) rather than living in
		// the Unyson+ (developer / site-config) menu - it is a surface site owners visit
		// often, the way Yoast / RankMath sit at the top level. Previously a Unyson+
		// submenu.
		$this->hook_suffix = add_menu_page(
			__( 'SEO', 'fw' ),
			__( 'SEO', 'fw' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render' ],
			'dashicons-search',
			30
		);

		if ( ! $this->hook_suffix ) {
			return;
		}

		FW_SEO_App::app()->attach( $this->hook_suffix );

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

		// The frame's own CSS/JS comes from FW_Admin_App; this is the styling of
		// the options form that sits inside it.
		wp_enqueue_style(
			'fw-ext-seo-settings',
			fw_min_uri( $this->extension->get_declared_URI( '/static/css/settings.css' ) ),
			[ 'fw-admin-app' ],
			$this->extension->manifest->get_version()
		);

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
		$view   = $this->current_view();
		$tabs   = $this->tabs();

		// Merged over what is stored, and parsed from the ONE tab that was on
		// screen. Parsing the whole tree would read defaults for every option
		// this form never rendered and write them over what is saved — the
		// Sitemap tab would quietly reset Social every time it was saved.
		$values = array_merge(
			$before,
			fw_get_options_values_from_input( (array) ( $tabs[ $view ]['options'] ?? [] ) )
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

		wp_safe_redirect( add_query_arg( 'fw-saved', '1', self::url( $view ) ) );
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
	 * Every view in the sidebar: the schema's tabs, then Import/Export, which is
	 * an action rather than a setting and so has no tab in the schema.
	 *
	 * @return array<string,string> view => label, in menu order.
	 */
	protected function views() {
		return self::view_labels();
	}

	/**
	 * The same list, readable without an instance — the sidebar is built from it
	 * while the shell renders, which is outside this object.
	 *
	 * @return array<string,string> view => label, in menu order.
	 */
	public static function view_labels() {
		$views = [];
		$ext   = fw()->extensions->get( 'seo' );

		if ( $ext ) {
			foreach ( (array) $ext->get_settings_options() as $key => $entry ) {
				if ( is_array( $entry ) && isset( $entry['type'] ) && 'tab' === $entry['type'] ) {
					$views[ $key ] = (string) ( $entry['title'] ?? $key );
				}
			}
		}

		$views['import_tab'] = __( 'Import / Export', 'fw' );

		return $views;
	}

	/**
	 * The view to render: what was asked for if it exists, the default if not.
	 *
	 * @return string
	 */
	protected function current_view() {
		$views = $this->views();
		$view  = (string) self::requested_view();

		return isset( $views[ $view ] ) ? $view : self::DEFAULT_VIEW;
	}

	/**
	 * @internal
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$views  = $this->views();
		$view   = $this->current_view();
		$tabs   = $this->tabs();
		$values = (array) fw_get_db_ext_settings_option( 'seo' );

		FW_SEO_App::app()->open( $view, [
			'title'  => $views[ $view ],
			'crumbs' => [ [ __( 'SEO', 'fw' ), self::url() ] ],
			'class'  => 'fw-ext-seo-settings',
		] );

		if ( isset( $_GET['fw-saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Settings saved.', 'fw' ); ?></p>
			</div>
			<?php
		endif;

		if ( self::DEFAULT_VIEW === $view ) :
			?>
			<p class="description fw-seo-intro">
				<?php esc_html_e( 'Titles, descriptions, sharing cards, structured data and sitemaps. Every field has a working default — you only need to change what you want to differ from what the site already produces.', 'fw' ); ?>
			</p>
			<?php
		endif;

		if ( 'import_tab' === $view ) {
			// Not a form of settings: its own buttons act immediately, and a
			// "Save Changes" here would be answering a question nobody asked.
			echo $this->import_panel(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the panel.
		} else {
			?>
			<form method="post" action="<?php echo esc_url( self::url( $view ) ); ?>" id="fw-seo-settings-form">
				<?php wp_nonce_field( self::NONCE ); ?>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput -- the options renderer escapes.
				$html = fw()->backend->render_options( (array) ( $tabs[ $view ]['options'] ?? [] ), $values );

				// Reports that need WordPress fully loaded are filled in now, not
				// while the schema was being built (see FW_SEO_Redirects::PLACEHOLDER).
				if ( false !== strpos( $html, FW_SEO_Redirects::PLACEHOLDER ) ) {
					$html = str_replace( FW_SEO_Redirects::PLACEHOLDER, FW_SEO_Redirects::report_html(), $html );
				}
				if ( false !== strpos( $html, FW_SEO_Links::PLACEHOLDER ) ) {
					$html = str_replace( FW_SEO_Links::PLACEHOLDER, FW_SEO_Links::report_html(), $html );
				}
				if ( false !== strpos( $html, FW_SEO_Alerts::PLACEHOLDER ) ) {
					$html = str_replace( FW_SEO_Alerts::PLACEHOLDER, FW_SEO_Alerts::report_html(), $html );
				}
				if ( false !== strpos( $html, FW_SEO_Health::PLACEHOLDER ) ) {
					$html = str_replace( FW_SEO_Health::PLACEHOLDER, FW_SEO_Health::report_html(), $html );
				}
				if ( false !== strpos( $html, FW_SEO_IndexNow::PLACEHOLDER ) ) {
					$html = str_replace( FW_SEO_IndexNow::PLACEHOLDER, FW_SEO_IndexNow::status_html(), $html );
				}
				if ( false !== strpos( $html, FW_SEO_Robots::PLACEHOLDER ) ) {
					$html = str_replace( FW_SEO_Robots::PLACEHOLDER, FW_SEO_Robots::preview_html(), $html );
				}

				echo $html;
				?>
				<p class="submit fw-seo-submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'fw' ); ?></button>
				</p>
			</form>
			<?php
		}

		FW_SEO_App::app()->close();
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

			<?php echo FW_SEO_Transfer::panel_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the panel. ?>
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
