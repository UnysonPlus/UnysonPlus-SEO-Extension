<?php
/**
 * Contract tests for the social (Open Graph / Twitter) fields.
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/social-test.php
 *
 * The behaviours pinned here are the ones that fail silently: an image ladder
 * that quietly stops at the wrong rung, a Twitter field that stops inheriting
 * from Open Graph, and a relative image URL that looks fine in the markup and is
 * dropped by every crawler that reads it.
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

foreach ( [
	'/includes/class-fw-seo-context.php',
	'/includes/class-fw-seo-tags.php',
	'/includes/class-fw-seo-content.php',
	'/includes/class-fw-seo-image.php',
	'/includes/class-fw-seo-store.php',
	'/includes/class-fw-seo-locations.php',
	'/includes/class-fw-seo-settings.php',
	'/includes/class-fw-seo-chain.php',
	'/includes/class-fw-seo-head.php',
	'/includes/class-fw-seo-sitemap.php',
	'/helpers.php',
] as $file ) {
	require_once $base . $file;
}

class TS {
	public static $pass = 0;
	public static $fail = 0;
}

function ts( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		TS::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	TS::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

// -----------------------------------------------------------------------------
// Fixtures
// -----------------------------------------------------------------------------

$post_id = wp_insert_post( [
	'post_title'   => 'A post that gets shared',
	'post_content' => '<p>Some words.</p><img src="/wp-content/uploads/relative.jpg" alt="" />',
	'post_status'  => 'publish',
	'post_type'    => 'post',
] );

$page_id = wp_insert_post( [
	'post_title'  => 'A page that gets shared',
	'post_status' => 'publish',
	'post_type'   => 'page',
] );

$ctx  = FW_SEO_Context::for_post( $post_id );
$pctx = FW_SEO_Context::for_post( $page_id );

printf( "\nSocial contracts (post %d)\n\n", $post_id );

// -----------------------------------------------------------------------------
// Inheritance — the reason the per-page panel can show four fields, not eight.
// -----------------------------------------------------------------------------

echo "Inheritance\n";

FW_SEO_Chain::flush();

ts(
	'og:title falls through to the SEO title when unset',
	FW_SEO_Chain::resolve( 'og_title', $ctx ),
	FW_SEO_Chain::resolve( 'title', $ctx )
);

ts(
	'twitter:title falls through to og:title when unset',
	FW_SEO_Chain::resolve( 'twitter_title', $ctx ),
	FW_SEO_Chain::resolve( 'og_title', $ctx )
);

FW_SEO_Store::set_post( $post_id, 'og_title', 'A different line for social' );
FW_SEO_Chain::flush();

ts(
	'an og:title override wins over the SEO title',
	FW_SEO_Chain::resolve( 'og_title', $ctx ),
	'A different line for social'
);

ts(
	'...and twitter:title inherits the override, not the SEO title',
	FW_SEO_Chain::resolve( 'twitter_title', $ctx ),
	'A different line for social'
);

FW_SEO_Store::set_post( $post_id, 'twitter_title', 'Something else again' );
FW_SEO_Chain::flush();

ts(
	'a twitter:title override wins over og:title',
	FW_SEO_Chain::resolve( 'twitter_title', $ctx ),
	'Something else again'
);

ts(
	'...without disturbing og:title',
	FW_SEO_Chain::resolve( 'og_title', $ctx ),
	'A different line for social'
);

FW_SEO_Store::set_post( $post_id, 'og_title', '' );
FW_SEO_Store::set_post( $post_id, 'twitter_title', '' );
FW_SEO_Chain::flush();

ts(
	'clearing both rejoins the SEO title',
	FW_SEO_Chain::resolve( 'og_title', $ctx ),
	FW_SEO_Chain::resolve( 'title', $ctx )
);

// A tag in a social override must resolve like anywhere else.
FW_SEO_Store::set_post( $post_id, 'og_description', 'Read %%title%% now' );
FW_SEO_Chain::flush();

ts(
	'template tags resolve inside a social override',
	FW_SEO_Chain::resolve( 'og_description', $ctx ),
	'Read A post that gets shared now'
);

FW_SEO_Store::set_post( $post_id, 'og_description', '' );

// -----------------------------------------------------------------------------
// The image ladder
// -----------------------------------------------------------------------------

echo "\nImage ladder\n";

FW_SEO_Chain::flush();
FW_SEO_Image::flush();

ts(
	'a relative <img> src is absolutised, not emitted as-is',
	FW_SEO_Chain::resolve( 'og_image', $ctx ),
	home_url( '/wp-content/uploads/relative.jpg' )
);

$filter = static function () {
	return 'https://cdn.example.com/from-the-builder.jpg';
};

add_filter( 'fw_seo_page_image', $filter );
FW_SEO_Chain::flush();
FW_SEO_Image::flush();

ts(
	'the page image is filterable',
	FW_SEO_Chain::resolve( 'og_image', $ctx ),
	'https://cdn.example.com/from-the-builder.jpg'
);

remove_filter( 'fw_seo_page_image', $filter );

FW_SEO_Store::set_post( $post_id, 'og_image', 'https://example.com/chosen.jpg' );
FW_SEO_Chain::flush();
FW_SEO_Image::flush();

ts(
	'a chosen image beats everything found on the page',
	FW_SEO_Chain::resolve( 'og_image', $ctx ),
	'https://example.com/chosen.jpg'
);

ts(
	'twitter:image inherits the og:image',
	FW_SEO_Chain::resolve( 'twitter_image', $ctx ),
	'https://example.com/chosen.jpg'
);

FW_SEO_Chain::flush();
FW_SEO_Image::flush();

ts(
	'a page with no image at all resolves to nothing rather than a broken URL',
	FW_SEO_Chain::resolve( 'og_image', $pctx ),
	''
);

// -----------------------------------------------------------------------------
// Value shapes — the seam between the media picker and the store.
// -----------------------------------------------------------------------------

echo "\nValue shapes\n";

ts(
	'the picker array reduces to its URL',
	FW_SEO_Image::url_from_value( [ 'attachment_id' => 7, 'url' => 'https://example.com/a.jpg' ] ),
	'https://example.com/a.jpg'
);

ts(
	'a list of images takes the first',
	FW_SEO_Image::url_from_value( [ [ 'url' => 'https://example.com/a.jpg' ], [ 'url' => 'https://example.com/b.jpg' ] ] ),
	'https://example.com/a.jpg'
);

ts(
	'an empty picker value is empty, not a stray array',
	FW_SEO_Image::url_from_value( [] ),
	''
);

ts(
	'a data: URI is rejected — no crawler will fetch one',
	FW_SEO_Image::url_from_value( 'data:image/png;base64,iVBORw0KGgo=' ),
	''
);

FW_SEO_Store::set_post( $post_id, 'og_image', [ 'attachment_id' => 0, 'url' => 'https://example.com/posted.jpg' ] );

ts(
	'the store accepts what the picker posts and keeps a URL',
	FW_SEO_Store::get_post( $post_id, 'og_image' ),
	'https://example.com/posted.jpg'
);

// -----------------------------------------------------------------------------
// Card type and handles
// -----------------------------------------------------------------------------

echo "\nCard type and handles\n";

FW_SEO_Store::set_post( $post_id, 'twitter_card', 'summary' );

ts(
	'a valid card type is stored',
	FW_SEO_Store::get_post( $post_id, 'twitter_card' ),
	'summary'
);

FW_SEO_Store::set_post( $post_id, 'twitter_card', 'large' );

ts(
	'a value from the OTHER choice field is rejected, not stored',
	FW_SEO_Store::get_post( $post_id, 'twitter_card' ),
	''
);

FW_SEO_Store::set_post( $post_id, 'max_image_preview', 'large' );

ts(
	'...while the field that owns "large" still accepts it',
	FW_SEO_Store::get_post( $post_id, 'max_image_preview' ),
	'large'
);

ts( 'a bare handle is prefixed', fw_seo_at_handle( 'unysonplus' ), '@unysonplus' );
ts( 'an @handle is left alone', fw_seo_at_handle( '@unysonplus' ), '@unysonplus' );
ts( 'a profile URL is reduced to the handle', fw_seo_at_handle( 'https://x.com/unysonplus' ), '@unysonplus' );
ts( 'a trailing slash does not become part of the handle', fw_seo_at_handle( 'https://twitter.com/unysonplus/' ), '@unysonplus' );
ts( 'an empty handle stays empty rather than becoming "@"', fw_seo_at_handle( '' ), '' );


// -----------------------------------------------------------------------------
// The size floor
//
// The rung that guesses must skip an image too small to be a card. This is the
// bug the first live page showed: a 441 x 84 logo chosen as the share image,
// which every network renders as a smear or refuses outright.
// -----------------------------------------------------------------------------

echo "\nSize floor\n";

/**
 * An attachment record with known dimensions. No file is written — nothing in
 * the ladder opens the image, it reads the metadata WordPress already keeps.
 */
