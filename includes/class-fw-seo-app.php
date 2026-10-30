<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The SEO screens' navigation.
 *
 * The frame itself — the folded WordPress menu, the sidebar, the top bar, focus
 * mode — is the framework's `FW_Admin_App`. What is left here is the part that
 * is actually about SEO: which views exist, how they are grouped, and keeping
 * that list honest against the options schema.
 *
 * SEO earns the frame because it is not one settings page: it is search
 * appearance, a sitemap, sharing cards, structured data and four reports. As
 * horizontal tabs that is a row of eleven labels that wraps, where the tab you
 * want is never the one in front of you.
 */
class FW_SEO_App {

	/** @var FW_Admin_App|null */
	protected static $app = null;

	/**
	 * The shell, built once.
	 *
	 * @return FW_Admin_App
	 */
	public static function app() {
		if ( self::$app ) {
			return self::$app;
		}

		// The framework loads the shell on admin screens only, which is where
		// these pages live — but a URL built from a redirect handler, or a test
		// run under WP-CLI, can reach this first.
		if ( ! class_exists( 'FW_Admin_App' ) ) {
			require_once fw_get_framework_directory( '/includes/admin-app/class-fw-admin-app.php' );
		}

		self::$app = new FW_Admin_App( 'seo', array(
			'brand'      => array(
				'label' => __( 'SEO', 'fw' ),
				'icon'  => 'dashicons-search',
			),
			'page'       => FW_SEO_Settings_Page::PAGE_SLUG,
			'arg'        => 'view',
			'default'    => FW_SEO_Settings_Page::DEFAULT_VIEW,
			'capability' => FW_SEO_Settings_Page::CAPABILITY,
			// A callable, not an array: the menu is reconciled with the options
			// schema at render time, and the schema is filterable.
			'nav'        => array( __CLASS__, 'nav_now' ),
		) );

		return self::$app;
	}

	/**
	 * The sidebar, in groups.
	 *
	 * Grouped by the question each view answers rather than by what it is made
	 * of: half of them are settings forms and half are reports, and filing those
	 * two kinds together would be a filing system rather than a menu.
	 *
	 * The keys are the tab keys from `settings-options.php`, plus `import_tab`,
	 * which is an action rather than a setting and so has no tab in the schema.
	 *
	 * @return array group => { label, items: view => { label, icon } }
	 */
	public static function nav() {
		return array(
			'appearance' => array(
				'label' => __( 'Search appearance', 'fw' ),
				'items' => array(
					'general_tab'   => array( 'label' => __( 'General', 'fw' ), 'icon' => 'dashicons-admin-generic' ),
					'templates_tab' => array( 'label' => __( 'Titles & meta', 'fw' ), 'icon' => 'dashicons-editor-textcolor' ),
					'social_tab'    => array( 'label' => __( 'Sharing cards', 'fw' ), 'icon' => 'dashicons-share' ),
					'schema_tab'    => array( 'label' => __( 'Structured data', 'fw' ), 'icon' => 'dashicons-networking' ),
				),
			),
			'site'       => array(
				'label' => __( 'Site', 'fw' ),
				'items' => array(
					'sitemap_tab'      => array( 'label' => __( 'Sitemap', 'fw' ), 'icon' => 'dashicons-media-spreadsheet' ),
					'verification_tab' => array( 'label' => __( 'Verification', 'fw' ), 'icon' => 'dashicons-yes-alt' ),
					'redirects_tab'    => array( 'label' => __( 'Redirects', 'fw' ), 'icon' => 'dashicons-randomize' ),
					'robots_tab'       => array( 'label' => __( 'robots.txt', 'fw' ), 'icon' => 'dashicons-shield' ),
				),
			),
			'reports'    => array(
				'label' => __( 'Reports', 'fw' ),
				'items' => array(
					'alerts_tab'       => array( 'label' => __( 'Alerts', 'fw' ), 'icon' => 'dashicons-bell' ),
					'health_tab'       => array( 'label' => __( 'Site health', 'fw' ), 'icon' => 'dashicons-heart' ),
					'console_tab'   => array( 'label' => __( 'Search Console', 'fw' ), 'icon' => 'dashicons-chart-line' ),
					'coverage_tab'  => array( 'label' => __( 'Index coverage', 'fw' ), 'icon' => 'dashicons-visibility' ),
					'rank_tab'      => array( 'label' => __( 'Where pages rank', 'fw' ), 'icon' => 'dashicons-sort' ),
					'search_tab'    => array( 'label' => __( 'Site search', 'fw' ), 'icon' => 'dashicons-search' ),
					'links_tab'        => array( 'label' => __( 'Links', 'fw' ), 'icon' => 'dashicons-admin-links' ),
					'freshness_tab' => array( 'label' => __( 'Going stale', 'fw' ), 'icon' => 'dashicons-backup' ),
					'vitals_tab'    => array( 'label' => __( 'Page speed', 'fw' ), 'icon' => 'dashicons-performance' ),
				),
			),
			'tools'      => array(
				'label' => __( 'Tools', 'fw' ),
				'items' => array(
					'import_tab' => array( 'label' => __( 'Import / Export', 'fw' ), 'icon' => 'dashicons-database-import' ),
				),
			),
		);
	}

	/**
	 * @internal The `nav` callable handed to FW_Admin_App.
	 *
	 * @return array
	 */
	public static function nav_now() {
		return self::nav_for( FW_SEO_Settings_Page::view_labels() );
	}

	/**
	 * The sidebar reconciled with the views that actually exist.
	 *
	 * A tab added to `settings-options.php` is reachable the moment it exists,
	 * in a trailing "More" group, rather than silently unreachable until someone
	 * remembers to list it above; a tab filtered out of the schema loses its
	 * link rather than leading to an empty page.
	 *
	 * @param array $views view => label, in schema order.
	 *
	 * @return array
	 */
	public static function nav_for( array $views ) {
		$nav   = self::nav();
		$known = array();

		foreach ( $nav as $group ) {
			$known = array_merge( $known, array_keys( $group['items'] ) );
		}

		foreach ( $nav as $key => $group ) {
			$nav[ $key ]['items'] = array_intersect_key( $group['items'], $views );

			if ( ! $nav[ $key ]['items'] ) {
				unset( $nav[ $key ] );
			}
		}

		$extra = array_diff( array_keys( $views ), $known );

		if ( $extra ) {
			$items = array();

			foreach ( $extra as $view ) {
				$items[ $view ] = array( 'label' => $views[ $view ], 'icon' => 'dashicons-admin-settings' );
			}

			$nav['more'] = array( 'label' => __( 'More', 'fw' ), 'items' => $items );
		}

		return $nav;
	}
}
