<?php
/**
 * Contract tests for the robots.txt editor (FW_SEO_Robots).
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/robots-test.php
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

require_once $base . '/includes/class-fw-seo-robots.php';

class FW_SEO_Robots_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_rob_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_Robots_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_Robots_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

$core = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://x.test/sitemap.xml\n";

/* Cleaning ----------------------------------------------------------------- */

fw_seo_rob_is( 'a normal crawler name is kept', FW_SEO_Robots::clean_agent( ' Googlebot-Image ' ), 'Googlebot-Image' );
fw_seo_rob_is( '* is a crawler name', FW_SEO_Robots::clean_agent( '*' ), '*' );
fw_seo_rob_is( 'a name with a space is refused', FW_SEO_Robots::clean_agent( 'Bad Bot' ), null );
fw_seo_rob_is( 'a line break cannot smuggle in a second rule', FW_SEO_Robots::clean_agent( "x\nDisallow: /" ), null );
fw_seo_rob_is( 'a path must start with /', FW_SEO_Robots::clean_path( 'private/', 'disallow' ), null );
fw_seo_rob_is( 'or with *', FW_SEO_Robots::clean_path( '*.pdf$', 'disallow' ), '*.pdf$' );
fw_seo_rob_is( 'an empty Disallow is valid (blocks nothing)', FW_SEO_Robots::clean_path( '', 'disallow' ), '' );
fw_seo_rob_is( 'an empty Allow means nothing and is dropped', FW_SEO_Robots::clean_path( '', 'allow' ), null );
fw_seo_rob_is( 'a path with a line break is refused', FW_SEO_Robots::clean_path( "/a\r\nUser-agent: *", 'disallow' ), null );

/* Building ----------------------------------------------------------------- */

$b = FW_SEO_Robots::build( [] );
fw_seo_rob_is( 'nothing set builds nothing', $b['groups'], [] );
fw_seo_rob_is( 'and leaves WordPress\'s file untouched', FW_SEO_Robots::apply( $core, $b['groups'] ), $core );

$b = FW_SEO_Robots::build( [ 'robots_block_search' => true ], '/blog' );
fw_seo_rob_is( 'search blocking covers ?s= on any path, under the site\'s folder', $b['groups']['*'], [ 'Disallow: /blog/*?s=', 'Disallow: /blog/search/' ] );

$b = FW_SEO_Robots::build( [ 'robots_ai_training' => [ 'GPTBot' => true, 'CCBot' => false, 'Evilbot' => true ], 'robots_ai_search' => [ 'PerplexityBot' ] ] );
fw_seo_rob_is( 'a ticked AI crawler gets its own block-all group', $b['groups']['GPTBot'] ?? null, [ 'Disallow: /' ] );
fw_seo_rob_is( 'an unticked one does not', isset( $b['groups']['CCBot'] ), false );
fw_seo_rob_is( 'a name not on the list is ignored', isset( $b['groups']['Evilbot'] ), false );
fw_seo_rob_is( 'a plain list of names is accepted too', $b['groups']['PerplexityBot'] ?? null, [ 'Disallow: /' ] );

$b = FW_SEO_Robots::build( [ 'robots_rules' => [
	[ 'agent' => '*', 'directive' => 'disallow', 'path' => '/private/' ],
	[ 'agent' => '*', 'directive' => 'disallow', 'path' => '/private/' ],
	[ 'agent' => 'Googlebot', 'directive' => 'allow', 'path' => '/private/press/' ],
	[ 'agent' => '*', 'directive' => 'disallow', 'path' => 'no-slash' ],
	[ 'agent' => 'Bad Bot', 'directive' => 'disallow', 'path' => '/' ],
] ] );
fw_seo_rob_is( 'a custom rule lands in its crawler\'s group, once', $b['groups']['*'], [ 'Disallow: /private/' ] );
fw_seo_rob_is( 'Allow is written as Allow', $b['groups']['Googlebot'], [ 'Allow: /private/press/' ] );
fw_seo_rob_is( 'broken rows are left out, and counted', count( $b['skipped'] ), 2 );

fw_seo_rob_is( 'a crawler with narrower rules of its own is flagged (it now ignores the * group)', FW_SEO_Robots::partial_groups( $b['groups'] ), [ 'Googlebot' ] );
fw_seo_rob_is( 'a block-all crawler is not — it loses nothing', FW_SEO_Robots::partial_groups( [ '*' => [ 'Disallow: /a/' ], 'GPTBot' => [ 'Disallow: /' ] ] ), [] );

/* Merging into WordPress's output ------------------------------------------ */

$out = FW_SEO_Robots::apply( $core, [ '*' => [ 'Disallow: /private/', 'Disallow: /wp-admin/' ], 'GPTBot' => [ 'Disallow: /' ] ] );
$expected = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nDisallow: /private/\n\nSitemap: https://x.test/sitemap.xml\n\nUser-agent: GPTBot\nDisallow: /\n";
fw_seo_rob_is( 'rules for every crawler join WordPress\'s * group (no second * group, no duplicates); others get their own', $out, $expected );
fw_seo_rob_is( 'only one User-agent: * group', substr_count( $out, 'User-agent: *' ), 1 );

$out = FW_SEO_Robots::apply( "Sitemap: https://x.test/s.xml\n", [ '*' => [ 'Disallow: /a/' ] ] );
fw_seo_rob_is( 'with no * group, one is started', 0 === strpos( $out, "User-agent: *\nDisallow: /a/\n" ), true );

/* The real filter ----------------------------------------------------------- */

fw_seo_rob_is( 'a site that asks not to be crawled is left alone', FW_SEO_Robots::_filter( "User-agent: *\nDisallow: /\n", '0' ), "User-agent: *\nDisallow: /\n" );
fw_seo_rob_is( 'the preview renders', false !== strpos( FW_SEO_Robots::preview_html(), 'User-agent: *' ), true );

printf( "\n%d passed, %d failed\n", FW_SEO_Robots_T::$pass, FW_SEO_Robots_T::$fail );

exit( FW_SEO_Robots_T::$fail ? 1 : 0 );
