<?php
/**
 * The pre-fill contract.
 *
 * The SEO title and meta description ship pre-filled and editable — the title as
 * its template with the tags intact, the description as the generated prose —
 * matching how Yoast and AIOSEO present them.
 *
 * The property this file exists to pin: an UNTOUCHED pre-fill must never become a
 * stored override. If it did, every post would carry a frozen copy of the
 * template and a later site-wide title change would silently update nothing.
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file  *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/prefill-test.php
 */

require_once WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo/includes/class-fw-seo-admin.php';

wp_set_current_user( 1 );

class PT { public static $pass = 0; public static $fail = 0; }

function t( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		PT::$pass ++;
		printf( "  ok   %s\n", $label );
		return;
	}
	PT::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

$post_id = wp_insert_post( [
	'post_title'   => 'Prefill fixture post',
	'post_content' => '<p>Some real prose that a description can be generated from, at length.</p>',
	'post_status'  => 'publish',
	'post_type'    => 'post',
] );

foreach ( array_keys( FW_SEO_Store::fields() ) as $f ) {
	FW_SEO_Store::set_post( $post_id, $f, '' );
}

$ctx     = FW_SEO_Context::for_post( $post_id );
$prefill = FW_SEO_Admin::prefill_values( $ctx );

echo "\nPre-fill values\n";
printf( "  title       %s\n", $prefill['seo_title'] );
printf( "  description %s\n\n", substr( $prefill['seo_description'], 0, 64 ) . '…' );

echo "Contract\n";

t(
	'the title field is pre-filled with the TEMPLATE, tags intact (as Yoast/AIOSEO show it)',
	$prefill['seo_title'],
	'%%title%% %%sep%% %%sitename%%'
);

t(
	'the description field is pre-filled with resolved prose, not tags',
	false === strpos( $prefill['seo_description'], '%%' ) && '' !== $prefill['seo_description'],
	true
);

// --- Save with both fields untouched ---------------------------------------

$options = FW_SEO_Admin::get_options( $post_id, null, $ctx );

$submit = [
	'seo_title'                  => $prefill['seo_title'],
	'seo_title__pristine'        => $prefill['seo_title'],
	'seo_description'            => $prefill['seo_description'],
	'seo_description__pristine'  => $prefill['seo_description'],
];

$_POST[ fw()->backend->get_options_name_attr_prefix() ] = $submit;
$_POST['fw_seo_nonce'] = wp_create_nonce( FW_SEO_Admin::NONCE_SAVE );

$admin = new FW_SEO_Admin( fw()->extensions->get( 'seo' ) );
$admin->_action_save_post( $post_id, get_post( $post_id ) );

t(
	'saving an UNTOUCHED pre-filled title stores nothing',
	metadata_exists( 'post', $post_id, '_fw_seo_title' ),
	false
);

t(
	'saving an UNTOUCHED pre-filled description stores nothing',
	metadata_exists( 'post', $post_id, '_fw_seo_description' ),
	false
);

t(
	'...so the post is still bound to its template',
	FW_SEO_Chain::source( 'title', FW_SEO_Context::for_post( $post_id ) ),
	'template'
);

// --- Save with the user having EDITED both ---------------------------------

$submit['seo_title']       = 'A hand-written title %%sep%% %%sitename%%';
$submit['seo_description'] = 'A hand-written description.';

$_POST[ fw()->backend->get_options_name_attr_prefix() ] = $submit;

$admin->_action_save_post( $post_id, get_post( $post_id ) );

t(
	'an EDITED title is stored as a real override',
	FW_SEO_Store::get_post( $post_id, 'title' ),
	'A hand-written title %%sep%% %%sitename%%'
);

t(
	'an EDITED description is stored as a real override',
	FW_SEO_Store::get_post( $post_id, 'description' ),
	'A hand-written description.'
);

FW_SEO_Chain::flush();
FW_SEO_Tags::flush();

t(
	'the stored override resolves its own tags on the front end',
	FW_SEO_Chain::resolve( 'title', FW_SEO_Context::for_post( $post_id ) ),
	'A hand-written title | ' . get_bloginfo( 'name' )
);

// --- Whitespace should not read as an edit ---------------------------------

$submit['seo_title']       = '  ' . $prefill['seo_title'] . ' ';
$submit['seo_description'] = $prefill['seo_description'];

$_POST[ fw()->backend->get_options_name_attr_prefix() ] = $submit;

$admin->_action_save_post( $post_id, get_post( $post_id ) );

t(
	'reverting to the pre-fill (even with stray whitespace) clears the override again',
	metadata_exists( 'post', $post_id, '_fw_seo_title' ),
	false
);

// --- A field that never reached the browser must not be wiped ---------------
//
// The framework's tab container supports lazy rendering: an unopened tab's
// fields live in a data-attribute rather than in the form. If the save path
// treated "absent from the submission" as "the user cleared it", opening the
// post and pressing Update would silently destroy every Advanced-tab value.

FW_SEO_Store::set_post( $post_id, 'canonical', 'https://example.com/kept/' );
FW_SEO_Store::set_post( $post_id, 'noindex', true );

$partial = [
	'seo_title'                 => $prefill['seo_title'],
	'seo_title__pristine'       => $prefill['seo_title'],
	'seo_description'           => $prefill['seo_description'],
	'seo_description__pristine' => $prefill['seo_description'],
	// No seo_canonical, no seo_noindex — as if the Advanced tab never rendered.
];

$_POST[ fw()->backend->get_options_name_attr_prefix() ] = $partial;

$admin->_action_save_post( $post_id, get_post( $post_id ) );

t(
	'a canonical absent from the submission survives the save',
	FW_SEO_Store::get_post( $post_id, 'canonical' ),
	'https://example.com/kept/'
);

t(
	'a robots flag absent from the submission survives the save',
	FW_SEO_Store::get_post( $post_id, 'noindex' ),
	true
);

// ...but a field that WAS on the page and was cleared must still clear.
$partial['seo_canonical'] = '';

$_POST[ fw()->backend->get_options_name_attr_prefix() ] = $partial;

$admin->_action_save_post( $post_id, get_post( $post_id ) );

t(
	'a canonical that was present and emptied is still cleared',
	FW_SEO_Store::get_post( $post_id, 'canonical' ),
	''
);

// --- Template change must still propagate ----------------------------------

FW_SEO_Chain::flush();
FW_SEO_Tags::flush();

t(
	'a post saved untouched still follows a later template change',
	FW_SEO_Tags::render(
		FW_SEO_Settings::template( 'title', FW_SEO_Context::for_post( $post_id ) ),
		FW_SEO_Context::for_post( $post_id )
	),
	'Prefill fixture post | ' . get_bloginfo( 'name' )
);

wp_delete_post( $post_id, true );

printf( "\n%d passed, %d failed\n", PT::$pass, PT::$fail );

if ( PT::$fail ) {
	exit( 1 );
}
