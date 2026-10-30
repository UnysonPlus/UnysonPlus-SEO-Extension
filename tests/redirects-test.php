<?php
/**
 * Contract tests for automatic redirects (FW_SEO_Redirects).
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/redirects-test.php
 *
 * Uses real pages and a real term, created and deleted by the run. Needs pretty
 * permalinks; with plain ones every path is "/" and there is nothing to test.
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

require_once $base . '/includes/class-fw-seo-redirects.php';

class FW_SEO_Redirects_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_redir_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_Redirects_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_Redirects_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/* -------------------------------------------------------------------------- *
 * path()
 * -------------------------------------------------------------------------- */

fw_seo_redir_is( 'a URL reduces to its path', FW_SEO_Redirects::path( 'https://x.test/a/b/?q=1' ), '/a/b' );
fw_seo_redir_is( 'trailing slash does not matter', FW_SEO_Redirects::path( '/a/b/' ), FW_SEO_Redirects::path( '/a/b' ) );
fw_seo_redir_is( 'case does not matter', FW_SEO_Redirects::path( '/About-Us/' ), '/about-us' );
fw_seo_redir_is( 'encoded characters are compared decoded', FW_SEO_Redirects::path( '/caf%C3%A9/' ), '/café' );
fw_seo_redir_is( 'the home page is "/"', FW_SEO_Redirects::path( 'https://x.test/' ), '/' );

if ( '' === (string) get_option( 'permalink_structure' ) ) {
	echo "NOTE  plain permalinks on this install — the live checks need pretty ones; skipped\n";
	printf( "\n%d passed, %d failed\n", FW_SEO_Redirects_T::$pass, FW_SEO_Redirects_T::$fail );
	exit( FW_SEO_Redirects_T::$fail ? 1 : 0 );
}

$saved = get_option( FW_SEO_Redirects::OPTION, null );
delete_option( FW_SEO_Redirects::OPTION );

// The hooks are what a real edit triggers; make sure they are on for the run.
remove_all_actions( 'post_updated' );
add_action( 'post_updated', array( 'FW_SEO_Redirects', '_on_post_updated' ), 20, 3 );
add_action( 'edit_terms', array( 'FW_SEO_Redirects', '_before_term_edit' ), 10, 2 );
add_action( 'edited_term', array( 'FW_SEO_Redirects', '_after_term_edit' ), 10, 3 );

$stamp  = substr( md5( (string) microtime( true ) ), 0, 6 );
$parent = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Services', 'post_name' => "services-$stamp" ) );
$child  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Plumbing', 'post_name' => "plumbing-$stamp", 'post_parent' => $parent ) );
$other  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Trades', 'post_name' => "trades-$stamp" ) );

$old_parent = FW_SEO_Redirects::path( get_permalink( $parent ) );
$old_child  = FW_SEO_Redirects::path( get_permalink( $child ) );

/* -------------------------------------------------------------------------- *
 * Renaming a page moves it and its children
 * -------------------------------------------------------------------------- */

wp_update_post( array( 'ID' => $parent, 'post_name' => "what-we-do-$stamp" ) );

fw_seo_redir_is( 'renaming a page records its old address', isset( FW_SEO_Redirects::all()[ $old_parent ] ), true );
fw_seo_redir_is( 'the old address leads to the new one', FW_SEO_Redirects::path( FW_SEO_Redirects::lookup( $old_parent ) ), FW_SEO_Redirects::path( get_permalink( $parent ) ) );
fw_seo_redir_is( 'its child moved too, and the child\'s old address is recorded', isset( FW_SEO_Redirects::all()[ $old_child ] ), true );
fw_seo_redir_is( 'and leads to the child\'s new address', FW_SEO_Redirects::path( FW_SEO_Redirects::lookup( $old_child ) ), FW_SEO_Redirects::path( get_permalink( $child ) ) );

/* -------------------------------------------------------------------------- *
 * Renaming again: no chains
 * -------------------------------------------------------------------------- */

$middle = FW_SEO_Redirects::path( get_permalink( $parent ) );
wp_update_post( array( 'ID' => $parent, 'post_name' => "our-services-$stamp" ) );
$now = FW_SEO_Redirects::path( get_permalink( $parent ) );

