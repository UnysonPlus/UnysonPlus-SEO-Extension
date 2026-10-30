<?php
/**
 * Contract tests for archive defaults, feed cleanup, image alt text, the HTML
 * sitemap and resetting a settings screen.
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/tidy-test.php
 *
 * Creates a few posts and an image record, and removes them again.
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

foreach ( [ 'feeds', 'alt', 'html-sitemap' ] as $f ) {
	require_once $base . '/includes/class-fw-seo-' . $f . '.php';
}

class FW_SEO_Tidy_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_tidy_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_Tidy_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_Tidy_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/* Archive defaults ------------------------------------------------------------ */

fw_seo_tidy_is( 'date archives are kept out of search by default', FW_SEO_Locations::default_noindex( 'date' ), true );
fw_seo_tidy_is( 'ordinary content is not', FW_SEO_Locations::default_noindex( 'post:post' ), false );
global $wpdb;
$authors = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_author) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'" );
fw_seo_tidy_is( 'author archives follow the number of people writing (' . $authors . ' here)', FW_SEO_Locations::default_noindex( 'author' ), $authors < 2 );

/* Feeds ----------------------------------------------------------------------- */

fw_seo_tidy_is( 'a category feed belongs to the category', FW_SEO_Feeds::page_of( '/category/news/feed/' ), '/category/news/' );
fw_seo_tidy_is( 'an atom feed too', FW_SEO_Feeds::page_of( '/category/news/feed/atom/' ), '/category/news/' );
fw_seo_tidy_is( 'a post\'s comment feed belongs to the post', FW_SEO_Feeds::page_of( '/my-post/feed/' ), '/my-post/' );
fw_seo_tidy_is( 'a search feed keeps the search', FW_SEO_Feeds::page_of( '/?s=bread&feed=rss2' ), '/?s=bread' );
fw_seo_tidy_is( 'the site comment feed goes home', FW_SEO_Feeds::page_of( '/comments/feed/' ), '/' );
fw_seo_tidy_is( 'nothing switched off by default', FW_SEO_Feeds::off(), [] );

/* Alt text --------------------------------------------------------------------- */

$post = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Tidy fixture: harbour walk' ] );
$img  = wp_insert_attachment( [ 'post_title' => 'Boats at dawn', 'post_excerpt' => 'Fishing boats leaving at first light', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ], false, $post );
$raw  = wp_insert_attachment( [ 'post_title' => 'IMG_4021', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ], false, $post );

fw_seo_tidy_is( 'the title as fallback', FW_SEO_Alt::fallback( $img, 'title' ), 'Boats at dawn' );
fw_seo_tidy_is( 'the caption as fallback', FW_SEO_Alt::fallback( $img, 'caption' ), 'Fishing boats leaving at first light' );
fw_seo_tidy_is( 'the page it is on as fallback', FW_SEO_Alt::fallback( $img, 'post', $post ), 'Tidy fixture: harbour walk' );
fw_seo_tidy_is( 'a camera file name is not a description', FW_SEO_Alt::fallback( $raw, 'title' ), '' );
fw_seo_tidy_is( 'switched off by default', FW_SEO_Alt::source(), 'none' );
fw_seo_tidy_is( 'an alt written into the content, even empty, is left alone', FW_SEO_Alt::_filter_content_img( '<img alt="" src="x.jpg">', 'the_content', $img ), '<img alt="" src="x.jpg">' );
fw_seo_tidy_is( 'images missing alt text are counted', FW_SEO_Alt::missing_count() >= 2, true );

/* HTML sitemap ------------------------------------------------------------------- */

$parent = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Tidy fixture parent' ] );
$child  = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Tidy fixture child', 'post_parent' => $parent ] );
$hidden = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Tidy fixture hidden' ] );
update_post_meta( $hidden, '_fw_seo_noindex', 1 );
if ( class_exists( 'FW_SEO_Store' ) ) {
	FW_SEO_Store::set_post( $hidden, 'noindex', true );
}

$html = FW_SEO_HTML_Sitemap::render( [ 'types' => 'page,post' ] );
fw_seo_tidy_is( 'pages and posts are listed', false !== strpos( $html, 'Tidy fixture parent' ) && false !== strpos( $html, 'Tidy fixture: harbour walk' ), true );
fw_seo_tidy_is( 'a child page is nested under its parent', (bool) preg_match( '#Tidy fixture parent</a><ul><li><a[^>]*>Tidy fixture child#', $html ), true );
fw_seo_tidy_is( 'a page kept out of search is left out', false === strpos( $html, 'Tidy fixture hidden' ), true );
fw_seo_tidy_is( 'excluding works', false === strpos( FW_SEO_HTML_Sitemap::render( [ 'types' => 'page', 'exclude' => (string) $parent ] ), 'Tidy fixture parent</a>' ), true );
fw_seo_tidy_is( 'the shortcode is registered', shortcode_exists( 'seo_sitemap' ), true );

foreach ( [ $img, $raw ] as $id ) {
	wp_delete_attachment( $id, true );
}
foreach ( [ $post, $parent, $child, $hidden ] as $id ) {
	wp_delete_post( $id, true );
}

/* Reset ------------------------------------------------------------------------- */

$keys = FW_SEO_Settings_Page::keys_of( [
	'box' => [ 'type' => 'box', 'options' => [
		'g' => [ 'type' => 'group', 'options' => [
			'a_switch' => [ 'type' => 'switch', 'value' => true ],
			'a_report' => [ 'type' => 'html-full', 'html' => 'x' ],
			'a_text'   => [ 'type' => 'text', 'value' => '' ],
		] ],
	] ],
] );
fw_seo_tidy_is( 'reset clears a screen\'s settings, not its reports', $keys, [ 'a_switch', 'a_text' ] );

printf( "\n%d passed, %d failed\n", FW_SEO_Tidy_T::$pass, FW_SEO_Tidy_T::$fail );

exit( FW_SEO_Tidy_T::$fail ? 1 : 0 );
