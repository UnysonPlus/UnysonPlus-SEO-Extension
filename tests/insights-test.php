<?php
/**
 * Contract tests for search performance (FW_SEO_Performance), the Trends
 * import and topic momentum (FW_SEO_Trends), and the AI pieces (FW_SEO_AI).
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/insights-test.php
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

foreach ( [ 'analysis', 'performance', 'trends', 'ai' ] as $f ) {
	require_once $base . '/includes/class-fw-seo-' . $f . '.php';
}

class FW_SEO_In_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_in_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_In_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_In_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/* Search performance ----------------------------------------------------------- */

$now    = [ '/a' => [ 'impressions' => 900, 'clicks' => 60, 'position' => 4.2 ], '/b' => [ 'impressions' => 300, 'clicks' => 2, 'position' => 6.0 ], '/c' => [ 'impressions' => 20, 'clicks' => 1, 'position' => 9 ] ];
$before = [ '/a' => [ 'impressions' => 600, 'clicks' => 30, 'position' => 6.0 ], '/b' => [ 'impressions' => 700, 'clicks' => 9, 'position' => 5.0 ], '/c' => [ 'impressions' => 10, 'clicks' => 0, 'position' => 12 ] ];
$rows   = FW_SEO_Performance::rows( $now, $before );
fw_seo_in_is( 'pages under 50 impressions in both windows are left out', array_column( $rows, 'url' ), [ '/a', '/b' ] );
fw_seo_in_is( 'changes are now minus before', [ $rows[0]['clicks_d'], $rows[0]['impr_d'] ], [ 30, 300 ] );
fw_seo_in_is( 'a position moving from 6 to 4.2 is +1.8 (up)', $rows[0]['position_d'], 1.8 );
$lists = FW_SEO_Performance::lists( $rows );
fw_seo_in_is( 'gaining and slipping', [ array_column( $lists['gaining'], 'url' ), array_column( $lists['slipping'], 'url' ) ], [ [ '/a' ], [ '/b' ] ] );
fw_seo_in_is( 'seen but not clicked: 300 impressions, 2 clicks', array_column( $lists['unclicked'], 'url' ), [ '/b' ] );

/* Trends: reading the files ----------------------------------------------------- */

fw_seo_in_is( 'a header cell names the term and country', FW_SEO_Trends::split_term( 'sourdough: (United Kingdom)' ), [ 'sourdough', 'United Kingdom' ] );
fw_seo_in_is( 'values: "<1", "Breakout", "+250%"', [ FW_SEO_Trends::value( '<1' ), FW_SEO_Trends::value( 'Breakout' ), FW_SEO_Trends::value( '+250%' ) ], [ 0.5, 5000.0, 250.0 ] );

$csv = "Category: All categories\n\nWeek,sourdough: (United Kingdom),bread maker: (United Kingdom)\n";
$d   = strtotime( '2024-11-03' );
for ( $i = 0; $i < 104; $i ++ ) {
	// Sourdough grows; bread makers fade; both peak in December.
	$month = (int) gmdate( 'n', $d + $i * WEEK_IN_SECONDS );
	$xmas  = 12 === $month ? 20 : 0;
	$csv  .= gmdate( 'Y-m-d', $d + $i * WEEK_IN_SECONDS ) . ',' . ( 30 + (int) ( $i * 0.4 ) + $xmas ) . ',' . max( 1, 60 - (int) ( $i * 0.4 ) + $xmas ) . "\n";
}
$t = FW_SEO_Trends::parse( "\xEF\xBB\xBF" . $csv, 'multiTimeline.csv' );
fw_seo_in_is( 'an "Interest over time" export is read', [ $t['kind'], $t['terms'], $t['geo'], $t['from'] ], [ 'timeline', [ 'sourdough', 'bread maker' ], 'United Kingdom', '2024-11-03' ] );
fw_seo_in_is( 'with every week of every term', count( $t['series']['sourdough'] ), 104 );

$rq = FW_SEO_Trends::parse( "Category: All categories\n\nTOP\nsourdough starter,100\nsourdough recipe,71\n\nRISING\nsourdough discard recipes,Breakout\nsourdough focaccia,+350%\n", 'relatedQueries.csv' );
fw_seo_in_is( 'a "Related queries" export is read', [ $rq['kind'], count( $rq['top'] ), $rq['rising'][0] ], [ 'related', 2, [ 'sourdough discard recipes', 5000.0 ] ] );
fw_seo_in_is( 'anything else is refused with a reason', is_wp_error( FW_SEO_Trends::parse( "a,b\n1,2\n" ) ), true );

/* Trends: momentum --------------------------------------------------------------- */

