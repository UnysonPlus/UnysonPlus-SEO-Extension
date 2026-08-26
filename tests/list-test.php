<?php
/**
 * Contract tests for the list-table columns, filters and inline editing.
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/list-test.php
 *
 * The inline-edit assertions are the important ones. Quick Edit posts a form
 * that never rendered the SEO fields, so the wrong wiring here does not throw —
 * it silently erases every override on the post being edited.
 */

if ( ! class_exists( 'FW_SEO_List' ) ) {
	require_once WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo/includes/class-fw-seo-list.php';
}

class TL {
	public static $pass = 0;
	public static $fail = 0;
}

function tl( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		TL::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	TL::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

wp_set_current_user( 1 );
set_current_screen( 'edit-post' );

$list = new FW_SEO_List( fw()->extensions->get( 'seo' ) );

echo "\nList table contracts\n\n";

// -----------------------------------------------------------------------------
// Columns
// -----------------------------------------------------------------------------

echo "Columns\n";

$columns = $list->_filter_columns( [ 'title' => 'Title' ] );

tl( 'the SEO columns are added', isset( $columns['fw_seo_title'], $columns['fw_seo_description'] ), true );
tl( '...after the existing ones, not instead of them', isset( $columns['title'] ), true );

$post_id = wp_insert_post( [
	'post_title'   => 'A listed post',
	'post_content' => '<p>Something to describe.</p>',
	'post_status'  => 'publish',
	'post_type'    => 'post',
] );

ob_start();
$list->_action_post_column( 'fw_seo_title', $post_id );
$cell = ob_get_clean();

tl(
	'a page following its template shows the RESOLVED title, not an empty cell',
	false !== strpos( $cell, 'A listed post' ),
	true
);

tl( '...labelled as coming from the template', false !== strpos( $cell, 'Template' ), true );

FW_SEO_Store::set_post( $post_id, 'title', 'A hand-written title' );
FW_SEO_Chain::flush();

ob_start();
$list->_action_post_column( 'fw_seo_title', $post_id );
$cell = ob_get_clean();

tl( 'an overridden title is shown', false !== strpos( $cell, 'A hand-written title' ), true );
tl( '...and labelled as custom', false !== strpos( $cell, 'Custom' ), true );

tl(
	'the inline data carries the STORED override, not the resolved value',
	false !== strpos( $cell, '"title":"A hand-written title"' ),
	true
);

FW_SEO_Store::set_post( $post_id, 'title', '' );

// -----------------------------------------------------------------------------
// Filters
// -----------------------------------------------------------------------------

echo "\nFilters\n";

FW_SEO_Store::set_post( $post_id, 'title', 'Customised' );
FW_SEO_Store::set_post( $post_id, 'noindex', true );

/**
 * Run the main-query filter for real. `is_main_query()` compares against the
 * global, so the global is what has to be set — faking it any other way tests
 * the fake.
 */
function tl_filtered( FW_SEO_List $list, $filter ) {
	$_GET['fw_seo_filter'] = $filter;

	$query                   = new WP_Query();
	$GLOBALS['wp_the_query'] = $query;
	$query->query_vars       = [ 'post_type' => 'post', 'fields' => 'ids', 'posts_per_page' => -1 ];

	$list->_action_apply_filter( $query );

	return get_posts( array_merge( $query->query_vars, [ 'meta_query' => $query->get( 'meta_query' ) ?: [] ] ) );
}

tl( 'a customised post is found by "has custom SEO"', in_array( $post_id, tl_filtered( $list, 'custom' ), true ), true );
tl( '...and excluded from "following the template"', in_array( $post_id, tl_filtered( $list, 'no_custom' ), true ), false );
tl( 'a noindexed post is found by "not indexed"', in_array( $post_id, tl_filtered( $list, 'noindex' ), true ), true );

FW_SEO_Store::set_post( $post_id, 'og_image', 'https://example.com/share.jpg' );

tl(
	'a post with a share image is excluded from "no custom share image"',
	in_array( $post_id, tl_filtered( $list, 'no_social' ), true ),
	false
);

FW_SEO_Store::set_post( $post_id, 'og_image', '' );
FW_SEO_Store::set_post( $post_id, 'noindex', false );
FW_SEO_Store::set_post( $post_id, 'title', '' );

$_GET['fw_seo_filter'] = 'nonsense';
$unknown               = new WP_Query();
$GLOBALS['wp_the_query'] = $unknown;
$unknown->query_vars   = [ 'post_type' => 'post' ];
$list->_action_apply_filter( $unknown );

tl( 'an unknown filter value is ignored rather than applied', $unknown->get( 'meta_query' ), '' );

$_GET = [];

// -----------------------------------------------------------------------------
// Inline editing
//
// The nonce case is the one that matters: Quick Edit submits a form that never
// rendered the SEO fields, so a handler that trusts the request wipes every
// override on the post without erroring.
// -----------------------------------------------------------------------------

echo "\nInline editing\n";

$post = get_post( $post_id );

FW_SEO_Store::set_post( $post_id, 'title', 'Set in the editor' );

$_POST = [];
$list->_action_save_quick_edit( $post_id, $post );

tl(
	'a save with no SEO nonce leaves the overrides alone',
	FW_SEO_Store::get_post( $post_id, 'title' ),
	'Set in the editor'
);

$_POST = [
	'fw_seo_quick_nonce' => 'not-a-real-nonce',
	'fw_seo_title'       => 'Should not land',
];
$list->_action_save_quick_edit( $post_id, $post );

tl(
	'a save with a BAD nonce is rejected, not merely unverified',
	FW_SEO_Store::get_post( $post_id, 'title' ),
	'Set in the editor'
);

$_POST = [
	'fw_seo_quick_nonce' => wp_create_nonce( FW_SEO_List::NONCE_QUICK ),
	'fw_seo_title'       => 'Edited inline',
	'fw_seo_description' => 'A description typed into the list',
	'fw_seo_noindex'     => '1',
];
$list->_action_save_quick_edit( $post_id, $post );

tl( 'a valid inline edit saves the title', FW_SEO_Store::get_post( $post_id, 'title' ), 'Edited inline' );
tl( '...the description', FW_SEO_Store::get_post( $post_id, 'description' ), 'A description typed into the list' );
tl( '...and the noindex switch', FW_SEO_Store::get_post( $post_id, 'noindex' ), true );

$_POST['fw_seo_title']   = '';
$_POST['fw_seo_noindex'] = '';
$list->_action_save_quick_edit( $post_id, $post );

tl( 'clearing a field rejoins the template', FW_SEO_Store::get_post( $post_id, 'title' ), '' );
tl(
	'...by DELETING the row, not storing an empty override',
	metadata_exists( 'post', $post_id, '_fw_seo_title' ),
	false
);
tl( 'unticking noindex clears it', FW_SEO_Store::get_post( $post_id, 'noindex' ), false );

$_POST = [];

wp_delete_post( $post_id, true );

printf( "\n%d passed, %d failed\n", TL::$pass, TL::$fail );

if ( TL::$fail ) {
	exit( 1 );
}
