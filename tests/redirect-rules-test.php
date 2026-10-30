<?php
/**
 * Contract tests for the redirect manager and 404 log (FW_SEO_Redirect_Rules).
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/redirect-rules-test.php
 *
 * Creates rules and log rows under a random prefix and removes them again.
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

require_once $base . '/includes/class-fw-seo-redirects.php';
require_once $base . '/includes/class-fw-seo-redirect-rules.php';

class FW_SEO_Rules_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_rules_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_Rules_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_Rules_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/* Keys --------------------------------------------------------------------- */

fw_seo_rules_is( 'case and trailing slash do not matter', FW_SEO_Redirect_Rules::key_of( '/About-Us/' ), FW_SEO_Redirect_Rules::key_of( '/about-us' ) );
fw_seo_rules_is( 'a full address from another site reduces to its path', FW_SEO_Redirect_Rules::key_of( 'https://old.example/Shop/Item' ), '/shop/item' );
fw_seo_rules_is( 'a query is part of the address, in any order', FW_SEO_Redirect_Rules::key_of( '/page.php?b=2&ID=4' ), FW_SEO_Redirect_Rules::key_of( '/page.php?id=4&b=2' ) );

fw_seo_rules_is( 'a site in a sub-folder sees requests relative to itself', FW_SEO_Redirect_Rules::relative( '/shop/old-page/?a=1', '/shop' ), '/old-page/?a=1' );
fw_seo_rules_is( 'its own root is "/"', FW_SEO_Redirect_Rules::relative( '/shop?x=1', '/shop' ), '/?x=1' );
fw_seo_rules_is( 'a similar-looking folder is not stripped', FW_SEO_Redirect_Rules::relative( '/shopping/a', '/shop' ), '/shopping/a' );
fw_seo_rules_is( 'a site at the root is unchanged', FW_SEO_Redirect_Rules::relative( '/old-page/', '' ), '/old-page/' );

/* Validation ------------------------------------------------------------------ */

fw_seo_rules_is( 'an empty old address is refused', is_wp_error( FW_SEO_Redirect_Rules::validate( [ 'source' => '', 'target' => '/x/' ] ) ), true );
fw_seo_rules_is( 'a redirect needs somewhere to go', is_wp_error( FW_SEO_Redirect_Rules::validate( [ 'source' => '/a/', 'target' => '', 'code' => 301 ] ) ), true );
fw_seo_rules_is( '410 Gone needs no target', is_wp_error( FW_SEO_Redirect_Rules::validate( [ 'source' => '/a/', 'target' => '', 'code' => 410 ] ) ), false );
fw_seo_rules_is( 'an unknown status code is refused', is_wp_error( FW_SEO_Redirect_Rules::validate( [ 'source' => '/a/', 'target' => '/b/', 'code' => 308 ] ) ), true );
fw_seo_rules_is( 'a broken regular expression is refused', is_wp_error( FW_SEO_Redirect_Rules::validate( [ 'source' => '^/blog/(\d+', 'target' => '/x/', 'is_regex' => 1 ] ) ), true );
fw_seo_rules_is( 'the home page cannot be redirected', is_wp_error( FW_SEO_Redirect_Rules::validate( [ 'source' => '/', 'target' => '/x/' ] ) ), true );
fw_seo_rules_is( 'a redirect to itself is refused', is_wp_error( FW_SEO_Redirect_Rules::validate( [ 'source' => '/Same/', 'target' => '/same' ] ) ), true );
fw_seo_rules_is( 'a line break cannot sneak into an address', is_wp_error( FW_SEO_Redirect_Rules::validate( [ 'source' => "/a\r\nLocation: x", 'target' => '/b/' ] ) ), true );

/* Regex targets ---------------------------------------------------------------- */