$up   = FW_SEO_Trends::momentum( $t['series']['sourdough'] );
$down = FW_SEO_Trends::momentum( $t['series']['bread maker'] );
fw_seo_in_is( 'two years of weekly data compare with the same months last year', $up['basis'], 'year' );
fw_seo_in_is( 'a growing term is rising', $up['direction'], 'rising' );
fw_seo_in_is( 'a fading one is falling', $down['direction'], 'falling' );
fw_seo_in_is( 'the peak month is found', $up['peak_month'], date_i18n( 'F', mktime( 0, 0, 0, 12, 1 ) ) );
fw_seo_in_is( 'too few points says so', FW_SEO_Trends::momentum( [ [ '2025-01-01', 1 ] ] )['direction'], 'unknown' );

/* Trends: what to do -------------------------------------------------------------- */

fw_seo_in_is( 'rising + seen + a page: refresh before the peak', FW_SEO_Trends::classify( 'rising', 120, true )['case'], 'refresh' );
fw_seo_in_is( 'rising + seen + no page: write it', FW_SEO_Trends::classify( 'rising', 120, false )['case'], 'write' );
fw_seo_in_is( 'rising + unseen + no page: an open gap', FW_SEO_Trends::classify( 'rising', 0, false )['case'], 'gap' );
fw_seo_in_is( 'falling + a page: the market moved, leave it', FW_SEO_Trends::classify( 'falling', 50, true )['case'], 'moved' );
fw_seo_in_is( 'the most valuable row sorts first', FW_SEO_Trends::classify( 'rising', 1, true )['rank'] < FW_SEO_Trends::classify( 'rising', 0, false )['rank'], true );

/* Trends: stored with their parameters, and the join ----------------------------------- */

$saved = get_option( FW_SEO_Trends::OPTION, null );
delete_option( FW_SEO_Trends::OPTION );
FW_SEO_Trends::add( $t, 'multiTimeline.csv' );
FW_SEO_Trends::add( $rq, 'relatedQueries.csv' );
$imports = FW_SEO_Trends::imports();
fw_seo_in_is( 'each import keeps the settings it was taken under', [ $imports[1]['geo'], $imports[1]['from'], $imports[1]['file'] ], [ 'United Kingdom', '2024-11-03', 'multiTimeline.csv' ] );

$page = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Our sourdough guide' ] );
$rows = FW_SEO_Trends::momentum_rows();
$by   = array_column( $rows, null, 'term' );
fw_seo_in_is( 'a term finds the page that targets it', $by['sourdough']['page'] ?? null, $page );
fw_seo_in_is( 'rising related searches join the rows', isset( $by['sourdough discard recipes'] ), true );
wp_delete_post( $page, true );
null === $saved ? delete_option( FW_SEO_Trends::OPTION ) : update_option( FW_SEO_Trends::OPTION, $saved, false );

/* AI --------------------------------------------------------------------------------- */

fw_seo_in_is( 'a mention by domain', FW_SEO_AI::mentions( 'Try harbourhouse.example for rooms.', 'www.harbourhouse.example', 'Harbour House' ), true );
fw_seo_in_is( 'a mention by name', FW_SEO_AI::mentions( 'Harbour House has sea views.', 'x.test', 'Harbour House' ), true );
fw_seo_in_is( 'not inside another word', FW_SEO_AI::mentions( 'The Harbour Houseboat company…', 'x.test', 'Harbour House' ), false );
fw_seo_in_is( 'no mention', FW_SEO_AI::mentions( 'Try the Seaview Inn.', 'x.test', 'Harbour House' ), false );
$p = FW_SEO_AI::prompt( 'description', 'Crab linguine', '<p>Sweet crab.</p>', 'crab linguine recipe' );
fw_seo_in_is( 'a description prompt asks for 3 of the right length, with the keyphrase', false !== strpos( $p, '120 to 155 characters' ) && false !== strpos( $p, 'crab linguine recipe' ), true );
fw_seo_in_is( 'the page text is sent without markup', false === strpos( $p, '<p>' ), true );
fw_seo_in_is( 'the voice never invents facts', false !== strpos( FW_SEO_AI::voice(), 'Never invent facts' ), true );
fw_seo_in_is( 'without an AI connection, the editor shows nothing', FW_SEO_AI::ready() ? 'skip' : FW_SEO_AI::editor_html( 1 ), FW_SEO_AI::ready() ? 'skip' : '' );

printf( "\n%d passed, %d failed\n", FW_SEO_In_T::$pass, FW_SEO_In_T::$fail );

exit( FW_SEO_In_T::$fail ? 1 : 0 );
