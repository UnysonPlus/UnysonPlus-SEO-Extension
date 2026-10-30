<?php
/**
 * Contract tests for attachment page handling (FW_SEO_Attachments).
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/attachments-test.php
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

require_once $base . '/includes/class-fw-seo-attachments.php';

class FW_SEO_Attachments_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_att_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_Attachments_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_Attachments_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

fw_seo_att_is( 'leaving it to WordPress changes nothing', FW_SEO_Attachments::core_value( 'core' ), null );
fw_seo_att_is( 'keeping pages turns core\'s pages on', FW_SEO_Attachments::core_value( 'keep' ), '1' );
fw_seo_att_is( 'sending to the file turns core\'s redirect on', FW_SEO_Attachments::core_value( 'file' ), '0' );
fw_seo_att_is( 'sending to the parent also turns it on, as the fallback for unattached files', FW_SEO_Attachments::core_value( 'parent' ), '0' );
fw_seo_att_is( 'an unknown mode changes nothing', FW_SEO_Attachments::core_value( 'whatever' ), null );

$post = wp_insert_post( array( 'post_title' => 'Attachment parent fixture', 'post_status' => 'publish', 'post_type' => 'post' ) );
$att  = wp_insert_attachment( array( 'post_title' => 'fixture.jpg', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'guid' => home_url( '/fixture.jpg' ) ), false, $post );
$loose = wp_insert_attachment( array( 'post_title' => 'loose.jpg', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'guid' => home_url( '/loose.jpg' ) ) );

fw_seo_att_is( 'an attached file goes to its post', FW_SEO_Attachments::parent_url( $att ), get_permalink( $post ) );
fw_seo_att_is( 'a loose file has no post to go to', FW_SEO_Attachments::parent_url( $loose ), '' );

wp_update_post( array( 'ID' => $post, 'post_status' => 'draft' ) );
fw_seo_att_is( 'a file on an unpublished post is not sent to it', FW_SEO_Attachments::parent_url( $att ), '' );

wp_delete_attachment( $att, true );
wp_delete_attachment( $loose, true );
wp_delete_post( $post, true );

fw_seo_att_is( 'mode() defaults to leaving it to WordPress', in_array( FW_SEO_Attachments::mode(), FW_SEO_Attachments::MODES, true ), true );
fw_seo_att_is( 'the setting can describe the site\'s current behaviour', '' !== FW_SEO_Attachments::core_state_text(), true );

printf( "\n%d passed, %d failed\n", FW_SEO_Attachments_T::$pass, FW_SEO_Attachments_T::$fail );

exit( FW_SEO_Attachments_T::$fail ? 1 : 0 );