function ts_attachment( $name, $width, $height ) {
	$uploads = wp_upload_dir();
	$id      = wp_insert_post( [
		'post_title'     => $name,
		'post_type'      => 'attachment',
		'post_mime_type' => 'image/png',
		'post_status'    => 'inherit',
		'guid'           => $uploads['url'] . '/' . $name,
	] );

	update_post_meta( $id, '_wp_attached_file', ltrim( $uploads['subdir'] . '/' . $name, '/' ) );
	wp_update_attachment_metadata( $id, [ 'width' => $width, 'height' => $height, 'file' => ltrim( $uploads['subdir'] . '/' . $name, '/' ) ] );

	return [ $id, $uploads['url'] . '/' . $name ];
}

list( $logo_id, $logo_url )   = ts_attachment( 'ts-logo.png', 441, 84 );
list( $photo_id, $photo_url ) = ts_attachment( 'ts-photo.png', 1200, 630 );

$sized_id = wp_insert_post( [
	'post_title'   => 'A post whose first image is a logo',
	'post_content' => '<img src="' . $logo_url . '" alt="" /><p>Words.</p><img src="' . $photo_url . '" alt="" />',
	'post_status'  => 'publish',
	'post_type'    => 'post',
] );

$sized_ctx = FW_SEO_Context::for_post( $sized_id );

