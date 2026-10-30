<?php
/**
 * Contract tests for the link report (FW_SEO_Links).
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/links-test.php
 *
 * The live part creates three posts, indexes them and deletes them again. It
 * leaves the site's own index as it found it (rows for other posts untouched).
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

require_once $base . '/includes/class-fw-seo-links.php';

class FW_SEO_Links_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_links_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_Links_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_Links_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/* From HTML ------------------------------------------------------------------- */

$l = FW_SEO_Links::from_html( '<p>See <a href="/guide/" class="x">our <b>guide</b></a> and <a rel="sponsored noopener" href=\'https://shop.test/?aff=9\'>this</a>.</p><a name="top">no href</a>' );
fw_seo_links_is( 'two real links, the anchor without href ignored', count( $l ), 2 );
fw_seo_links_is( 'the text is read without tags', $l[0]['anchor'], 'our guide' );
fw_seo_links_is( 'rel is read', $l[1]['rel'], 'sponsored noopener' );
fw_seo_links_is( 'single-quoted href works', $l[1]['url'], 'https://shop.test/?aff=9' );

/* From the builder -------------------------------------------------------------- */

$tree = [
	[ 'type' => 'section', 'atts' => [ 'background' => [ 'image' => [ 'src' => [ 'url' => 'https://x.test/wp-content/uploads/hero.jpg' ] ] ] ], '_items' => [
		[ 'type' => 'simple', 'shortcode' => 'button', 'atts' => [ 'label' => 'Book now', 'link' => '/book/', 'nofollow' => 'yes' ] ],
		[ 'type' => 'simple', 'shortcode' => 'button', 'atts' => [ 'label' => 'Soon', 'link' => '#' ] ],
		[ 'type' => 'simple', 'shortcode' => 'text_block', 'atts' => [ 'text' => '<p>Read <a href="https://x.test/about/">about us</a></p>' ] ],
		[ 'type' => 'simple', 'shortcode' => 'icon_box', 'atts' => [ 'title' => 'Plans', 'button_link' => 'https://x.test/plans/', 'image_url' => 'https://x.test/plans.png' ] ],
		[ 'type' => 'simple', 'shortcode' => 'author', 'atts' => [ 'link_to_author' => 'yes' ] ],
	] ],
];
$b    = FW_SEO_Links::from_builder( $tree );
$urls = array_column( $b, 'url' );
fw_seo_links_is( 'a button\'s link is found', in_array( '/book/', $urls, true ), true );
fw_seo_links_is( 'with its label as the text', $b[ array_search( '/book/', $urls, true ) ]['anchor'], 'Book now' );
fw_seo_links_is( 'and its nofollow switch as rel', $b[ array_search( '/book/', $urls, true ) ]['rel'], 'nofollow' );
fw_seo_links_is( 'a link inside rich text is found', in_array( 'https://x.test/about/', $urls, true ), true );
fw_seo_links_is( 'a *_link field is found', in_array( 'https://x.test/plans/', $urls, true ), true );
fw_seo_links_is( 'image sources, "#" and yes/no switches are not links', count( $b ), 3 );

/* Classifying -------------------------------------------------------------------- */

$home = 'https://x.test/';
fw_seo_links_is( 'a root-relative link is internal', FW_SEO_Links::classify( [ 'url' => '/guide/' ], $home )['kind'], 'internal' );
fw_seo_links_is( 'and made absolute', FW_SEO_Links::classify( [ 'url' => '/guide/' ], $home )['url'], 'https://x.test/guide/' );
fw_seo_links_is( 'www. is the same site', FW_SEO_Links::classify( [ 'url' => 'https://www.x.test/a/' ], $home )['kind'], 'internal' );
fw_seo_links_is( 'another site is external', FW_SEO_Links::classify( [ 'url' => 'https://news.test/story' ], $home )['kind'], 'external' );
fw_seo_links_is( 'a /go/ link on your own site is affiliate', FW_SEO_Links::classify( [ 'url' => '/go/blender' ], $home )['kind'], 'affiliate' );
fw_seo_links_is( 'an affiliate parameter makes it affiliate', FW_SEO_Links::classify( [ 'url' => 'https://shop.test/p?affid=12' ], $home )['kind'], 'affiliate' );
fw_seo_links_is( '"ref" alone is too common to count', FW_SEO_Links::classify( [ 'url' => 'https://shop.test/p?ref=nav' ], $home )['kind'], 'external' );
fw_seo_links_is( 'your own folder pattern counts', FW_SEO_Links::classify( [ 'url' => '/deals/x' ], $home, [ '/deals/' ] )['kind'], 'affiliate' );
fw_seo_links_is( 'your own site pattern counts, subdomains included', FW_SEO_Links::classify( [ 'url' => 'https://go.partner.test/x' ], $home, [ 'partner.test' ] )['kind'], 'affiliate' );
fw_seo_links_is( 'a fragment is not a link to index', FW_SEO_Links::classify( [ 'url' => '#top' ], $home ), null );
fw_seo_links_is( 'a relative path without a slash is skipped', FW_SEO_Links::classify( [ 'url' => 'page.html' ], $home ), null );

