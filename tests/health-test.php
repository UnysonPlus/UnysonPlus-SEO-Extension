<?php
/**
 * Contract tests for the site health checks (FW_SEO_Health).
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/health-test.php
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

require_once $base . '/includes/class-fw-seo-health.php';

class FW_SEO_Health_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_health_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_Health_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_Health_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/* noindex ---------------------------------------------------------------- */

fw_seo_health_is( 'WordPress\'s own robots tag (single quotes) is read', FW_SEO_Health::says_noindex( "<meta name='robots' content='noindex, follow' />" ), true );
fw_seo_health_is( 'double quotes too', FW_SEO_Health::says_noindex( '<meta name="robots" content="max-image-preview:large, noindex">' ), true );
fw_seo_health_is( '"none" means noindex', FW_SEO_Health::says_noindex( '<meta name="robots" content="none">' ), true );
fw_seo_health_is( 'a googlebot-only tag counts', FW_SEO_Health::says_noindex( '<meta name="googlebot" content="noindex">' ), true );
fw_seo_health_is( 'index, follow is fine', FW_SEO_Health::says_noindex( "<meta name='robots' content='index, follow, max-image-preview:large' />" ), false );
fw_seo_health_is( 'the word in body text does not count', FW_SEO_Health::says_noindex( '<p>We never use noindex.</p>' ), false );
fw_seo_health_is( 'another meta mentioning it does not count', FW_SEO_Health::says_noindex( '<meta name="description" content="Why noindex matters">' ), false );
fw_seo_health_is( 'the X-Robots-Tag header counts', FW_SEO_Health::says_noindex( '', 'noindex, nofollow' ), true );

/* robots.txt ------------------------------------------------------------- */

fw_seo_health_is( 'WordPress\'s default lets crawlers in', FW_SEO_Health::blocks_everything( "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n" ), false );
fw_seo_health_is( 'Disallow: / for everyone blocks the site', FW_SEO_Health::blocks_everything( "User-agent: *\nDisallow: /\n" ), true );
fw_seo_health_is( 'an empty Disallow blocks nothing', FW_SEO_Health::blocks_everything( "User-agent: *\nDisallow:\n" ), false );
fw_seo_health_is( 'blocking only an AI crawler is a choice, not an outage', FW_SEO_Health::blocks_everything( "User-agent: *\nDisallow: /wp-admin/\n\nUser-agent: GPTBot\nDisallow: /\n" ), false );
fw_seo_health_is( 'a Googlebot group blocking everything counts, even with * open', FW_SEO_Health::blocks_everything( "User-agent: *\nDisallow:\n\nUser-agent: Googlebot\nDisallow: /\n" ), true );
fw_seo_health_is( 'and Googlebot open overrides * blocked', FW_SEO_Health::blocks_everything( "User-agent: *\nDisallow: /\n\nUser-agent: Googlebot\nAllow: /\n" ), false );
fw_seo_health_is( 'agents listed together share the rules', FW_SEO_Health::blocks_everything( "User-agent: Bingbot\nUser-agent: *\nDisallow: /\n" ), true );
fw_seo_health_is( 'comments are ignored', FW_SEO_Health::blocks_everything( "# Disallow: /\nUser-agent: *\nDisallow: /private/ # Disallow: /\n" ), false );

/* sitemap ---------------------------------------------------------------- */

fw_seo_health_is( 'a sitemap index with an entry', FW_SEO_Health::has_entries( '<sitemapindex><sitemap><loc>https://x.test/post-sitemap.xml</loc></sitemap></sitemapindex>' ), true );
fw_seo_health_is( 'a urlset with an entry', FW_SEO_Health::has_entries( "<urlset>\n<url>\n <loc>https://x.test/a/</loc></url></urlset>" ), true );
fw_seo_health_is( 'an empty index', FW_SEO_Health::has_entries( '<sitemapindex></sitemapindex>' ), false );
fw_seo_health_is( 'an HTML error page', FW_SEO_Health::has_entries( '<html><body>Not found</body></html>' ), false );

/* A real run on this install --------------------------------------------- */

$saved = get_option( FW_SEO_Health::OPTION, null );
$run   = FW_SEO_Health::run();
$ids   = wp_list_pluck( $run['results'], 'id' );

fw_seo_health_is( 'a run checks visibility, homepage, robots.txt and the sitemap', ! array_diff( [ 'visibility', 'home', 'robots' ], $ids ), true );
fw_seo_health_is( 'every result has a known status', ! array_diff( wp_list_pluck( $run['results'], 'status' ), [ 'critical', 'warning', 'good' ] ), true );
fw_seo_health_is( 'the run is stored', FW_SEO_Health::last()['time'], $run['time'] );
fw_seo_health_is( 'the report renders', false !== strpos( FW_SEO_Health::report_html(), 'fw-seo-health' ), true );
fw_seo_health_is( 'Site Health gets a result', in_array( FW_SEO_Health::_site_status_result()['status'], [ 'good', 'recommended', 'critical' ], true ), true );

foreach ( $run['results'] as $r ) {
	printf( "  ..   %-8s %s\n", $r['status'], $r['label'] );
}

if ( null === $saved ) {
	delete_option( FW_SEO_Health::OPTION );
} else {
	update_option( FW_SEO_Health::OPTION, $saved, false );
}

printf( "\n%d passed, %d failed\n", FW_SEO_Health_T::$pass, FW_SEO_Health_T::$fail );

exit( FW_SEO_Health_T::$fail ? 1 : 0 );