FW_SEO_Chain::flush();
FW_SEO_Image::flush();

ts(
	'the dimensions of a hosted image are read',
	FW_SEO_Image::dimensions( $photo_url ),
	[ 'width' => 1200, 'height' => 630 ]
);

ts(
	'a logo too small for a card is skipped in favour of the real photograph',
	FW_SEO_Chain::resolve( 'og_image', $sized_ctx ),
	$photo_url
);

// Only the logo this time — nothing on the page clears the floor.
$only_logo_id = wp_insert_post( [
	'post_title'   => 'A post with nothing but a logo',
	'post_content' => '<img src="' . $logo_url . '" alt="" />',
	'post_status'  => 'publish',
	'post_type'    => 'post',
] );

FW_SEO_Chain::flush();
FW_SEO_Image::flush();

ts(
	'an image we measured and know is too small is never used',
	FW_SEO_Chain::resolve( 'og_image', FW_SEO_Context::for_post( $only_logo_id ) ),
	''
);

// A featured image is an explicit decision and is not second-guessed.
set_post_thumbnail( $only_logo_id, $logo_id );

FW_SEO_Chain::flush();
FW_SEO_Image::flush();

ts(
	'...but a featured image is honoured even when it is small, because it was chosen',
	FW_SEO_Chain::resolve( 'og_image', FW_SEO_Context::for_post( $only_logo_id ) ),
	$logo_url
);

wp_delete_post( $sized_id, true );
wp_delete_post( $only_logo_id, true );
wp_delete_post( $logo_id, true );
wp_delete_post( $photo_id, true );

echo "\nObject type\n";

ts( 'a post is an article', fw_seo_og_type( $ctx ), 'article' );
ts( 'a page is a website, not an article', fw_seo_og_type( $pctx ), 'website' );

wp_delete_post( $post_id, true );
wp_delete_post( $page_id, true );

printf( "\n%d passed, %d failed\n", TS::$pass, TS::$fail );

if ( TS::$fail ) {
	exit( 1 );
}
