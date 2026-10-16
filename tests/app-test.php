<?php
/**
 * Contract tests for the SEO app shell and its view routing.
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/app-test.php
 *
 * Exit 0 = all pass, 1 = something regressed.
 *
 * Pins the two things that break quietly: the sidebar reconciling itself with
 * whatever tabs the schema actually has (a new tab must not be unreachable, a
 * removed one must not leave a link to an empty page), and `wants()`, which
 * decides whether a report is generated at all — get it wrong in the lenient
 * direction and every page load runs four queries it does not need; get it
 * wrong in the strict direction and a report is permanently blank.
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

// The frame is the framework's; only the navigation is the extension's. Under
// WP-CLI `is_admin()` is false, so the bootstrap has not loaded the shell.
require_once WP_PLUGIN_DIR . '/unysonplus/framework/includes/admin-app/class-fw-admin-app.php';
require_once $base . '/includes/class-fw-seo-settings-page.php';
require_once $base . '/includes/class-fw-seo-app.php';

// WP-CLI runs this file inside a function, so the counters cannot be plain
// locals shared with a helper — they live on a class, as in the other suites.
class FW_SEO_App_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_app_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_App_T::$pass ++;
		printf( "  ok   %s
", $label );

		return;
	}

	FW_SEO_App_T::$fail ++;
	printf( "  FAIL %s
         expected: %s
         actual:   %s
", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/* -------------------------------------------------------------------------- *
 * url()
 * -------------------------------------------------------------------------- */

$plain = admin_url( 'admin.php?page=fw-seo-settings' );

fw_seo_app_is( 'url() with no view is the bare page', FW_SEO_Settings_Page::url(), $plain );
fw_seo_app_is(
	'url() of the default view is the bare page too — one URL per screen, not two',
	FW_SEO_Settings_Page::url( FW_SEO_Settings_Page::DEFAULT_VIEW ),
	$plain
);
fw_seo_app_is(
	'url() of another view carries it',
	FW_SEO_Settings_Page::url( 'console_tab' ),
	add_query_arg( 'view', 'console_tab', $plain )
);

/* -------------------------------------------------------------------------- *
 * wants() — the report guard
 * -------------------------------------------------------------------------- */

$get = $_GET;

$_GET = array();
fw_seo_app_is(
	'off the settings page, every report is still built',
	array( FW_SEO_Settings_Page::wants( 'search_tab' ), FW_SEO_Settings_Page::wants( 'console_tab' ) ),
	array( true, true )
);

$_GET = array( 'page' => 'fw-extensions' );
fw_seo_app_is(
	'the Extensions manager reads the whole tree',
	FW_SEO_Settings_Page::wants( 'vitals_tab' ),
	true
);

$_GET = array( 'page' => 'fw-seo-settings' );
fw_seo_app_is(
	'a bare settings URL wants only the default view',
	array( FW_SEO_Settings_Page::wants( 'general_tab' ), FW_SEO_Settings_Page::wants( 'console_tab' ) ),
	array( true, false )
);

$_GET = array( 'page' => 'fw-seo-settings', 'view' => 'console_tab' );
fw_seo_app_is(
	'a view wants its own report and no other',
	array(
		FW_SEO_Settings_Page::wants( 'console_tab' ),
		FW_SEO_Settings_Page::wants( 'search_tab' ),
		FW_SEO_Settings_Page::wants( 'vitals_tab' ),
		FW_SEO_Settings_Page::wants( 'freshness_tab' ),
	),
	array( true, false, false, false )
);

$_GET = $get;

/* -------------------------------------------------------------------------- *
 * The shared shell
 * -------------------------------------------------------------------------- */

fw_seo_app_is(
	'SEO opens the framework shell rather than one of its own',
	FW_SEO_App::app() instanceof FW_Admin_App,
	true
);

fw_seo_app_is(
	'the shell is built once, so its nav callable is not re-registered per call',
	FW_SEO_App::app() === FW_SEO_App::app(),
	true
);

fw_seo_app_is(
	'focus mode is one preference for every app, not one per app',
	FW_Admin_App::FOCUS_META,
	'fw_admin_app_focus'
);

fw_seo_app_is(
	'the shell drops an item the current user lacks the capability for',
	array_keys( ( new FW_Admin_App( 'test', array(
		'page' => 'x',
		'nav'  => array(
			'g' => array(
				'label' => 'G',
				'items' => array(
					'yes' => array( 'label' => 'Yes' ),
					'no'  => array( 'label' => 'No', 'cap' => 'a_capability_nobody_has' ),
				),
			),
		),
	) ) )->nav()['g']['items'] ),
	array( 'yes' )
);

