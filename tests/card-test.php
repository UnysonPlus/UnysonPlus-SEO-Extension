<?php
/**
 * Contract tests for generated share images.
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/card-test.php
 *
 * The property that matters most is the ladder position: a card must only ever
 * appear where there was NOTHING, never in place of a picture the page already
 * had. Everything else is drawing.
 */

class TD {
	public static $pass = 0;
	public static $fail = 0;
}

function td( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		TD::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	TD::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

echo "\nShare-card contracts\n\n";

$font = FW_SEO_Card::font_path();

echo "Drawing\n";
td( 'a usable font was found on this server', '' !== $font, true );

if ( '' === $font ) {
	echo "  (no font: the drawing tests cannot run, which is itself the documented behaviour)\n";
	printf( "\n%d passed, %d failed\n", TD::$pass, TD::$fail );
	exit( TD::$fail ? 1 : 0 );
}

td( 'so cards are available', FW_SEO_Card::available(), true );

$short = FW_SEO_Card::wrap( 'Contact us', $font, 68, 1020, 20 );
td( 'a short title is one line', count( $short ), 1 );

$long = FW_SEO_Card::wrap( 'How to choose the right prefab home for your family', $font, 68, 1020, 20 );
td( 'a long title wraps onto several', count( $long ) > 1, true );
td( '...without losing any words', trim( implode( ' ', $long ) ), 'How to choose the right prefab home for your family' );

$capped = FW_SEO_Card::wrap( str_repeat( 'overflowing ', 60 ), $font, 68, 1020, 3 );
td( 'a title past the line cap is truncated, not spilled', count( $capped ), 3 );
td( '...and says so with an ellipsis', '…' === mb_substr( end( $capped ), -1 ), true );

echo "\nColours\n";

$palette = FW_SEO_Card::palette();
td( 'a palette is produced', isset( $palette['bg'], $palette['fg'], $palette['accent'] ), true );
td( 'each colour is an RGB triplet', count( $palette['bg'] ), 3 );

echo "\nCaching and invalidation\n";

$post_id = wp_insert_post( [ 'post_title' => 'A card fixture page', 'post_status' => 'publish', 'post_type' => 'page' ] );

FW_SEO_Card::forget( $post_id );

$file = FW_SEO_Card::render( $post_id, 'A card fixture page' );
td( 'a card is written', is_string( $file ) && file_exists( $file ), true );

$again = FW_SEO_Card::render( $post_id, 'A card fixture page' );
td( 'asking again reuses the same file rather than redrawing', $again, $file );

$renamed = FW_SEO_Card::render( $post_id, 'A card fixture page, retitled' );
td( 'a different title produces a different file', $renamed !== $file, true );

td( 'forgetting removes every card for the post', FW_SEO_Card::forget( $post_id ) >= 2, true );
td( '...and the file is gone', file_exists( $file ), false );

echo "\nWhere it sits in the ladder\n";

FW_SEO_Chain::flush();
FW_SEO_Image::flush();

$ctx = FW_SEO_Context::for_post( $post_id );
$resolved = FW_SEO_Chain::resolve( 'og_image', $ctx );

td( 'a page with no image of its own gets a drawn card', false !== strpos( $resolved, '/social-cards/card-' ), true );

// A page that HAS a picture must never be overridden by a drawn one.
$with_image = wp_insert_post( [
	'post_title'   => 'A page that has its own picture',
	'post_content' => '<img src="' . home_url( '/wp-content/uploads/real-photo.jpg' ) . '" alt="" />',
	'post_status'  => 'publish',
	'post_type'    => 'page',
] );

FW_SEO_Chain::flush();
FW_SEO_Image::flush();

$resolved = FW_SEO_Chain::resolve( 'og_image', FW_SEO_Context::for_post( $with_image ) );

td( 'a page WITH a picture keeps it', $resolved, home_url( '/wp-content/uploads/real-photo.jpg' ) );
td( '...and is not given a card', false === strpos( $resolved, '/social-cards/' ), true );

// An explicit choice outranks everything.
FW_SEO_Store::set_post( $post_id, 'og_image', 'https://example.com/chosen.jpg' );
FW_SEO_Chain::flush();
FW_SEO_Image::flush();

td(
	'a chosen image still beats a drawn card',
	FW_SEO_Chain::resolve( 'og_image', FW_SEO_Context::for_post( $post_id ) ),
	'https://example.com/chosen.jpg'
);

FW_SEO_Store::set_post( $post_id, 'og_image', '' );

// Switched off, the ladder falls through to the site default instead.
add_filter( 'fw_seo_card_font', '__return_empty_string', 99 );
$off = FW_SEO_Card::render( $post_id, 'Should not be drawn without a font' );
remove_filter( 'fw_seo_card_font', '__return_empty_string', 99 );

td( 'with no font available nothing is drawn, and nothing breaks', $off, '' );

FW_SEO_Card::forget( $post_id );
wp_delete_post( $post_id, true );
wp_delete_post( $with_image, true );

printf( "\n%d passed, %d failed\n", TD::$pass, TD::$fail );

if ( TD::$fail ) {
	exit( 1 );
}
