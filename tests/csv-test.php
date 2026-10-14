<?php
/**
 * Contract tests for the bulk-edit spreadsheet.
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/csv-test.php
 *
 * The assertions that matter are the destructive ones. A CSV import writes
 * across a whole site in one click, so the properties pinned here are the ones
 * whose failure would be expensive and silent: posts are never created or
 * removed, an id that is not in the file is never visited, and a column the
 * file does not contain is never touched.
 */

class TV {
	public static $pass = 0;
	public static $fail = 0;
}

function tv( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		TV::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	TV::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

wp_set_current_user( 1 );

$a = wp_insert_post( [ 'post_title' => 'CSV fixture A', 'post_status' => 'publish', 'post_type' => 'post', 'post_author' => 1 ] );
$b = wp_insert_post( [ 'post_title' => 'CSV fixture B', 'post_status' => 'publish', 'post_type' => 'post', 'post_author' => 1 ] );

FW_SEO_Store::set_post( $a, 'title', 'A stored title' );
FW_SEO_Store::set_post( $b, 'description', 'B stored description' );

$before_count = (int) wp_count_posts( 'post' )->publish;

printf( "\nSpreadsheet contracts (posts %d, %d)\n\n", $a, $b );

echo "Export\n";

$row = FW_SEO_CSV::row( $a );

tv( 'the row carries the post id', $row['id'], (string) $a );
tv( 'the stored override is written into the editable column', $row['seo_title'], 'A stored title' );
tv( 'the RESOLVED value is written beside it as context', $row['current_title'] !== '', true );
tv( 'a boolean exports as 1/0, not TRUE/FALSE', $row['noindex'], '0' );

tv( 'a formula-leading value is neutralised', FW_SEO_CSV::safe_cell( '=cmd|calc' ), "\t=cmd|calc" );
tv( '...and a minus-leading one', FW_SEO_CSV::safe_cell( '-1+1' ), "\t-1+1" );
tv( 'ordinary text is untouched', FW_SEO_CSV::safe_cell( 'A normal title' ), 'A normal title' );
tv( 'the prefix is stripped on the way back in', FW_SEO_CSV::unsafe_cell( "\t=cmd|calc" ), '=cmd|calc' );

echo "\nParsing\n";

$dir  = sys_get_temp_dir() . '/fw-seo-csv-test';
wp_mkdir_p( $dir );

/** Write a sheet and read it back through the real parser. */
function tv_write( $dir, $name, $lines ) {
	$path = $dir . '/' . $name;
	file_put_contents( $path, "\xEF\xBB\xBF" . implode( "\n", $lines ) );

	return $path;
}

$path = tv_write( $dir, 'basic.csv', [
	'ID,SEO title,Meta description',
	$GLOBALS['a'] ?? '0',
] );

$parsed = FW_SEO_CSV::parse( tv_write( $dir, 'headers.csv', [
	'ID,SEO title',
	$a . ',Renamed from the sheet',
] ) );

tv( 'the BOM does not break the first header', in_array( 'id', $parsed['headers'], true ), true );
tv( 'only the columns present are reported', $parsed['headers'], [ 'id', 'seo_title' ] );
tv( 'the row is read', $parsed['rows'][0]['seo_title'] ?? '', 'Renamed from the sheet' );

$reordered = FW_SEO_CSV::parse( tv_write( $dir, 'reordered.csv', [
	'Meta description,ID',
	'Swapped around,' . $a,
] ) );

tv( 'columns may be reordered', $reordered['rows'][0]['meta_description'] ?? '', 'Swapped around' );

$noid = FW_SEO_CSV::parse( tv_write( $dir, 'noid.csv', [ 'SEO title', 'Nothing to match on' ] ) );

tv( 'a file with no ID column is REFUSED, not guessed at', '' !== $noid['error'], true );
tv( '...and yields no rows to apply', $noid['rows'], [] );

echo "\nWhat an import will not do\n";

// A sheet that mentions only ONE of the two posts, and only ONE column.
$partial = FW_SEO_CSV::parse( tv_write( $dir, 'partial.csv', [
	'ID,SEO title',
	$a . ',Edited in a spreadsheet',
] ) );

$preview = FW_SEO_CSV::process( $partial['rows'], $partial['headers'], false );

tv( 'a preview reports the change', $preview['changes'], 1 );
tv( '...and writes nothing', FW_SEO_Store::get_post( $a, 'title' ), 'A stored title' );

FW_SEO_CSV::process( $partial['rows'], $partial['headers'], true );

tv( 'applying writes the edited column', FW_SEO_Store::get_post( $a, 'title' ), 'Edited in a spreadsheet' );

tv(
	'a column the file does NOT contain is left alone',
	FW_SEO_Store::get_post( $a, 'description' ),
	''
);

tv(
	'a post the file does not mention is NOT visited',
	FW_SEO_Store::get_post( $b, 'description' ),
	'B stored description'
);

tv( 'no post was created or removed', (int) wp_count_posts( 'post' )->publish, $before_count );
tv( 'the posts themselves are untouched', get_post( $b )->post_title, 'CSV fixture B' );

echo "\nMissing ids and blanks\n";

$ghost = FW_SEO_CSV::parse( tv_write( $dir, 'ghost.csv', [
	'ID,SEO title',
	'99999901,A title for a post that is not here',
	$a . ',Still fine',
] ) );

$res = FW_SEO_CSV::process( $ghost['rows'], $ghost['headers'], true );

tv( 'an unknown id is reported', in_array( 99999901, $res['missing'], true ), true );
tv( '...and counted as skipped, not applied', $res['skipped'], 1 );
tv( 'the real row in the same file still applies', FW_SEO_Store::get_post( $a, 'title' ), 'Still fine' );
tv( 'no post was created for the unknown id', get_post( 99999901 ), null );

// A blank in a column that IS present means "clear" — the export puts the
// current override in the cell, so emptying it is a deliberate act.
$clear = FW_SEO_CSV::parse( tv_write( $dir, 'clear.csv', [
	'ID,SEO title',
	$a . ',',
] ) );

$res = FW_SEO_CSV::process( $clear['rows'], $clear['headers'], false );

tv( 'a blank in a present column previews as a CLEAR', $res['clears'], 1 );

FW_SEO_CSV::process( $clear['rows'], $clear['headers'], true );

tv( 'applying it removes the override', FW_SEO_Store::get_post( $a, 'title' ), '' );
tv( '...by deleting the row, not storing an empty one', metadata_exists( 'post', $a, '_fw_seo_title' ), false );

echo "\nRe-importing an unchanged sheet\n";

FW_SEO_Store::set_post( $a, 'title', 'Settled value' );
FW_SEO_Store::set_post( $a, 'noindex', true );

$round = FW_SEO_CSV::parse( tv_write( $dir, 'round.csv', [
	'ID,SEO title,No index',
	$a . ',Settled value,1',
] ) );

$res = FW_SEO_CSV::process( $round['rows'], $round['headers'], false );

tv( 'an unchanged sheet reports NO changes', $res['changes'], 0 );
tv( '...and no clears', $res['clears'], 0 );

$yes = FW_SEO_CSV::parse( tv_write( $dir, 'yes.csv', [
	'ID,No index',
	$a . ',yes',
] ) );

tv(
	'a spreadsheet that wrote "yes" instead of 1 is still read as true',
	FW_SEO_CSV::process( $yes['rows'], $yes['headers'], false )['changes'],
	0
);

wp_delete_post( $a, true );
wp_delete_post( $b, true );
array_map( 'unlink', glob( $dir . '/*.csv' ) ?: [] );

printf( "\n%d passed, %d failed\n", TV::$pass, TV::$fail );

if ( TV::$fail ) {
	exit( 1 );
}
