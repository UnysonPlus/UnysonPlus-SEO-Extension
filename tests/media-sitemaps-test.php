<?php
/**
 * Contract tests for the video and news sitemaps (FW_SEO_Media_Sitemaps).
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/media-sitemaps-test.php
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

require_once $base . '/includes/class-fw-seo-media-sitemaps.php';

class FW_SEO_Media_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_media_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_Media_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_Media_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/* Recognising videos ------------------------------------------------------------ */

fw_seo_media_is( 'a YouTube watch link', FW_SEO_Media_Sitemaps::youtube_id( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10' ), 'dQw4w9WgXcQ' );
fw_seo_media_is( 'a short youtu.be link', FW_SEO_Media_Sitemaps::youtube_id( 'https://youtu.be/dQw4w9WgXcQ' ), 'dQw4w9WgXcQ' );
fw_seo_media_is( 'a privacy-enhanced embed', FW_SEO_Media_Sitemaps::youtube_id( 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ' ), 'dQw4w9WgXcQ' );
fw_seo_media_is( 'a Vimeo link', FW_SEO_Media_Sitemaps::vimeo_id( 'https://vimeo.com/76979871' ), '76979871' );
fw_seo_media_is( 'a video file', FW_SEO_Media_Sitemaps::is_file( 'https://x.test/wp-content/uploads/tour.mp4' ), true );
fw_seo_media_is( 'an image is not a video', FW_SEO_Media_Sitemaps::is_file( 'https://x.test/a.jpg' ), false );

/* Entries --------------------------------------------------------------------------- */

$yt = FW_SEO_Media_Sitemaps::video( 'https://youtu.be/dQw4w9WgXcQ', '', 'Tour', 'A short tour' );
fw_seo_media_is( 'YouTube gets its own thumbnail and player', [ $yt['thumbnail'], $yt['player'] ], [ 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', 'https://www.youtube.com/embed/dQw4w9WgXcQ' ] );
fw_seo_media_is( 'a file without poster or featured image is left out (no thumbnail)', FW_SEO_Media_Sitemaps::video( 'https://x.test/v.mp4', '', 'T', 'D' ), null );
$file = FW_SEO_Media_Sitemaps::video( 'https://x.test/v.mp4', '', 'T', '', 'https://x.test/featured.jpg' );
fw_seo_media_is( 'falls back to the featured image, and to the title as description', [ $file['thumbnail'], $file['content'], $file['description'] ], [ 'https://x.test/featured.jpg', 'https://x.test/v.mp4', 'T' ] );
fw_seo_media_is( 'not a video address, no entry', FW_SEO_Media_Sitemaps::video( 'https://x.test/page/', '', 'T', 'D', 'x.jpg' ), null );

/* Finding them -------------------------------------------------------------------------- */

$tree = [ [ 'type' => 'section', '_items' => [
	[ 'type' => 'simple', 'shortcode' => 'media_video', 'atts' => [ 'source_type' => [ 'source' => 'embed', 'embed' => [ 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'poster' => [ 'attachment_id' => 4, 'url' => 'https://x.test/poster.jpg' ] ] ] ] ],
	[ 'type' => 'simple', 'shortcode' => 'text_block', 'atts' => [ 'text' => 'no video' ] ],
] ] ];
$b = FW_SEO_Media_Sitemaps::from_builder( $tree );
fw_seo_media_is( 'a builder video element is found with its poster', [ count( $b ), $b[0]['poster'] ?? '' ], [ 1, 'https://x.test/poster.jpg' ] );
$h = FW_SEO_Media_Sitemaps::from_html( '<p>Watch https://vimeo.com/76979871 or</p><video poster="/p.jpg" src="https://x.test/v.webm"></video>' );
fw_seo_media_is( 'content links and video tags are found', array_column( $h, 'url' ), [ 'https://vimeo.com/76979871', 'https://x.test/v.webm' ] );

$post = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Media fixture: harbour tour', 'post_content' => '<p>Our tour: https://www.youtube.com/watch?v=dQw4w9WgXcQ and again https://youtu.be/dQw4w9WgXcQ</p>' ] );
$v    = FW_SEO_Media_Sitemaps::videos_for( get_post( $post ) );
fw_seo_media_is( 'the same video twice on a page is one entry', count( $v ), 1 );
fw_seo_media_is( 'titled after the page', $v[0]['title'], 'Media fixture: harbour tour' );
FW_SEO_Media_Sitemaps::_flush();
fw_seo_media_is( 'the page is in the video sitemap', in_array( $post, FW_SEO_Media_Sitemaps::video_post_ids(), true ), true );

/* News ------------------------------------------------------------------------------------ */

$old = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Media fixture: old news', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ), 'post_date' => gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ) ] );
$ids = wp_list_pluck( FW_SEO_Media_Sitemaps::news_posts(), 'ID' );
fw_seo_media_is( 'an article from today is news', in_array( $post, $ids, true ), true );
fw_seo_media_is( 'one from five days ago is not', in_array( $old, $ids, true ), false );

wp_delete_post( $post, true );
wp_delete_post( $old, true );
FW_SEO_Media_Sitemaps::_flush();

printf( "\n%d passed, %d failed\n", FW_SEO_Media_T::$pass, FW_SEO_Media_T::$fail );

exit( FW_SEO_Media_T::$fail ? 1 : 0 );