fw_seo_rules_is( 'captured parts fill the target', FW_SEO_Redirect_Rules::regex_target( '^/blog/(\d+)/(.*)$', '/news/$2/', '/blog/2019/spring-sale' ), '/news/spring-sale/' );
fw_seo_rules_is( 'letter case is ignored', FW_SEO_Redirect_Rules::regex_target( '^/OLD/(.*)$', '/new/$1', '/old/a' ), '/new/a' );
fw_seo_rules_is( 'no match, no target', FW_SEO_Redirect_Rules::regex_target( '^/blog/', '/x/', '/news/' ), null );

/* Storing and matching ------------------------------------------------------------ */

global $wpdb;
FW_SEO_Redirect_Rules::install();
$p = '/t' . substr( md5( (string) microtime( true ) ), 0, 6 );

$a = FW_SEO_Redirect_Rules::save( [ 'source' => "$p/Old-Page/", 'target' => '/new-page/', 'code' => 301, 'grp' => 'Test' ] );
$b = FW_SEO_Redirect_Rules::save( [ 'source' => "https://old.example$p/item.php?id=4", 'target' => '/products/widget/', 'code' => 301 ] );
$c = FW_SEO_Redirect_Rules::save( [ 'source' => "^$p/blog/(\\d+)/(.*)$", 'target' => '/news/$2/', 'code' => 302, 'is_regex' => 1 ] );
$d = FW_SEO_Redirect_Rules::save( [ 'source' => "$p/discontinued", 'target' => '', 'code' => 410 ] );

fw_seo_rules_is( 'four rules saved', count( array_filter( [ $a, $b, $c, $d ], 'is_int' ) ), 4 );
fw_seo_rules_is( 'saving the same old address again replaces it', FW_SEO_Redirect_Rules::save( [ 'source' => "$p/old-page", 'target' => '/newer-page/', 'code' => 301 ] ), $a );

$m = FW_SEO_Redirect_Rules::match( "$p/OLD-PAGE" );
fw_seo_rules_is( 'matches regardless of case and slash, to the newest target', $m['target'] ?? '', '/newer-page/' );
$m = FW_SEO_Redirect_Rules::match( "$p/old-page/?utm_source=mail" );
fw_seo_rules_is( 'a tracked link keeps its tracking', $m['target'] ?? '', '/newer-page/?utm_source=mail' );
$m = FW_SEO_Redirect_Rules::match( "$p/item.php?id=4" );
fw_seo_rules_is( 'an old platform\'s query-string address matches', $m['target'] ?? '', '/products/widget/' );
fw_seo_rules_is( 'a different id does not', FW_SEO_Redirect_Rules::match( "$p/item.php?id=5" ), null );
$m = FW_SEO_Redirect_Rules::match( "$p/blog/2019/spring-sale" );
fw_seo_rules_is( 'a regex rule matches with its own code', [ $m['target'] ?? '', $m['code'] ?? 0 ], [ '/news/spring-sale/', 302 ] );
fw_seo_rules_is( 'a 410 rule answers Gone', FW_SEO_Redirect_Rules::match( "$p/discontinued" )['code'] ?? 0, 410 );

/* Import / export ------------------------------------------------------------------ */

$mine = array_values( array_filter( FW_SEO_Redirect_Rules::all(), static function ( $r ) use ( $p ) {
	return false !== strpos( $r['source'], $p );
} ) );
$csv  = FW_SEO_Redirect_Rules::to_csv( $mine );
$back = FW_SEO_Redirect_Rules::parse_import( $csv );
fw_seo_rules_is( 'what is exported reads back unchanged', array_map( static function ( $r ) {
	return [ $r['source'], $r['target'], (int) $r['code'], (bool) $r['is_regex'], $r['grp'] ];
}, $back ), array_map( static function ( $r ) {
	return [ $r['source'], $r['target'], (int) $r['code'], (bool) $r['is_regex'], $r['grp'] ];
}, $mine ) );

