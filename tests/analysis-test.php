<?php
/**
 * Contract tests for page analysis (FW_SEO_Analysis) and the site audit with
 * its guardrails (FW_SEO_Audit).
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/analysis-test.php
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

require_once $base . '/includes/class-fw-seo-analysis.php';
require_once $base . '/includes/class-fw-seo-audit.php';

class FW_SEO_An_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_an_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_An_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_An_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/* Matching ---------------------------------------------------------------------- */

fw_seo_an_is( 'the phrase is found whatever the case and punctuation', FW_SEO_Analysis::has_phrase( 'Our Crab-Linguine recipe, quick!', 'crab linguine recipe' ), true );
fw_seo_an_is( 'not inside a longer word', FW_SEO_Analysis::has_phrase( 'crabapple linguine', 'crab linguine' ), false );
fw_seo_an_is( 'all the words in another order is not the phrase', FW_SEO_Analysis::has_phrase( 'linguine with crab', 'crab linguine' ), false );
fw_seo_an_is( 'counted as whole phrases', FW_SEO_Analysis::count_phrase( 'Crab linguine. More crab linguine, crab linguines.', 'crab linguine' ), 2 );

/* Structure ---------------------------------------------------------------------- */

$heads = FW_SEO_Analysis::headings( '<h2>Intro</h2><p>x</p><h4>Deep</h4><h2>Next</h2>' );
fw_seo_an_is( 'headings are read in order', array_column( $heads, 'level' ), [ 2, 4, 2 ] );
fw_seo_an_is( 'a skipped level is reported', count( FW_SEO_Analysis::heading_problems( $heads ) ), 1 );
fw_seo_an_is( 'a clean outline is not', FW_SEO_Analysis::heading_problems( [ [ 'level' => 2, 'text' => 'a' ], [ 'level' => 3, 'text' => 'b' ], [ 'level' => 2, 'text' => 'c' ] ] ), [] );
fw_seo_an_is( 'an H1 in the content is reported', count( FW_SEO_Analysis::heading_problems( [ [ 'level' => 1, 'text' => 'Again' ] ] ) ), 1 );

/* Readability ---------------------------------------------------------------------- */

$long = 'Word ' . str_repeat( 'word ', 29 );
$r    = FW_SEO_Analysis::readability( '<p>This is short. This is also short. ' . $long . 'end.</p><p>Fine sentence here. Another one here.</p>' );
fw_seo_an_is( 'sentences counted', $r['sentences'], 5 );
fw_seo_an_is( 'long sentences counted', $r['long'], 1 );

/* The checks on a real page ---------------------------------------------------------- */

$text = '<p>This crab linguine recipe takes twenty minutes and feeds two.</p><h2>Why crab linguine works</h2><p>' . str_repeat( 'Sweet crab meets sharp lemon and warm chilli in every bite. ', 40 ) . '</p>';
$post = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Crab linguine recipe with chilli', 'post_name' => 'crab-linguine-recipe', 'post_content' => $text ] );
update_post_meta( $post, FW_SEO_Analysis::META, 'crab linguine recipe' );

$checks = array_column( FW_SEO_Analysis::keyphrase_checks( 'crab linguine recipe', FW_SEO_Analysis::material( $post ) ), 'ok', 'id' );
fw_seo_an_is( 'in the title, address and first paragraph', [ $checks['title'], $checks['slug'], $checks['intro'] ], [ true, true, true ] );
fw_seo_an_is( 'not in a subheading (it says "crab linguine works", not the whole phrase)', $checks['heading'], false );
fw_seo_an_is( 'no image, so no alt text', $checks['alt'], false );

$twin = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Another crab page', 'post_content' => $text ] );
update_post_meta( $twin, FW_SEO_Analysis::META, 'Crab Linguine Recipe' );
fw_seo_an_is( 'a second page aiming at the same phrase is named', FW_SEO_Analysis::competitors( $post, 'crab linguine recipe' ), [ $twin ] );

/* Similarity --------------------------------------------------------------------------- */

$base_text = str_repeat( 'Our plumbers in Harbourtown fix leaks, boilers and blocked drains the same day. ', 6 );
$a         = FW_SEO_Audit::signature( $base_text );
$b         = FW_SEO_Audit::signature( str_replace( 'Harbourtown', 'Millbrook', $base_text ) );
$c         = FW_SEO_Audit::signature( str_repeat( 'Fresh pastry is baked every morning with butter from the farm down the road. ', 6 ) );
fw_seo_an_is( 'a text too short to compare has no signature', FW_SEO_Audit::signature( 'Too short.' ), [] );
fw_seo_an_is( 'a page is identical to itself', FW_SEO_Audit::similarity( $a, $a ), 1.0 );
fw_seo_an_is( 'a different text is not similar', FW_SEO_Audit::similarity( $a, $c ) < 0.2, true );
$groups = FW_SEO_Audit::clusters( [ 1 => $a, 2 => $a, 3 => $c ] );
fw_seo_an_is( 'identical pages cluster, a different one stays out', array_column( $groups, 'ids' ), [ [ 1, 2 ] ] );

/* Audit of a page ------------------------------------------------------------------------- */

$issues = FW_SEO_Audit::check_post( $post );
fw_seo_an_is( 'the audit stores what it found for the page', is_array( get_post_meta( $post, FW_SEO_Audit::META, true ) ), true );
fw_seo_an_is( 'a page without a written description is listed for it', isset( $issues['auto_description'] ) || isset( $issues['no_description'] ), true );
fw_seo_an_is( 'its keyphrase is set, so it is not "no focus"', isset( $issues['no_focus'] ), false );

/* Guardrail ------------------------------------------------------------------------------- */

$thin = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Plumber in Smalltown', 'post_content' => '<p>We cover Smalltown.</p>' ] );
fw_seo_an_is( 'with no type guarded, nothing is held back', FW_SEO_Audit::held_back( $thin ), false );
$settings = (array) fw_get_db_ext_settings_option( 'seo' );
$s        = (array) $settings;
$s['thin_noindex_types'] = [ 'post' => true ];
fw_set_db_ext_settings_option( 'seo', null, $s );
FW_SEO_Settings::flush();
fw_seo_an_is( 'a thin page of a guarded type is held out of the index', FW_SEO_Audit::held_back( $thin ), true );
fw_seo_an_is( 'a substantial one is not', FW_SEO_Audit::held_back( $post ), false );
$ctx = FW_SEO_Context::for_post( get_post( $thin ) );
fw_seo_an_is( 'its robots tag says noindex', in_array( 'noindex', FW_SEO_Audit::_filter_robots( [ 'index', 'follow' ], $ctx ), true ), true );
fw_set_db_ext_settings_option( 'seo', null, $settings );
FW_SEO_Settings::flush();

foreach ( [ $post, $twin, $thin ] as $id ) {
	wp_delete_post( $id, true );
}

printf( "\n%d passed, %d failed\n", FW_SEO_An_T::$pass, FW_SEO_An_T::$fail );

exit( FW_SEO_An_T::$fail ? 1 : 0 );
