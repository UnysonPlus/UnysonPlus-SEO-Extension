<?php
/**
 * Contract tests for moving SEO settings between sites (FW_SEO_Transfer).
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/transfer-test.php
 *
 * The promise being pinned: what is exported imports back unchanged, a file
 * can only touch keys this version knows, and credentials only travel when
 * someone asked for them.
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

require_once $base . '/includes/class-fw-seo-transfer.php';

class FW_SEO_Transfer_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_transfer_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_Transfer_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_Transfer_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

$settings = array(
	'separator'             => '|',
	'sitemap_enabled'       => 'yes',
	'crux_api_key'          => 'AIza-SECRET',
	'console_client_id'     => 'client.apps',
	'console_client_secret' => 'shh',
	'templates'             => array( 'post' => array( 'title' => '%%title%% %%sep%% %%sitename%%' ) ),
);

/* -------------------------------------------------------------------------- *
 * payload()
 * -------------------------------------------------------------------------- */

$p = FW_SEO_Transfer::payload( $settings, false, array( 'site' => 'https://a.test', 'extension' => '2.0.35' ) );

fw_seo_transfer_is( 'the file says what it is', array( $p['format'], $p['version'] ), array( 'unysonplus-seo-settings', 1 ) );
fw_seo_transfer_is( 'credentials stay behind by default', array_values( array_intersect( array_keys( $p['settings'] ), FW_SEO_Transfer::SECRETS ) ), array() );
fw_seo_transfer_is( 'and the file records that they were left out', $p['secrets'], false );
fw_seo_transfer_is( 'ordinary settings travel', $p['settings']['separator'], '|' );
fw_seo_transfer_is( 'nested values travel whole', $p['settings']['templates'], $settings['templates'] );

$ps = FW_SEO_Transfer::payload( $settings, true );
fw_seo_transfer_is( 'asked for, the credentials are included', $ps['settings']['crux_api_key'], 'AIza-SECRET' );
fw_seo_transfer_is( 'and the file says so', $ps['secrets'], true );

/* -------------------------------------------------------------------------- *
 * Round trip — what is exported imports back unchanged
 * -------------------------------------------------------------------------- */

$known = array_keys( $settings );
$json  = json_decode( wp_json_encode( $ps ), true );
$plan  = FW_SEO_Transfer::plan( array(), $json['settings'], $known );

fw_seo_transfer_is( 'a full export through JSON comes back value for value', $plan['values'] == $settings, true );

$plan = FW_SEO_Transfer::plan( $settings, $json['settings'], $known );
fw_seo_transfer_is( 'importing a site\'s own export changes nothing', $plan['change'], array() );
fw_seo_transfer_is( 'and counts every setting as already the same', $plan['same'], count( $settings ) );

/* -------------------------------------------------------------------------- *
 * validate()
 * -------------------------------------------------------------------------- */

fw_seo_transfer_is( 'a valid export validates', FW_SEO_Transfer::validate( $p ), '' );
fw_seo_transfer_is( 'garbage is not JSON we can use', FW_SEO_Transfer::validate( 'nope' ), 'notjson' );
fw_seo_transfer_is( 'somebody else\'s JSON is refused', FW_SEO_Transfer::validate( array( 'format' => 'other', 'settings' => array() ) ), 'notours' );
fw_seo_transfer_is( 'a file from a newer version is refused, not half-read', FW_SEO_Transfer::validate( array( 'format' => FW_SEO_Transfer::FORMAT, 'version' => 99, 'settings' => array() ) ), 'newer' );
fw_seo_transfer_is( 'a file with no settings is refused', FW_SEO_Transfer::validate( array( 'format' => FW_SEO_Transfer::FORMAT, 'version' => 1 ) ), 'empty' );

/* -------------------------------------------------------------------------- *
 * plan() — only known keys, nothing outside the file
 * -------------------------------------------------------------------------- */

$plan = FW_SEO_Transfer::plan(
	array( 'separator' => '|', 'sitemap_enabled' => 'yes' ),
	array( 'separator' => '-', 'sitemap_enabled' => 'yes', 'planted' => 'evil', 'from_newer' => 1 ),
	array( 'separator', 'sitemap_enabled' )
);
fw_seo_transfer_is( 'a changed value is a change', $plan['change'], array( 'separator' ) );
fw_seo_transfer_is( 'an equal value is not', $plan['same'], 1 );
fw_seo_transfer_is( 'unknown keys are reported', $plan['unknown'], array( 'from_newer', 'planted' ) );
fw_seo_transfer_is( 'and never written', array_keys( $plan['values'] ), array( 'separator', 'sitemap_enabled' ) );

/* -------------------------------------------------------------------------- *
 * known_keys() against the real schema
 * -------------------------------------------------------------------------- */

if ( fw()->extensions->get( 'seo' ) ) {
	$keys = FW_SEO_Transfer::known_keys();
	fw_seo_transfer_is( 'the schema yields real setting ids', in_array( 'separator', $keys, true ) && in_array( 'sitemap_enabled', $keys, true ), true );
	fw_seo_transfer_is( 'report panels are not settings', in_array( 'console_report', $keys, true ) || in_array( 'vitals_report', $keys, true ), false );
	fw_seo_transfer_is( 'every credential the export strips is a real setting', array_values( array_diff( FW_SEO_Transfer::SECRETS, $keys ) ), array() );
}

fw_seo_transfer_is(
	'display-only items in a file are skipped silently, not reported as lost',
	FW_SEO_Transfer::plan( array(), array( 'a' => 1, 'heading__x' => '' ), array( 'a' ), array( 'heading__x' ) )['unknown'],
	array()
);

if ( fw()->extensions->get( 'seo' ) ) {
	// The real thing: this site's own export, through JSON, against this site.
	$own  = json_decode( wp_json_encode( FW_SEO_Transfer::payload( FW_SEO_Transfer::exportable(), true ) ), true );
	$plan = FW_SEO_Transfer::plan( FW_SEO_Transfer::current(), $own['settings'], FW_SEO_Transfer::known_keys(), FW_SEO_Transfer::known_keys( true ) );
	fw_seo_transfer_is( "a site's own export reports nothing unknown", $plan['unknown'], array() );
	fw_seo_transfer_is( 'and nothing to change', $plan['change'], array() );
	fw_seo_transfer_is( 'the export carries no display-only items', array_values( array_intersect( array_keys( $own['settings'] ), FW_SEO_Transfer::known_keys( true ) ) ), array() );
}

fw_seo_transfer_is( 'the panel renders', false !== strpos( FW_SEO_Transfer::panel_html(), 'Export settings' ), true );

printf( "\n%d passed, %d failed\n", FW_SEO_Transfer_T::$pass, FW_SEO_Transfer_T::$fail );

exit( FW_SEO_Transfer_T::$fail ? 1 : 0 );
