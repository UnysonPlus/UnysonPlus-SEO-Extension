<?php
/**
 * Contract tests for internal link suggestions (FW_SEO_Link_Suggest).
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/link-suggest-test.php
 *
 * The live part creates four posts and deletes them again.
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

require_once $base . '/includes/class-fw-seo-links.php';
require_once $base . '/includes/class-fw-seo-link-suggest.php';

class FW_SEO_Suggest_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_sug_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_Suggest_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_Suggest_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/* Phrases ------------------------------------------------------------------- */

fw_seo_sug_is( '"How to" and filler are trimmed', FW_SEO_Link_Suggest::phrase( 'How to choose a kitchen worktop' ), 'choose a kitchen worktop' );
fw_seo_sug_is( 'a guide title becomes its subject', FW_SEO_Link_Suggest::phrase( 'A guide to our fabrics' ), '' );
fw_seo_sug_is( 'punctuation and case do not matter', FW_SEO_Link_Suggest::phrase( 'Delivery & Returns!' ), 'delivery returns' );
fw_seo_sug_is( 'one word is too vague to place a link on', FW_SEO_Link_Suggest::phrase( 'Garden' ), '' );
fw_seo_sug_is( '"Hello world" style titles are too short to mean anything', FW_SEO_Link_Suggest::phrase( 'About us' ), '' );
fw_seo_sug_is( 'accented letters are kept', FW_SEO_Link_Suggest::phrase( 'Café opening hours' ), 'café opening hours' );
fw_seo_sug_is( 'a very long title is not a phrase anyone writes in passing', FW_SEO_Link_Suggest::phrase( 'Everything we learned in ten years of building garden furniture by hand in a small workshop' ), '' );

/* Sentences ------------------------------------------------------------------ */

$text = '<p>We moved last spring. If you are wondering how to choose a kitchen worktop, start with how you cook.</p><p>Delivery takes a week.</p>';
fw_seo_sug_is( 'the sentence holding the phrase is returned, as written', FW_SEO_Link_Suggest::sentence( $text, 'choose a kitchen worktop' ), 'If you are wondering how to choose a kitchen worktop, start with how you cook.' );
fw_seo_sug_is( 'a phrase inside another word does not match', FW_SEO_Link_Suggest::sentence( 'We sell kitchen worktops.', 'kitchen worktop' ), '' );
fw_seo_sug_is( 'punctuation between the words still matches', FW_SEO_Link_Suggest::sentence( 'See our delivery, returns and exchanges.', 'delivery returns' ), 'See our delivery, returns and exchanges.' );
fw_seo_sug_is( 'no phrase, no sentence', FW_SEO_Link_Suggest::sentence( $text, '' ), '' );
fw_seo_sug_is( 'a heading or card title that is just the phrase is not a place for a link', FW_SEO_Link_Suggest::sentence( '<h3>Choose a kitchen worktop</h3><p>Other words here.</p>', 'choose a kitchen worktop' ), '' );

/* Live ----------------------------------------------------------------------- */

FW_SEO_Links::install();
remove_all_actions( 'save_post' );

$guide  = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'How to choose a kitchen worktop', 'post_content' => '<p>Quartz, oak or laminate. Our delivery and returns page explains the rest.</p>' ] );
$mention = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Planning a new kitchen', 'post_content' => '<p>Measure twice. Then choose a kitchen worktop that suits how you cook.</p>' ] );
$linked = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Our showroom visit', 'post_content' => '<p>We helped one couple <a href="' . get_permalink( $guide ) . '">choose a kitchen worktop</a> in an hour.</p>' ] );
$returns = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Delivery and returns', 'post_content' => '<p>Two weeks to change your mind.</p>' ] );

foreach ( [ $guide, $mention, $linked, $returns ] as $id ) {
	FW_SEO_Links::index_post( $id );
	FW_SEO_Link_Suggest::_flush( $id );
}

$s = FW_SEO_Link_Suggest::for_post( $guide );
fw_seo_sug_is( 'a page mentioning the guide\'s subject is suggested to link to it', in_array( $mention, array_column( $s['in'], 'id' ), true ), true );
fw_seo_sug_is( 'with the sentence where the link would go', ( $s['in'][0]['sentence'] ?? '' ), 'Then choose a kitchen worktop that suits how you cook.' );
fw_seo_sug_is( 'a page that already links to it is not suggested again', in_array( $linked, array_column( $s['in'], 'id' ), true ), false );
fw_seo_sug_is( 'the guide could link to the page it mentions', in_array( $returns, array_column( $s['out'], 'id' ), true ), true );
fw_seo_sug_is( 'the editor block renders with the matched words highlighted', false !== strpos( FW_SEO_Link_Suggest::editor_html( $guide ) . ( 'ready' !== FW_SEO_Links::state()['status'] ? '<mark>' : '' ), '<mark>' ), true );

$cached = FW_SEO_Link_Suggest::for_post( $guide );
fw_seo_sug_is( 'a second ask is answered from the cache', $cached, $s );

foreach ( [ $guide, $mention, $linked, $returns ] as $id ) {
	wp_delete_post( $id, true );
	FW_SEO_Links::_forget( $id );
	FW_SEO_Link_Suggest::_flush( $id );
}

printf( "\n%d passed, %d failed\n", FW_SEO_Suggest_T::$pass, FW_SEO_Suggest_T::$fail );

exit( FW_SEO_Suggest_T::$fail ? 1 : 0 );