fw_seo_app_is(
	'and drops a group that is left with nothing in it',
	( new FW_Admin_App( 'test', array(
		'page' => 'x',
		'nav'  => array(
			'g' => array(
				'label' => 'G',
				'items' => array( 'no' => array( 'label' => 'No', 'cap' => 'a_capability_nobody_has' ) ),
			),
		),
	) ) )->nav(),
	array()
);

/* -------------------------------------------------------------------------- *
 * nav_for() — the sidebar against the real schema
 * -------------------------------------------------------------------------- */

$nav_keys = static function ( array $nav ) {
	$keys = array();

	foreach ( $nav as $group ) {
		$keys = array_merge( $keys, array_keys( $group['items'] ) );
	}

	return $keys;
};

$all = array(
	'general_tab'      => 'General',
	'templates_tab'    => 'Titles & meta',
	'sitemap_tab'      => 'Sitemap',
	'social_tab'       => 'Sharing cards',
	'search_tab'       => 'Site search',
	'freshness_tab'    => 'Going stale',
	'vitals_tab'       => 'Page speed',
	'console_tab'      => 'Search Console',
	'schema_tab'       => 'Structured data',
	'verification_tab' => 'Verification',
	'import_tab'       => 'Import / Export',
);

$nav = FW_SEO_App::nav_for( $all );

fw_seo_app_is(
	'every view the page offers has a link',
	array_values( array_diff( array_keys( $all ), $nav_keys( $nav ) ) ),
	array()
);

fw_seo_app_is(
	'and no link points at a view that does not exist',
	array_values( array_diff( $nav_keys( $nav ), array_keys( $all ) ) ),
	array()
);

fw_seo_app_is(
	'the groups are in a deliberate order, not the schema\'s',
	array_keys( $nav ),
	array( 'appearance', 'site', 'reports', 'tools' )
);

$short = array( 'general_tab' => 'General', 'import_tab' => 'Import / Export' );
$nav   = FW_SEO_App::nav_for( $short );

fw_seo_app_is(
	'a filtered-out tab loses its link rather than leading nowhere',
	$nav_keys( $nav ),
	array( 'general_tab', 'import_tab' )
);

fw_seo_app_is(
	'and a group left with nothing in it disappears',
	array_keys( $nav ),
	array( 'appearance', 'tools' )
);

$with_new = $all;
$with_new['experiments_tab'] = 'Experiments';
$nav = FW_SEO_App::nav_for( $with_new );

fw_seo_app_is(
	'a tab nobody listed here is still reachable the moment it exists',
	in_array( 'experiments_tab', $nav_keys( $nav ), true ),
	true
);

fw_seo_app_is(
	'in a trailing group, so it does not pretend to be filed',
	array_slice( array_keys( $nav ), -1 ),
	array( 'more' )
);

/* -------------------------------------------------------------------------- *
 * The schema and the sidebar agree
 * -------------------------------------------------------------------------- */

$ext = fw()->extensions->get( 'seo' );

if ( $ext ) {
	$tabs = array();

	foreach ( (array) $ext->get_settings_options() as $key => $entry ) {
		if ( is_array( $entry ) && isset( $entry['type'] ) && 'tab' === $entry['type'] ) {
			$tabs[ $key ] = true;
		}
	}

	$listed = array();

	foreach ( FW_SEO_App::nav() as $group ) {
		$listed = array_merge( $listed, array_keys( $group['items'] ) );
	}

	// `import_tab` is an action rather than a setting, so it has no tab.
	$listed = array_diff( $listed, array( 'import_tab' ) );

	fw_seo_app_is(
		'every tab in the schema is filed in a named group',
		array_values( array_diff( array_keys( $tabs ), $listed ) ),
		array()
	);

	fw_seo_app_is(
		'and the sidebar names no tab the schema lost',
		array_values( array_diff( $listed, array_keys( $tabs ) ) ),
		array()
	);

	fw_seo_app_is(
		'the default view is a real tab',
		isset( $tabs[ FW_SEO_Settings_Page::DEFAULT_VIEW ] ),
		true
	);
} else {
	echo "NOTE  the SEO extension is not active here; schema checks skipped\n";
}

printf( "\n%d passed, %d failed\n", FW_SEO_App_T::$pass, FW_SEO_App_T::$fail );

exit( FW_SEO_App_T::$fail ? 1 : 0 );
