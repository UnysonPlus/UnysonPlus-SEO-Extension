<?php
/**
 * Contract tests for living alongside another SEO plugin.
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/conflict-test.php
 *
 * The property that matters: when another SEO plugin is active this extension
 * prints NOTHING, and loses nothing. A site with two canonicals is worse off
 * than with either alone, and a user who switches the other plugin off must get
 * every value they wrote back untouched.
 */

class TC {
	public static $pass = 0;
	public static $fail = 0;
}

function tc( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		TC::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	TC::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

echo "\nConflict contracts\n\n";

echo "Detection\n";

tc( 'a clean site detects nothing', fw_upw_seo_plugin_active(), '' );
tc( '...so the extension emits', FW_SEO_Conflict::should_emit(), true );

// Each competitor is detected by the marker it actually defines, so a renamed
// plugin folder or a mu-plugin install cannot hide it.
$pretend = static function ( $name ) {
	return static function () use ( $name ) {
		return $name;
	};
};

add_filter( 'fw_upw_seo_plugin_active', $pretend( 'Yoast SEO' ) );

tc( 'a detected plugin is named, not just flagged', fw_upw_seo_plugin_active(), 'Yoast SEO' );
tc( '...and the extension stands down', FW_SEO_Conflict::should_emit(), false );

remove_all_filters( 'fw_upw_seo_plugin_active' );

tc( 'removing it brings emission back', FW_SEO_Conflict::should_emit(), true );

echo "\nThe override\n";

add_filter( 'fw_upw_seo_plugin_active', $pretend( 'Rank Math' ) );
add_filter( 'fw_seo_should_emit', '__return_true' );

tc(
	'a site that has silenced the other plugin itself can force emission back on',
	FW_SEO_Conflict::should_emit(),
	true
);

remove_all_filters( 'fw_seo_should_emit' );
remove_all_filters( 'fw_upw_seo_plugin_active' );

echo "\nNothing is lost\n";

$post_id = wp_insert_post( [
	'post_title'  => 'A post with its own SEO',
	'post_status' => 'publish',
	'post_type'   => 'post',
] );

FW_SEO_Store::set_post( $post_id, 'title', 'Written before the other plugin arrived' );
FW_SEO_Store::set_post( $post_id, 'noindex', true );

add_filter( 'fw_upw_seo_plugin_active', $pretend( 'All in One SEO' ) );

tc(
	'standing down does not touch what was stored',
	FW_SEO_Store::get_post( $post_id, 'title' ),
	'Written before the other plugin arrived'
);

tc( '...including the switches', FW_SEO_Store::get_post( $post_id, 'noindex' ), true );

tc(
	'the admin side still resolves, so the editor keeps working',
	FW_SEO_Chain::resolve( 'title', FW_SEO_Context::for_post( $post_id ) ),
	'Written before the other plugin arrived'
);

remove_all_filters( 'fw_upw_seo_plugin_active' );

tc(
	'and it all comes back when the other plugin goes away',
	FW_SEO_Store::get_post( $post_id, 'title' ),
	'Written before the other plugin arrived'
);

wp_delete_post( $post_id, true );

printf( "\n%d passed, %d failed\n", TC::$pass, TC::$fail );

if ( TC::$fail ) {
	exit( 1 );
}
