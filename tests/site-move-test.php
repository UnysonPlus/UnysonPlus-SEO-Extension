<?php
/**
 * Contract tests for whole-site moves (FW_SEO_Site_Move).
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/site-move-test.php
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

require_once $base . '/includes/class-fw-seo-site-move.php';

class FW_SEO_Move_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_move_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_Move_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_Move_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

$home = 'https://old-name.test/';

fw_seo_move_is( 'a new domain is used as given', FW_SEO_Site_Move::clean_destination( 'https://www.new-name.test/', $home ), 'https://www.new-name.test' );
fw_seo_move_is( 'a folder on the new site is kept', FW_SEO_Site_Move::clean_destination( 'https://new-name.test/blog/', $home ), 'https://new-name.test/blog' );
fw_seo_move_is( 'a query or fragment in the setting is dropped', FW_SEO_Site_Move::clean_destination( 'https://new-name.test/?x=1#y', $home ), 'https://new-name.test' );
fw_seo_move_is( 'without http(s) nothing happens', FW_SEO_Site_Move::clean_destination( 'new-name.test', $home ), '' );
fw_seo_move_is( 'a move to this same site would loop, so nothing happens', FW_SEO_Site_Move::clean_destination( 'https://www.old-name.test/', $home ), '' );
fw_seo_move_is( 'a line break cannot sneak in', FW_SEO_Site_Move::clean_destination( "https://new-name.test/\r\nX: y", $home ), '' );

fw_seo_move_is( 'each page keeps its path and query', FW_SEO_Site_Move::target( '/about/team/?ref=mail', 'https://new-name.test' ), 'https://new-name.test/about/team/?ref=mail' );
fw_seo_move_is( 'the home page goes to the new home page', FW_SEO_Site_Move::target( '/', 'https://new-name.test' ), 'https://new-name.test/' );
fw_seo_move_is( 'a site in a sub-folder drops its folder', FW_SEO_Site_Move::target( '/shop/item/', 'https://new-name.test', '/shop' ), 'https://new-name.test/item/' );
fw_seo_move_is( 'into a folder on the new site', FW_SEO_Site_Move::target( '/item/', 'https://new-name.test/old-shop' ), 'https://new-name.test/old-shop/item/' );

fw_seo_move_is( 'switched off by default: no destination', FW_SEO_Site_Move::destination(), '' );

printf( "\n%d passed, %d failed\n", FW_SEO_Move_T::$pass, FW_SEO_Move_T::$fail );

exit( FW_SEO_Move_T::$fail ? 1 : 0 );