fw_seo_redir_is( 'the first address goes straight to the newest, not through the middle one', FW_SEO_Redirects::path( FW_SEO_Redirects::lookup( $old_parent ) ), $now );
fw_seo_redir_is( 'the middle address goes there too', FW_SEO_Redirects::path( FW_SEO_Redirects::lookup( $middle ) ), $now );

/* -------------------------------------------------------------------------- *
 * Moving a page under another parent
 * -------------------------------------------------------------------------- */

$before_move = FW_SEO_Redirects::path( get_permalink( $child ) );
wp_update_post( array( 'ID' => $child, 'post_parent' => $other ) );

fw_seo_redir_is( 'moving under another parent records the old address', FW_SEO_Redirects::path( FW_SEO_Redirects::lookup( $before_move ) ), FW_SEO_Redirects::path( get_permalink( $child ) ) );
fw_seo_redir_is( 'the moved page now lives under its new parent', 0 === strpos( FW_SEO_Redirects::path( get_permalink( $child ) ), FW_SEO_Redirects::path( get_permalink( $other ) ) . '/' ), true );

/* -------------------------------------------------------------------------- *
 * Edits that do not move anything record nothing
 * -------------------------------------------------------------------------- */

$count = count( FW_SEO_Redirects::all() );
wp_update_post( array( 'ID' => $other, 'post_title' => 'Trades and crafts' ) );
fw_seo_redir_is( 'a title change alone records nothing', count( FW_SEO_Redirects::all() ), $count );

/* -------------------------------------------------------------------------- *
 * Moving back reclaims the address
 * -------------------------------------------------------------------------- */

wp_update_post( array( 'ID' => $parent, 'post_name' => "what-we-do-$stamp" ) );
fw_seo_redir_is( 'an address that is live again is no longer a redirect', isset( FW_SEO_Redirects::all()[ $middle ] ), false );

/* -------------------------------------------------------------------------- *
 * Unpublished content is not a target
 * -------------------------------------------------------------------------- */

wp_update_post( array( 'ID' => $child, 'post_status' => 'draft' ) );
fw_seo_redir_is( 'a drafted page is not a destination — that would just move the 404', FW_SEO_Redirects::lookup( $before_move ), '' );

/* -------------------------------------------------------------------------- *
 * Terms
 * -------------------------------------------------------------------------- */

$term = wp_insert_term( "Tools $stamp", 'category', array( 'slug' => "tools-$stamp" ) );
$tid  = is_wp_error( $term ) ? 0 : (int) $term['term_id'];

if ( $tid ) {
	$old_term = FW_SEO_Redirects::path( get_term_link( $tid ) );
	wp_update_term( $tid, 'category', array( 'slug' => "equipment-$stamp" ) );
	fw_seo_redir_is( 'renaming a category records its old address', FW_SEO_Redirects::path( FW_SEO_Redirects::lookup( $old_term ) ), FW_SEO_Redirects::path( get_term_link( $tid ) ) );

	wp_delete_term( $tid, 'category' );
	FW_SEO_Redirects::_forget_term( $tid );
	fw_seo_redir_is( 'a deleted category is forgotten', isset( FW_SEO_Redirects::all()[ $old_term ] ), false );
}

/* -------------------------------------------------------------------------- *
 * Deleting forgets
 * -------------------------------------------------------------------------- */

wp_delete_post( $parent, true );
FW_SEO_Redirects::_forget_post( $parent );
fw_seo_redir_is( 'a deleted page leaves no entries behind', isset( FW_SEO_Redirects::all()[ $old_parent ] ), false );

wp_delete_post( $child, true );
wp_delete_post( $other, true );

if ( null === $saved ) {
	delete_option( FW_SEO_Redirects::OPTION );
} else {
	update_option( FW_SEO_Redirects::OPTION, $saved, false );
}

fw_seo_redir_is( 'the report renders', false !== strpos( FW_SEO_Redirects::report_html(), 'fw-seo-redirects' ), true );

printf( "\n%d passed, %d failed\n", FW_SEO_Redirects_T::$pass, FW_SEO_Redirects_T::$fail );

exit( FW_SEO_Redirects_T::$fail ? 1 : 0 );
