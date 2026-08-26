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
			</h2>

			<form method="post" action="">
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
		</div>

		<style>
		.fw-ext-seo-settings .fw-seo-tabs{margin-top:.4em;border-bottom:1px solid #c3c4c7}
		.fw-ext-seo-settings .fw-seo-tabs .nav-tab{border-radius:.25rem .25rem 0 0}
		.fw-ext-seo-settings .fw-seo-panel{display:none}
		.fw-ext-seo-settings .fw-seo-panel.is-active{display:block}
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

			if ( hash && wrap.querySelector( '#panel-' + hash ) ) { activate( hash ); }
		}() );
		</script>
		<?php
	}
}
