<?php
/**
 * Contract tests for the small additions (FW_SEO_Additions), IndexNow
 * (FW_SEO_IndexNow) and the opt-in uninstall cleanup (uninstall.php).
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/additions-test.php
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

require_once $base . '/includes/class-fw-seo-additions.php';
require_once $base . '/includes/class-fw-seo-indexnow.php';

class FW_SEO_Additions_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_add_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_Additions_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_Additions_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/* Additional verification -------------------------------------------------- */

$tags = FW_SEO_Additions::parse_verification( "<meta name=\"facebook-domain-verification\" content=\"abc123\" />\n<meta name='norton-safeweb-site-verification' content='x-y_z'>" );
fw_seo_add_is( 'two pasted tags give two', count( $tags ), 2 );
fw_seo_add_is( 'name and content are read', $tags[0], [ 'name' => 'facebook-domain-verification', 'content' => 'abc123' ] );
fw_seo_add_is( 'single quotes work too', $tags[1]['content'], 'x-y_z' );
fw_seo_add_is( 'a script is not a meta tag', FW_SEO_Additions::parse_verification( '<script>alert(1)</script>' ), [] );
fw_seo_add_is( 'a meta without content is dropped', FW_SEO_Additions::parse_verification( '<meta name="x">' ), [] );
fw_seo_add_is( 'a name with odd characters is dropped', FW_SEO_Additions::parse_verification( '<meta name="a b<" content="1">' ), [] );
fw_seo_add_is( 'property= or http-equiv= tags are not verification tags', FW_SEO_Additions::parse_verification( '<meta http-equiv="refresh" content="0;url=https://x.test">' ), [] );

/* Paginated titles ------------------------------------------------------- */

fw_seo_add_is( 'page one is left alone', FW_SEO_Additions::paged_title( 'Blog | Site', 1, 7, '|' ), 'Blog | Site' );
fw_seo_add_is( 'page two is numbered', FW_SEO_Additions::paged_title( 'Blog | Site', 2, 7, '|' ), 'Blog | Site | Page 2 of 7' );
fw_seo_add_is( 'without a total, just the page', FW_SEO_Additions::paged_title( 'Blog', 3, 0, '–' ), 'Blog – Page 3' );
fw_seo_add_is( 'a title already saying the page is not numbered twice', FW_SEO_Additions::paged_title( 'Blog | Page 2 of 7 | Site', 2, 7, '|' ), 'Blog | Page 2 of 7 | Site' );
fw_seo_add_is( 'nor one ending in the bare page number', FW_SEO_Additions::paged_title( 'Blog - 2', 2, 7, '|' ), 'Blog - 2' );
fw_seo_add_is( 'a number inside the title is not a page number', FW_SEO_Additions::paged_title( 'Top 2 recipes', 2, 5, '|' ), 'Top 2 recipes | Page 2 of 5' );

/* RSS attribution -------------------------------------------------------- */

fw_seo_add_is( 'empty adds nothing', FW_SEO_Additions::feed_line( '  ', 'https://x.test/p/', 'P', 'https://x.test/', 'X' ), '' );
fw_seo_add_is(
	'the links are filled in',
	FW_SEO_Additions::feed_line( 'The post %%post_link%% first appeared on %%site_link%%.', 'https://x.test/p/', 'Hello', 'https://x.test/', 'X Site' ),
	'<p>The post <a href="https://x.test/p/">Hello</a> first appeared on <a href="https://x.test/">X Site</a>.</p>'
);
fw_seo_add_is( 'a script in the template is stripped', false === strpos( FW_SEO_Additions::feed_line( 'Hi<script>x()</script>', '', '', '', '' ), '<script' ), true );
fw_seo_add_is( 'a title is escaped', false === strpos( FW_SEO_Additions::feed_line( '%%post_link%%', 'https://x.test/', '<b>T</b>', '', '' ), '<b>' ), true );

/* Editor access ---------------------------------------------------------- */

$editor = new WP_User( 0 );
$editor->roles = [ 'editor' ];
$editor->allcaps = [ 'edit_posts' => true ];
$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] );

