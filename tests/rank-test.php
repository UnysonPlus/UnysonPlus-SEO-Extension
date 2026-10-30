<?php
/**
 * Contract tests for "Where your pages rank" (FW_SEO_Rank).
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/rank-test.php
 *
 * The live API needs a connected property; these pin what is ours — which rows
 * count as "almost on page one", the impressions floor, and the direction of
 * the position change, which is the easiest thing in this feature to get
 * backwards (a smaller position number is a BETTER position).
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

require_once $base . '/includes/class-fw-seo-rank.php';

class FW_SEO_Rank_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_rank_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_Rank_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_Rank_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

function fw_seo_rank_row( $page, $query, $position, $impressions, $clicks = 0 ) {
	return array( 'keys' => array( $page, $query ), 'position' => $position, 'impressions' => $impressions, 'clicks' => $clicks );
}

/* -------------------------------------------------------------------------- *
 * near_page_one_rows()
 * -------------------------------------------------------------------------- */

$rows = FW_SEO_Rank::near_page_one_rows( array(
	fw_seo_rank_row( '/a/', 'already top', 2.1, 900 ),
	fw_seo_rank_row( '/b/', 'in band, popular', 9.4, 500, 4 ),
	fw_seo_rank_row( '/c/', 'in band, quieter', 13.0, 120 ),
	fw_seo_rank_row( '/d/', 'in band, too few to mean anything', 10.0, 5 ),
	fw_seo_rank_row( '/e/', 'page three', 24.0, 800 ),
	fw_seo_rank_row( '/f/', 'edge low', 8.0, 40 ),
	fw_seo_rank_row( '/g/', 'edge high', 15.0, 40 ),
	fw_seo_rank_row( '/h/', 'just past', 15.1, 400 ),
) );

fw_seo_rank_is(
	'only positions 8 to 15 with enough impressions are kept, most-seen first',
	array_column( $rows, 'query' ),
	array( 'in band, popular', 'in band, quieter', 'edge low', 'edge high' )
);
fw_seo_rank_is( 'the band includes both edges', in_array( 'edge low', array_column( $rows, 'query' ), true ) && in_array( 'edge high', array_column( $rows, 'query' ), true ), true );
fw_seo_rank_is( 'a position already on page one is not "almost"', in_array( 'already top', array_column( $rows, 'query' ), true ), false );
fw_seo_rank_is( 'below the impressions floor is dropped — five people is not a position', in_array( 'in band, too few to mean anything', array_column( $rows, 'query' ), true ), false );
fw_seo_rank_is( 'the page and query come from the two dimension keys in order', array( $rows[0]['page'], $rows[0]['query'] ), array( '/b/', 'in band, popular' ) );
fw_seo_rank_is( 'position is rounded to one decimal', $rows[0]['position'], 9.4 );
fw_seo_rank_is( 'an empty response is an empty list', FW_SEO_Rank::near_page_one_rows( array() ), array() );

/* -------------------------------------------------------------------------- *
 * merge_windows() — the direction of change
 * -------------------------------------------------------------------------- */

$q = static function ( $query, $position, $impressions ) {
	return array( 'keys' => array( $query ), 'position' => $position, 'impressions' => $impressions, 'clicks' => 0 );
};

$merged = FW_SEO_Rank::merge_windows(
	array( $q( 'climbed', 6.0, 300 ), $q( 'slipped', 12.0, 200 ), $q( 'brand new', 9.0, 100 ) ),
	array( $q( 'climbed', 11.0, 250 ), $q( 'slipped', 7.5, 220 ), $q( 'gone now', 4.0, 90 ) )
);
$by = array_column( $merged, null, 'query' );

fw_seo_rank_is( 'moving from 11 to 6 is +5: up is positive, because a smaller number is better', $by['climbed']['change'], 5.0 );
fw_seo_rank_is( 'moving from 7.5 to 12 is -4.5', $by['slipped']['change'], -4.5 );
fw_seo_rank_is( 'a query with no previous row is new, not "+0"', $by['brand new']['change'], null );
fw_seo_rank_is( 'a query only in the previous window does not appear — the list is about now', isset( $by['gone now'] ), false );
fw_seo_rank_is( 'sorted by this window\'s impressions', array_column( $merged, 'query' ), array( 'climbed', 'slipped', 'brand new' ) );

/* -------------------------------------------------------------------------- *
 * change_html()
 * -------------------------------------------------------------------------- */

fw_seo_rank_is( 'an improvement renders as an up arrow', false !== strpos( FW_SEO_Rank::change_html( 3.2 ), 'fw-seo-rank-up' ), true );
fw_seo_rank_is( 'a drop renders as a down arrow', false !== strpos( FW_SEO_Rank::change_html( -2.0 ), 'fw-seo-rank-down' ), true );
fw_seo_rank_is( 'a wobble under half a place is shown as no change', false !== strpos( FW_SEO_Rank::change_html( 0.3 ), 'fw-seo-rank-flat' ), true );
fw_seo_rank_is( 'a new query says so', false !== strpos( FW_SEO_Rank::change_html( null ), 'fw-seo-rank-new' ), true );

/* -------------------------------------------------------------------------- *
 * Without a connection
 * -------------------------------------------------------------------------- */

require_once $base . '/includes/class-fw-seo-console.php';
$saved = get_option( FW_SEO_Console::TOKENS_OPTION, null );
delete_option( FW_SEO_Console::TOKENS_OPTION );

fw_seo_rank_is( 'no connection: the report explains rather than fataling', false !== strpos( FW_SEO_Rank::report_html(), 'Connect Search Console' ), true );
fw_seo_rank_is( 'no connection: the editor shows nothing', FW_SEO_Rank::editor_html( 1 ), '' );
fw_seo_rank_is( 'no connection: no fetch is attempted', FW_SEO_Rank::near_page_one()['reason'], 'notconnected' );

if ( null !== $saved ) {
	update_option( FW_SEO_Console::TOKENS_OPTION, $saved, false );
}

printf( "\n%d passed, %d failed\n", FW_SEO_Rank_T::$pass, FW_SEO_Rank_T::$fail );

exit( FW_SEO_Rank_T::$fail ? 1 : 0 );
