<?php
/**
 * Contract tests for the content-freshness report.
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/freshness-test.php
 *
 * The one that matters is the word count: on a page-builder page the prose
 * lives in the builder TREE, and counting post_content would measure rendered
 * markup — every builder page would read as thin and the report would be noise.
 */

class TF {
	public static $pass = 0;
	public static $fail = 0;
}

function tf( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		TF::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	TF::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

echo "\nFreshness contracts\n\n";

echo "Counting words\n";

$prose = wp_insert_post( [
	'post_title'   => 'Freshness fixture with prose',
	'post_content' => '<p>' . implode( ' ', array_fill( 0, 40, 'word' ) ) . '</p>',
	'post_status'  => 'publish',
	'post_type'    => 'post',
] );

tf( 'words are counted from the visible prose', FW_SEO_Freshness::words( $prose ), 40 );

$empty = wp_insert_post( [ 'post_title' => 'Freshness fixture empty', 'post_status' => 'publish', 'post_type' => 'post' ] );
tf( 'a page with nothing on it counts zero, not one', FW_SEO_Freshness::words( $empty ), 0 );

$accented = wp_insert_post( [
	'post_title'   => 'Freshness fixture accented',
	'post_content' => '<p>Überprüfung Gerätes Händler Straße Grüße</p>',
	'post_status'  => 'publish',
	'post_type'    => 'post',
] );

// str_word_count would undercount these badly; splitting on whitespace does not.
tf( 'non-ASCII prose is counted correctly', FW_SEO_Freshness::words( $accented ), 5 );

echo "\nFlagging\n";

$rows  = FW_SEO_Freshness::rows( 100 );
$byId  = [];
foreach ( $rows as $row ) { $byId[ $row['id'] ] = $row; }

tf( 'a short page is flagged thin', in_array( 'thin', $byId[ $empty ]['flags'] ?? [], true ), true );
tf( 'a page just written is not flagged stale', in_array( 'stale', $byId[ $prose ]['flags'] ?? [], true ), false );

FW_SEO_Store::set_post( $empty, 'noindex', true );
$rows = FW_SEO_Freshness::rows( 100 );
$byId = [];
foreach ( $rows as $row ) { $byId[ $row['id'] ] = $row; }

// A page somebody deliberately hid is not a problem to nag about.
tf( 'a deliberately hidden page says so', in_array( 'noindex', $byId[ $empty ]['flags'] ?? [], true ), true );

FW_SEO_Store::set_post( $empty, 'noindex', false );

echo "\nOrdering and limits\n";

$rows = FW_SEO_Freshness::rows( 5 );
tf( 'the limit is respected', count( $rows ) <= 5, true );

$ordered = true;
for ( $i = 1; $i < count( $rows ); $i ++ ) {
	if ( $rows[ $i ]['modified'] < $rows[ $i - 1 ]['modified'] ) { $ordered = false; }
}
tf( 'oldest-touched comes first', $ordered, true );

tf( 'an absurd limit is clamped rather than run', count( FW_SEO_Freshness::rows( 99999 ) ) <= 100, true );

$summary = FW_SEO_Freshness::summary();
tf( 'the summary counts published pages', $summary['total'] > 0, true );
tf( '...and stale is never more than the total', $summary['stale'] <= $summary['total'], true );

wp_delete_post( $prose, true );
wp_delete_post( $empty, true );
wp_delete_post( $accented, true );

printf( "\n%d passed, %d failed\n", TF::$pass, TF::$fail );

if ( TF::$fail ) {
	exit( 1 );
}