fw_seo_add_is( 'nothing ticked means everyone', FW_SEO_Additions::can_edit( $editor, [] ), true );
fw_seo_add_is( 'a ticked role may edit', FW_SEO_Additions::can_edit( $editor, [ 'editor' => true, 'author' => false ] ), true );
fw_seo_add_is( 'an unticked role may not', FW_SEO_Additions::can_edit( $editor, [ 'author' => true ] ), false );
fw_seo_add_is( 'a plain list of roles works too', FW_SEO_Additions::can_edit( $editor, [ 'editor' ] ), true );
if ( $admin ) {
	fw_seo_add_is( 'administrators always may', FW_SEO_Additions::can_edit( $admin[0], [ 'author' => true ] ), true );
}

/* IndexNow --------------------------------------------------------------- */

$key = FW_SEO_IndexNow::key();
fw_seo_add_is( 'the key is 32 hex characters', (bool) preg_match( '#^[a-f0-9]{32}$#', $key ), true );
fw_seo_add_is( 'and stays the same', FW_SEO_IndexNow::key(), $key );
fw_seo_add_is( 'the key file sits at the site root', FW_SEO_IndexNow::key_url(), home_url( '/' . $key . '.txt' ) );

fw_seo_add_is( 'publishing is sent', FW_SEO_IndexNow::worth_sending( 'publish', 'draft' ), true );
fw_seo_add_is( 'an update to a published post is sent', FW_SEO_IndexNow::worth_sending( 'publish', 'publish' ), true );
fw_seo_add_is( 'a draft is not', FW_SEO_IndexNow::worth_sending( 'draft', 'draft' ), false );
fw_seo_add_is( 'a private post is not', FW_SEO_IndexNow::worth_sending( 'private', 'draft' ), false );

$due = FW_SEO_IndexNow::due( [ 'https://x.test/a/', 'https://x.test/a/', 'https://x.test/b/', 'https://other.test/c/' ], [ 'https://x.test/b/' => 1000 ], 1100, 'x.test' );
fw_seo_add_is( 'duplicates, recently sent and foreign addresses are dropped', $due, [ 'https://x.test/a/' ] );
fw_seo_add_is( 'after ten minutes it can be sent again', FW_SEO_IndexNow::due( [ 'https://x.test/b/' ], [ 'https://x.test/b/' => 1000 ], 1000 + FW_SEO_IndexNow::THROTTLE, 'x.test' ), [ 'https://x.test/b/' ] );

$payload = FW_SEO_IndexNow::payload( [ 'https://x.test/a/' ] );
fw_seo_add_is( 'the payload carries host, key, key location and the list', array_keys( $payload ), [ 'host', 'key', 'keyLocation', 'urlList' ] );
fw_seo_add_is( 'the status renders', false !== strpos( FW_SEO_IndexNow::status_html(), $key ), true );

/* Uninstall cleanup ------------------------------------------------------ */

$settings = (array) get_option( 'fw_ext_settings_options:seo', [] );

if ( ! empty( $settings['delete_on_uninstall'] ) ) {
	echo "NOTE  delete_on_uninstall is ON here — running the uninstall file would wipe this site; skipped\n";
} else {
	if ( ! defined( 'FW_EXTENSION_UNINSTALL' ) ) {
		define( 'FW_EXTENSION_UNINSTALL', true );
	}

	// With the switch off, running the file must change nothing.
	$before = get_option( 'fw_ext_settings_options:seo' );
	require $base . '/uninstall.php';
	fw_seo_add_is( 'with the switch off the uninstall file deletes nothing', get_option( 'fw_ext_settings_options:seo' ), $before );
	fw_seo_add_is( 'and reports that it did nothing', fw_seo_uninstall_site( true ), false );
}

printf( "\n%d passed, %d failed\n", FW_SEO_Additions_T::$pass, FW_SEO_Additions_T::$fail );

exit( FW_SEO_Additions_T::$fail ? 1 : 0 );