$two = FW_SEO_Redirect_Rules::parse_import( "$p/a,/b/\n$p/c,/d/\n" );
fw_seo_rules_is( 'a two-column file without a header is old → new', [ $two[0]['source'], $two[0]['target'], $two[0]['code'] ], [ "$p/a", '/b/', 301 ] );

$json = FW_SEO_Redirect_Rules::parse_import( wp_json_encode( [ 'format' => 'unysonplus-seo-redirects', 'rules' => [ [ 'source' => "$p/j", 'target' => '/k/', 'code' => 307, 'regex' => false, 'group' => 'G' ] ] ] ) );
fw_seo_rules_is( 'the JSON export reads back too', [ $json[0]['source'], $json[0]['code'], $json[0]['grp'] ], [ "$p/j", 307, 'G' ] );

$result = FW_SEO_Redirect_Rules::import( array_merge( $two, [ [ 'source' => '', 'target' => '/x/' ] ] ) );
fw_seo_rules_is( 'import saves the good rows and says which were skipped and why', [ $result['saved'], count( $result['skipped'] ) ], [ 2, 1 ] );

/* The 404 log ------------------------------------------------------------------- */

fw_seo_rules_is( 'a page is worth logging', FW_SEO_Redirect_Rules::worth_logging( '/old-article/', 'Mozilla/5.0 (Windows NT 10.0) Chrome/130' ), true );
fw_seo_rules_is( 'a scanner probe is not', FW_SEO_Redirect_Rules::worth_logging( '/wp-config.php.bak', 'Mozilla/5.0' ), false );
fw_seo_rules_is( 'a dotfile probe is not', FW_SEO_Redirect_Rules::worth_logging( '/.env', 'Mozilla/5.0' ), false );
fw_seo_rules_is( 'a missing image is not', FW_SEO_Redirect_Rules::worth_logging( '/images/logo.png', 'Mozilla/5.0' ), false );
fw_seo_rules_is( 'a search engine is not', FW_SEO_Redirect_Rules::worth_logging( '/old-article/', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ), false );

FW_SEO_Redirect_Rules::log( "$p/Missing-Thing/", 'https://news.example/story' );
FW_SEO_Redirect_Rules::log( "$p/missing-thing" );
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . FW_SEO_Redirect_Rules::log_table() . ' WHERE path_key = %s', md5( FW_SEO_Redirect_Rules::key_of( "$p/missing-thing" ) ) ) );
fw_seo_rules_is( 'the same address is one row, counted twice', (int) ( $row->hits ?? 0 ), 2 );
fw_seo_rules_is( 'the referrer is kept when a later request has none', (string) ( $row->referrer ?? '' ), 'https://news.example/story' );

FW_SEO_Redirect_Rules::save( [ 'source' => "$p/missing-thing", 'target' => '/found/', 'code' => 301 ] );
fw_seo_rules_is( 'redirecting it takes it off the log', (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . FW_SEO_Redirect_Rules::log_table() . ' WHERE path_key = %s', md5( FW_SEO_Redirect_Rules::key_of( "$p/missing-thing" ) ) ) ), 0 );

fw_seo_rules_is( 'the rules screen renders', false !== strpos( FW_SEO_Redirect_Rules::rules_html(), 'fw-seo-rules' ), true );
fw_seo_rules_is( 'the log screen renders', '' !== FW_SEO_Redirect_Rules::log_html(), true );

/* Clean up ----------------------------------------------------------------------- */

$like = '%' . $wpdb->esc_like( $p ) . '%';
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . FW_SEO_Redirect_Rules::rules_table() . ' WHERE source LIKE %s', $like ) );
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . FW_SEO_Redirect_Rules::log_table() . ' WHERE path LIKE %s', $like ) );

printf( "\n%d passed, %d failed\n", FW_SEO_Rules_T::$pass, FW_SEO_Rules_T::$fail );

exit( FW_SEO_Rules_T::$fail ? 1 : 0 );
