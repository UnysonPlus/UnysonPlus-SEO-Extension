<?php
/**
 * Contract tests for importing from other SEO plugins.
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/import-test.php
 *
 * The fixtures WRITE each competitor's meta keys directly, so the mapping is
 * exercised without those plugins being installed. That tests the translation
 * and the write path; it cannot confirm the key NAMES are right, which only a
 * real site can.
 *
 * The assertions that matter most are about not doing harm: an import must not
 * overwrite what you have already written here, must not turn another plugin's
 * "use the default" into an explicit switch, and must not silently swallow a
 * tag it could not translate.
 */

if ( ! class_exists( 'FW_SEO_Import' ) ) {
	require_once WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo/includes/class-fw-seo-import.php';
}

class TI {
	public static $pass = 0;
	public static $fail = 0;
}

function ti( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		TI::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	TI::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

echo "\nImport contracts\n\n";

echo "Tag translation\n";

$unknown = [];

ti(
	'Rank Math single-percent tags become ours',
	FW_SEO_Import::translate( '%title% %sep% %sitename%', 'rankmath', $unknown ),
	'%%title%% %%sep%% %%sitename%%'
);

ti(
	'AIOSEO hash tags become ours',
	FW_SEO_Import::translate( '#post_title #separator_sa #site_title', 'aioseo', $unknown ),
	'%%title%% %%sep%% %%sitename%%'
);

ti(
	'SEOPress double-percent tags are renamed, not passed through',
	FW_SEO_Import::translate( '%%post_title%% %%sep%% %%sitetitle%%', 'seopress', $unknown ),
	'%%title%% %%sep%% %%sitename%%'
);

ti(
	'a Yoast tag that already matches ours is left alone',
	FW_SEO_Import::translate( '%%title%% %%sep%% %%sitename%%', 'yoast', $unknown ),
	'%%title%% %%sep%% %%sitename%%'
);

ti(
	'a Yoast tag with a different name IS renamed',
	FW_SEO_Import::translate( '%%name%% on %%category%%', 'yoast', $unknown ),
	'%%author_name%% on %%post_categories%%'
);

ti(
	'a longer tag is not eaten by a shorter one that prefixes it',
	FW_SEO_Import::translate( '%%category_description%%', 'yoast', $unknown ),
	'%%term_description%%'
);

$unknown = [];
$out     = FW_SEO_Import::translate( 'Buy %title% with %mystery_tag%', 'rankmath', $unknown );

ti( 'an untranslatable tag is LEFT IN the value, not stripped', false !== strpos( $out, '%mystery_tag%' ), true );
ti( '...and reported so it can be fixed', isset( $unknown['%mystery_tag%'] ), true );

$unknown = [];
FW_SEO_Import::translate( '%%title%% %%sep%%', 'yoast', $unknown );

ti( 'a fully translated value reports nothing unknown', $unknown, [] );

$unknown = [];
FW_SEO_Import::translate( 'Save 50% today', 'rankmath', $unknown );

ti( 'a bare percent sign in prose is not mistaken for a tag', $unknown, [] );

echo "\nReading a source\n";

$post_id = wp_insert_post( [
	'post_title'  => 'An imported post',
	'post_status' => 'publish',
	'post_type'   => 'post',
] );

update_post_meta( $post_id, 'rank_math_title', '%title% %sep% %sitename%' );
update_post_meta( $post_id, 'rank_math_description', 'Written for %name%' );
update_post_meta( $post_id, 'rank_math_canonical_url', 'https://example.com/canonical' );
update_post_meta( $post_id, 'rank_math_robots', [ 'noindex', 'nofollow' ] );
update_post_meta( $post_id, 'rank_math_facebook_title', 'Shared: %title%' );

$unknown = [];
$read    = FW_SEO_Import::read( 'rankmath', $post_id, $unknown );

ti( 'the title comes across translated', $read['title'] ?? '', '%%title%% %%sep%% %%sitename%%' );
ti( 'the description comes across translated', $read['description'] ?? '', 'Written for %%author_name%%' );
ti( 'the canonical is copied verbatim — it is a URL, not a template', $read['canonical'] ?? '', 'https://example.com/canonical' );
ti( 'the share title comes across', $read['og_title'] ?? '', 'Shared: %%title%%' );
ti( 'a serialised robots list becomes our switches', [ $read['noindex'] ?? null, $read['nofollow'] ?? null ], [ true, true ] );

echo "\nDetection\n";

ti( 'Rank Math is detected as having data', FW_SEO_Import::count_available( 'rankmath' ) > 0, true );
ti( 'a source with no data reports none', FW_SEO_Import::count_available( 'seopress' ), 0 );
ti( 'available() lists only what is really there', isset( FW_SEO_Import::available()['rankmath'] ), true );
ti( '...and omits what is not', isset( FW_SEO_Import::available()['seopress'] ), false );

echo "\nRunning an import\n";

$result = FW_SEO_Import::run_batch( 'rankmath', 0, false );

ti( 'the batch imported our post', $result['imported'] >= 1, true );
ti( 'the title landed in our store', FW_SEO_Store::get_post( $post_id, 'title' ), '%%title%% %%sep%% %%sitename%%' );
ti( 'the noindex switch landed', FW_SEO_Store::get_post( $post_id, 'noindex' ), true );
ti( 'unknown tags are reported from the batch, not just per value', is_array( $result['unknown'] ), true );

// The safety property: a second run must not trample edits made since.
FW_SEO_Store::set_post( $post_id, 'title', 'Edited here after importing' );

$again = FW_SEO_Import::run_batch( 'rankmath', 0, false );

ti(
	'a second import does NOT overwrite what you have written since',
	FW_SEO_Store::get_post( $post_id, 'title' ),
	'Edited here after importing'
);

$forced = FW_SEO_Import::run_batch( 'rankmath', 0, true );

ti(
	'...unless overwrite is asked for explicitly',
	FW_SEO_Store::get_post( $post_id, 'title' ),
	'%%title%% %%sep%% %%sitename%%'
);

echo "\nNot inventing overrides\n";

$plain_id = wp_insert_post( [
	'post_title'  => 'A post with defaults',
	'post_status' => 'publish',
	'post_type'   => 'post',
] );

// Yoast writes 2 for "index" — its DEFAULT, not a decision.
update_post_meta( $plain_id, '_yoast_wpseo_title', 'Just a title' );
update_post_meta( $plain_id, '_yoast_wpseo_meta-robots-noindex', '2' );

$unknown = [];
$read    = FW_SEO_Import::read( 'yoast', $plain_id, $unknown );

ti( 'the title is read', $read['title'] ?? '', 'Just a title' );
ti(
	'"use the default" does NOT become an explicit noindex switch',
	isset( $read['noindex'] ),
	false
);

update_post_meta( $plain_id, '_yoast_wpseo_meta-robots-noindex', '1' );
$read = FW_SEO_Import::read( 'yoast', $plain_id, $unknown );

ti( '...but an explicit noindex does', $read['noindex'] ?? null, true );

echo "\nEmpty values\n";

$empty_id = wp_insert_post( [ 'post_title' => 'Nothing set', 'post_status' => 'publish', 'post_type' => 'post' ] );
update_post_meta( $empty_id, '_yoast_wpseo_title', '' );

$unknown = [];
ti( 'an empty source value is not imported as an empty override', FW_SEO_Import::read( 'yoast', $empty_id, $unknown ), [] );

wp_delete_post( $post_id, true );
wp_delete_post( $plain_id, true );
wp_delete_post( $empty_id, true );

printf( "\n%d passed, %d failed\n", TI::$pass, TI::$fail );

if ( TI::$fail ) {
	exit( 1 );
}
