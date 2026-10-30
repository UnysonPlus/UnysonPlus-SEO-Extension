<?php
/**
 * Contract tests for the site-wide SEO abilities an AI client can use:
 * settings and redirects, each change undoable.
 *
 * Needs the AI Assistant extension active (skipped otherwise).
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/ai-abilities-test.php
 */

class FW_SEO_Ab_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_ab_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_Ab_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_Ab_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

if ( ! function_exists( 'wp_get_ability' ) || ! class_exists( 'FW_AI_Toolkit' ) ) {
	echo "NOTE  the AI Assistant (and the Abilities API) is not active here; skipped\n";
	printf( "\n%d passed, %d failed\n", 0, 0 );
	exit( 0 );
}

wp_set_current_user( 1 );
$run = static function ( $slug, $in = null ) {
	$a = wp_get_ability( 'unysonplus/' . $slug );

	return $a ? $a->execute( $in ) : new WP_Error( 'missing', $slug . ' is not registered' );
};

foreach ( [ 'seo-get-settings', 'seo-update-settings', 'seo-list-redirects', 'seo-save-redirect', 'seo-delete-redirect' ] as $slug ) {
	fw_seo_ab_is( $slug . ' is registered', (bool) wp_get_ability( 'unysonplus/' . $slug ), true );
}

/* Settings ------------------------------------------------------------------------ */

$before = (array) fw_get_db_ext_settings_option( 'seo' );
$got    = $run( 'seo-get-settings', [ 'keys' => [ 'separator', 'console_client_secret' ] ] );
fw_seo_ab_is( 'settings can be read', array_key_exists( 'separator', $got['settings'] ?? [] ), true );
fw_seo_ab_is( 'credentials never are', array_key_exists( 'console_client_secret', $got['settings'] ?? [] ), false );

$new = '|' === ( $before['separator'] ?? '|' ) ? '–' : '|';
$res = $run( 'seo-update-settings', [ 'settings' => [ 'separator' => $new, 'console_client_secret' => 'stolen', 'not_a_setting' => 1 ] ] );
fw_seo_ab_is( 'a known setting is changed', ( (array) fw_get_db_ext_settings_option( 'seo' ) )['separator'] ?? '', $new );
fw_seo_ab_is( 'credentials and unknown keys are refused', $res['refused'] ?? [], [ 'console_client_secret', 'not_a_setting' ] );
fw_seo_ab_is( 'and the credential is untouched', ( (array) fw_get_db_ext_settings_option( 'seo' ) )['console_client_secret'] ?? '', $before['console_client_secret'] ?? '' );
FW_AI_Toolkit::restore( (int) $res['undo_revision_id'] );
FW_SEO_Settings::flush();
fw_seo_ab_is( 'undo puts the setting back', ( (array) fw_get_db_ext_settings_option( 'seo' ) )['separator'] ?? '|', $before['separator'] ?? '|' );

/* Redirects -------------------------------------------------------------------------- */

$p     = '/ab' . substr( md5( (string) microtime( true ) ), 0, 6 );
$count = count( FW_SEO_Redirect_Rules::all() );
$saved = $run( 'seo-save-redirect', [ 'source' => "$p/old/", 'target' => '/new/', 'code' => 301, 'group' => 'AI' ] );
fw_seo_ab_is( 'a redirect is saved', is_int( $saved['id'] ?? null ), true );
fw_seo_ab_is( 'and answers', FW_SEO_Redirect_Rules::match( "$p/old" )['target'] ?? '', '/new/' );
fw_seo_ab_is( 'a broken one is refused with the reason', is_wp_error( $run( 'seo-save-redirect', [ 'source' => "$p/x", 'target' => '' ] ) ), true );

FW_AI_Toolkit::restore( (int) $saved['undo_revision_id'] );
fw_seo_ab_is( 'undo removes the redirect again', FW_SEO_Redirect_Rules::match( "$p/old" ), null );
fw_seo_ab_is( 'and leaves the others as they were', count( FW_SEO_Redirect_Rules::all() ), $count );

$saved = $run( 'seo-save-redirect', [ 'source' => "$p/keep/", 'target' => '/kept/' ] );
$del   = $run( 'seo-delete-redirect', [ 'id' => (int) $saved['id'] ] );
fw_seo_ab_is( 'a redirect is deleted', FW_SEO_Redirect_Rules::match( "$p/keep" ), null );
FW_AI_Toolkit::restore( (int) $del['undo_revision_id'] );
fw_seo_ab_is( 'undo brings it back', FW_SEO_Redirect_Rules::match( "$p/keep" )['target'] ?? '', '/kept/' );

$list = $run( 'seo-list-redirects' );
fw_seo_ab_is( 'the list shows redirects and pages not found', isset( $list['redirects'], $list['not_found'] ), true );

global $wpdb;
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . FW_SEO_Redirect_Rules::rules_table() . ' WHERE source LIKE %s', '%' . $wpdb->esc_like( $p ) . '%' ) );
FW_SEO_Redirect_Rules::mirror();

printf( "\n%d passed, %d failed\n", FW_SEO_Ab_T::$pass, FW_SEO_Ab_T::$fail );

exit( FW_SEO_Ab_T::$fail ? 1 : 0 );
