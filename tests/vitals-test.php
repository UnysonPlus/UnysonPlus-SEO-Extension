<?php
/**
 * Contract tests for the Core Web Vitals report.
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/vitals-test.php
 *
 * NOTE: the live call to Google is NOT exercised here — it needs an API key.
 * What is pinned is everything around it: the shape CrUX returns is parsed
 * correctly, every failure path is distinguished, and "no data" is never
 * treated as an error or shown as a zero. That last one is the whole point:
 * most pages on most sites have too little traffic to be in CrUX at all.
 */

class TW {
	public static $pass = 0;
	public static $fail = 0;
}

function tw( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		TW::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	TW::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

echo "\nCore Web Vitals contracts\n\n";

echo "Parsing a CrUX response\n";

// The shape the API actually returns, trimmed to what is read.
$raw = [
	'largest_contentful_paint' => [
		'histogram'   => [ [ 'density' => 0.72 ], [ 'density' => 0.19 ], [ 'density' => 0.09 ] ],
		'percentiles' => [ 'p75' => 2180 ],
	],
	'cumulative_layout_shift'  => [
		'histogram'   => [ [ 'density' => 0.91 ], [ 'density' => 0.06 ], [ 'density' => 0.03 ] ],
		'percentiles' => [ 'p75' => 0.04 ],
	],
];

$parsed = FW_SEO_Vitals::parse( $raw );

tw( 'the metrics present are parsed', count( $parsed ), 2 );
tw( 'the 75th percentile is read, not the average', $parsed['largest_contentful_paint']['p75'], 2180.0 );
tw( 'the good share is read', $parsed['largest_contentful_paint']['good'], 0.72 );
tw( 'a metric absent from the response is simply absent', isset( $parsed['interaction_to_next_paint'] ), false );
tw( 'an empty response parses to nothing rather than erroring', FW_SEO_Vitals::parse( [] ), [] );

echo "\nVerdicts\n";

tw( 'LCP inside the threshold is good', FW_SEO_Vitals::verdict( 'largest_contentful_paint', 2180 ), 'good' );
tw( 'LCP just over it needs improvement', FW_SEO_Vitals::verdict( 'largest_contentful_paint', 3200 ), 'ni' );
tw( 'LCP far over it is poor', FW_SEO_Vitals::verdict( 'largest_contentful_paint', 12000 ), 'poor' );
tw( 'CLS uses its own, much smaller threshold', FW_SEO_Vitals::verdict( 'cumulative_layout_shift', 0.04 ), 'good' );
tw( '...and its own poor boundary', FW_SEO_Vitals::verdict( 'cumulative_layout_shift', 0.9 ), 'poor' );
tw( 'INP is judged on 200ms, not LCP\'s 2500', FW_SEO_Vitals::verdict( 'interaction_to_next_paint', 400 ), 'ni' );

echo "\nFormatting\n";

tw( 'sub-second timings stay in milliseconds', FW_SEO_Vitals::format( 'largest_contentful_paint', 820 ), '820ms' );
tw( 'longer ones become seconds', FW_SEO_Vitals::format( 'largest_contentful_paint', 2180 ), '2.18s' );
tw( 'CLS is a ratio, not a time', FW_SEO_Vitals::format( 'cumulative_layout_shift', 0.04 ), '0.04' );

echo "\nWhen there is nothing to show\n";

tw( 'no key is reported as no key, not as an error', FW_SEO_Vitals::fetch( home_url(), true )['reason'], 'nokey' );
tw( '...and returns no metrics rather than zeros', FW_SEO_Vitals::fetch( home_url(), true )['metrics'], [] );

// The report must render in every state without a key present.
$html = FW_SEO_Vitals::report_html();
tw( 'the report renders without a key', is_string( $html ) && '' !== $html, true );
tw( '...and asks for one rather than showing an error', false !== strpos( $html, 'API key' ), true );

// "Too little traffic" is the ordinary answer for most sites, and must read
// that way. A key has to be present to reach this branch at all — without one
// the report correctly asks for a key instead.
$before = (array) fw_get_db_ext_settings_option( 'seo' );
fw_set_db_ext_settings_option( 'seo', null, array_merge( $before, [ 'crux_api_key' => 'test-key-not-real' ] ) );
FW_SEO_Settings::flush();

set_transient( FW_SEO_Vitals::CACHE_KEY, [ 'ok' => false, 'reason' => 'nodata', 'metrics' => [], 'checked' => time() ], 60 );

$html = FW_SEO_Vitals::report_html();
tw( 'a site too small for CrUX is told so plainly', false !== strpos( $html, 'Not enough traffic' ), true );
tw( '...and is not shown a zero', false === strpos( $html, '0ms' ), true );
tw( '...and is told it is not a fault', false !== strpos( $html, 'not a problem with the site' ), true );

// A rejected key must be distinguishable from having no data.
set_transient( FW_SEO_Vitals::CACHE_KEY, [ 'ok' => false, 'reason' => 'badkey', 'metrics' => [], 'checked' => time() ], 60 );
$html = FW_SEO_Vitals::report_html();
tw( 'a rejected key says so, rather than "no data"', false !== strpos( $html, 'rejected the API key' ), true );

fw_set_db_ext_settings_option( 'seo', null, $before );
FW_SEO_Settings::flush();
delete_transient( FW_SEO_Vitals::CACHE_KEY );

printf( "\n%d passed, %d failed\n", TW::$pass, TW::$fail );

if ( TW::$fail ) {
	exit( 1 );
}