/* Live: real posts, indexed and asked about --------------------------------------- */

global $wpdb;
FW_SEO_Links::install();
remove_all_actions( 'save_post' ); // keep other features out of the fixture

$a = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Links fixture A' ] );
$b = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Links fixture B' ] );
$c = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Links fixture C' ] );
wp_update_post( [ 'ID' => $a, 'post_content' => '<a href="' . get_permalink( $b ) . '">B</a> <a href="' . get_permalink( $a ) . '">myself</a> <a href="https://shop.test/x?aff_id=3">buy</a> <a rel="sponsored" href="https://shop.test/y?aff_id=3">buy 2</a>' ] );

FW_SEO_Links::index_post( $a );
FW_SEO_Links::index_post( $b );
FW_SEO_Links::index_post( $c );

$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . FW_SEO_Links::table() . ' WHERE source_id = %d', $a ) );
fw_seo_links_is( 'A\'s three outgoing links are stored (not the link to itself)', count( $rows ), 3 );
fw_seo_links_is( 'the internal one points at B\'s post', (int) $wpdb->get_var( $wpdb->prepare( 'SELECT target_id FROM ' . FW_SEO_Links::table() . " WHERE source_id = %d AND kind = 'internal'", $a ) ), $b );

$orphan_ids = array_column( FW_SEO_Links::orphans( 5000 ), 'id' );
fw_seo_links_is( 'B is linked to, so not an orphan', in_array( $b, $orphan_ids, true ), false );
fw_seo_links_is( 'C is an orphan', in_array( $c, $orphan_ids, true ), true );
fw_seo_links_is( 'A links only to itself and others, so it is an orphan too', in_array( $a, $orphan_ids, true ), true );
fw_seo_links_is( 'B counts one page linking in', FW_SEO_Links::inbound( $b ), 1 );

$unmarked = array_column( FW_SEO_Links::unmarked_affiliates( 500 ), 'url' );
fw_seo_links_is( 'the affiliate link without sponsored is flagged', in_array( 'https://shop.test/x?aff_id=3', $unmarked, true ), true );
fw_seo_links_is( 'the one with sponsored is not', in_array( 'https://shop.test/y?aff_id=3', $unmarked, true ), false );
fw_seo_links_is( 'the shop counts as a linked site', in_array( 'shop.test', array_column( FW_SEO_Links::domains( 500 ), 'host' ), true ), true );

wp_update_post( [ 'ID' => $a, 'post_status' => 'draft' ] );
FW_SEO_Links::index_post( $a );
fw_seo_links_is( 'unpublishing A drops its links, so B becomes an orphan', in_array( $b, array_column( FW_SEO_Links::orphans( 5000 ), 'id' ), true ), true );

foreach ( [ $a, $b, $c ] as $id ) {
	wp_delete_post( $id, true );
	FW_SEO_Links::_forget( $id );
}
fw_seo_links_is( 'deleting forgets their rows', (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . FW_SEO_Links::table() . ' WHERE source_id IN (%d,%d,%d)', $a, $b, $c ) ), 0 );

fw_seo_links_is( 'the report renders', '' !== FW_SEO_Links::report_html(), true );

printf( "\n%d passed, %d failed\n", FW_SEO_Links_T::$pass, FW_SEO_Links_T::$fail );

exit( FW_SEO_Links_T::$fail ? 1 : 0 );
