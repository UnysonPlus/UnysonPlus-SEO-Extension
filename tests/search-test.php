<?php
/**
 * Contract tests for the site-search gap log.
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/search-test.php
 *
 * Two things matter here beyond "it records": the log must AGGREGATE rather
 * than grow a row per search, and it must forget. Search phrases are text a
 * visitor typed, and people paste things they did not mean to.
 */

class TS2 {
	public static $pass = 0;
	public static $fail = 0;
}

function ts2( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		TS2::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	TS2::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

FW_SEO_Search_Log::install();
FW_SEO_Search_Log::clear();

echo "\nSite-search log contracts\n\n";

echo "Normalising\n";

ts2( 'case and padding fold together', FW_SEO_Search_Log::normalise( '  Blue Widgets ' ), 'blue widgets' );
ts2( 'runs of whitespace collapse', FW_SEO_Search_Log::normalise( "blue\t widgets" ), 'blue widgets' );
ts2( 'tags are stripped', FW_SEO_Search_Log::normalise( '<b>blue</b> widgets' ), 'blue widgets' );
ts2( 'an empty phrase is refused', FW_SEO_Search_Log::normalise( '   ' ), '' );
ts2( 'a pasted wall of text is refused', FW_SEO_Search_Log::normalise( str_repeat( 'a', 500 ) ), '' );

echo "\nAggregating\n";

FW_SEO_Search_Log::record( 'Blue Widgets', 0 );
FW_SEO_Search_Log::record( 'blue widgets', 0 );
FW_SEO_Search_Log::record( '  BLUE  WIDGETS  ', 0 );

$rows = FW_SEO_Search_Log::rows( [ 'gap' => 'missing' ] );
$blue = null;

foreach ( $rows as $row ) {
	if ( 'blue widgets' === $row->term ) {
		$blue = $row;
	}
}

ts2( 'three spellings became ONE row', is_object( $blue ), true );
ts2( '...with the hits counted', (int) ( $blue->hits ?? 0 ), 3 );

FW_SEO_Search_Log::record( 'blue widgets', 7 );
$rows = FW_SEO_Search_Log::rows( [ 'gap' => 'all' ] );
$blue = null;
foreach ( $rows as $row ) { if ( 'blue widgets' === $row->term ) { $blue = $row; } }

ts2( 'the result count reflects the LATEST search', (int) ( $blue->results ?? -1 ), 7 );
ts2( '...and it leaves the "found nothing" list', count( array_filter( FW_SEO_Search_Log::rows( [ 'gap' => 'missing' ] ), function ( $r ) { return 'blue widgets' === $r->term; } ) ), 0 );

echo "\nThe gap report\n";

FW_SEO_Search_Log::clear();
FW_SEO_Search_Log::record( 'wedding packages', 0 );
for ( $i = 0; $i < 12; $i ++ ) { FW_SEO_Search_Log::record( 'gift vouchers', 0 ); }
FW_SEO_Search_Log::record( 'opening hours', 2 );
FW_SEO_Search_Log::record( 'about us', 9 );

$missing = FW_SEO_Search_Log::rows( [ 'gap' => 'missing' ] );

ts2( 'only phrases that found nothing are listed', count( $missing ), 2 );
ts2( 'the most-asked comes first, not the most recent', $missing[0]->term, 'gift vouchers' );

$thin = FW_SEO_Search_Log::rows( [ 'gap' => 'thin' ] );

ts2( 'a thin result is reported separately', count( $thin ), 1 );
ts2( '...and it is the right one', $thin[0]->term, 'opening hours' );

$summary = FW_SEO_Search_Log::summary();

ts2( 'the summary counts every search, not every phrase', $summary['searches'], 15 );
ts2( '...distinct phrases', $summary['terms'], 4 );
ts2( '...and how many found nothing', $summary['missing'], 2 );

echo "\nForgetting\n";

global $wpdb;
$table = FW_SEO_Search_Log::table();
$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET last_seen = %s WHERE term = %s", gmdate( 'Y-m-d H:i:s', time() - ( 200 * DAY_IN_SECONDS ) ), 'wedding packages' ) );

$removed = FW_SEO_Search_Log::prune( 90 );

ts2( 'a phrase nobody has searched for in 200 days is dropped', $removed, 1 );
ts2( '...and a recent one is kept', count( FW_SEO_Search_Log::rows( [ 'gap' => 'all' ] ) ), 3 );
ts2( 'a retention of zero prunes nothing rather than everything', FW_SEO_Search_Log::prune( 0 ), 0 );

echo "\nNot recording the wrong things\n";

ts2( 'a crawler is not a visitor', FW_SEO_Search_Log::is_bot_agent( 'Mozilla/5.0 (compatible; Googlebot/2.1)' ), true );
ts2( 'nor is a script', FW_SEO_Search_Log::is_bot_agent( 'curl/8.4.0' ), true );
ts2( 'nor a headless browser', FW_SEO_Search_Log::is_bot_agent( 'HeadlessChrome/120' ), true );
ts2( 'an ordinary browser is NOT a bot', FW_SEO_Search_Log::is_bot_agent( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36' ), false );
ts2( 'no user agent at all counts as a bot', FW_SEO_Search_Log::is_bot_agent( '' ), true );

// The context guard is separate, and is why the above is a pure function:
// under WP-CLI nothing is a visitor, whatever the agent string says.
ts2( 'WP-CLI is never a visitor', FW_SEO_Search_Log::is_human(), false );

ts2( 'an empty phrase records nothing', FW_SEO_Search_Log::record( '   ', 0 ), false );

FW_SEO_Search_Log::clear();
ts2( 'clearing empties the log', FW_SEO_Search_Log::summary()['searches'], 0 );

printf( "\n%d passed, %d failed\n", TS2::$pass, TS2::$fail );

if ( TS2::$fail ) {
	exit( 1 );
}
