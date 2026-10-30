<?php
/**
 * Contract tests for page speed diagnostics (FW_SEO_Speed).
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/speed-test.php
 *
 * No network: these pin the parser against a fixture and the guard that keeps
 * the button from being a proxy for testing other people's sites.
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

require_once $base . '/includes/class-fw-seo-speed.php';

class FW_SEO_Speed_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_speed_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_Speed_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_Speed_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

function fw_seo_speed_opp( $title, $savings, $score = 0.3, $items = array() ) {
	return array(
		'title'   => $title,
		'score'   => $score,
		'details' => array( 'type' => 'opportunity', 'overallSavingsMs' => $savings, 'items' => $items ),
	);
}

$fixture = array(
	'lighthouseResult' => array(
		'finalUrl'   => 'https://example.test/',
		'categories' => array( 'performance' => array( 'score' => 0.47 ) ),
		'audits'     => array(
			'render-blocking-resources' => fw_seo_speed_opp( 'Eliminate render-blocking resources', 1240, 0.2, array(
				array( 'url' => 'https://example.test/a.css' ),
				array( 'url' => 'https://example.test/b.js' ),
				array( 'url' => 'https://example.test/c.js' ),
				array( 'url' => 'https://example.test/d.js' ),
			) ),
			'uses-responsive-images'    => fw_seo_speed_opp( 'Properly size images', 2810 ),
			'unused-css-rules'          => fw_seo_speed_opp( 'Reduce unused CSS', 300 ),
			'tiny-saving'               => fw_seo_speed_opp( 'Something trivial', 40 ),
			'already-passing'           => fw_seo_speed_opp( 'Already fine', 900, 0.95 ),
			'unused-javascript'         => fw_seo_speed_opp( 'Reduce unused JavaScript', 610 ),
			'modern-image-formats'      => fw_seo_speed_opp( 'Serve images in next-gen formats', 450 ),
			'offscreen-images'          => fw_seo_speed_opp( 'Defer offscreen images', 200 ),
			'not-an-opportunity'        => array( 'title' => 'A table', 'score' => 0, 'details' => array( 'type' => 'table' ) ),
			'largest-contentful-paint-element' => array(
				'title'   => 'Largest Contentful Paint element',
				'score'   => 0,
				'displayValue' => '4,200 ms',
				'details' => array( 'items' => array( array( 'node' => array( 'snippet' => '<img class="hero">' ) ) ) ),
			),
			'dom-size' => array( 'title' => 'Avoid an excessive DOM size', 'score' => 1, 'details' => array() ),
		),
	),
);

$r = FW_SEO_Speed::parse( $fixture );

fw_seo_speed_is( 'the performance score is a whole number out of 100', $r['score'], 47 );
fw_seo_speed_is( 'the tested URL is kept', $r['url'], 'https://example.test/' );
fw_seo_speed_is(
	'opportunities are the biggest savings first, capped at five',
	array_column( $r['opportunities'], 'title' ),
	array( 'Properly size images', 'Eliminate render-blocking resources', 'Reduce unused JavaScript', 'Serve images in next-gen formats', 'Reduce unused CSS' )
);
fw_seo_speed_is( 'savings under a tenth of a second are not a to-do', in_array( 'Something trivial', array_column( $r['opportunities'], 'title' ), true ), false );
fw_seo_speed_is( 'an audit already passing is left out however big its number', in_array( 'Already fine', array_column( $r['opportunities'], 'title' ), true ), false );
fw_seo_speed_is( 'a non-opportunity audit is not mistaken for one', in_array( 'A table', array_column( $r['opportunities'], 'title' ), true ), false );

$rb = $r['opportunities'][1];
fw_seo_speed_is( 'each fix names at most three files', $rb['items'], array( 'https://example.test/a.css', 'https://example.test/b.js', 'https://example.test/c.js' ) );
fw_seo_speed_is( 'savings are kept in whole milliseconds', $rb['savings'], 1240 );

fw_seo_speed_is( 'a failing diagnostic is listed', array_column( $r['diagnostics'], 'id' ), array( 'largest-contentful-paint-element' ) );
fw_seo_speed_is( 'an element without a URL is named by its snippet', $r['diagnostics'][0]['items'], array( '<img class="hero">' ) );
fw_seo_speed_is( 'the diagnostic keeps Google\'s display value', $r['diagnostics'][0]['display'], '4,200 ms' );

fw_seo_speed_is( 'an empty response parses rather than fataling', FW_SEO_Speed::parse( array() )['score'], null );
fw_seo_speed_is( 'and has nothing to fix', FW_SEO_Speed::parse( array() )['opportunities'], array() );

/* -------------------------------------------------------------------------- *
 * Format drift — never a false all-clear
 * -------------------------------------------------------------------------- */

$newer = FW_SEO_Speed::parse( array( 'lighthouseResult' => array(
	'categories' => array( 'performance' => array( 'score' => 0.38 ) ),
	'audits'     => array(
		'render-blocking-resources' => array( 'title' => 'Render blocking', 'score' => 0, 'metricSavings' => array( 'LCP' => 1500, 'FCP' => 900 ), 'details' => array( 'type' => 'opportunity' ) ),
		'unstated' => array( 'title' => 'No saving stated', 'score' => 0.1, 'details' => array( 'type' => 'opportunity' ) ),
	),
) ) );
fw_seo_speed_is( 'savings are read from metricSavings when overallSavingsMs is absent', $newer['opportunities'][0]['savings'], 1500 );
fw_seo_speed_is( 'a failing audit with no stated saving is kept, not silently dropped', in_array( 'No saving stated', array_column( $newer['opportunities'], 'title' ), true ), true );
fw_seo_speed_is( 'and its saving is unknown, not zero', end( $newer['opportunities'] )['savings'], null );
fw_seo_speed_is( 'savings() prefers overallSavingsMs', FW_SEO_Speed::savings( array( 'details' => array( 'overallSavingsMs' => 700 ), 'metricSavings' => array( 'LCP' => 50 ) ) ), 700.0 );
fw_seo_speed_is( 'savings() is null when nothing is stated', FW_SEO_Speed::savings( array() ), null );

/* -------------------------------------------------------------------------- *
 * Only this site's pages
 * -------------------------------------------------------------------------- */

$home = home_url( '/' );
fw_seo_speed_is( 'the home page is this site', FW_SEO_Speed::is_own_url( $home ), true );
fw_seo_speed_is( 'a page on this site is this site', FW_SEO_Speed::is_own_url( home_url( '/some-page/' ) ), true );
fw_seo_speed_is( 'another site is refused — not an open proxy', FW_SEO_Speed::is_own_url( 'https://someone-else.example/' ), false );
fw_seo_speed_is( 'a non-http scheme is refused', FW_SEO_Speed::is_own_url( 'javascript:alert(1)' ), false );
fw_seo_speed_is( 'garbage is refused', FW_SEO_Speed::is_own_url( 'not a url' ), false );
fw_seo_speed_is(
	'run() refuses another site before making any request',
	FW_SEO_Speed::run( 'https://someone-else.example/' )['reason'],
	'foreign'
);

fw_seo_speed_is( 'an element snippet survives display — it is nothing but a tag', FW_SEO_Speed::clip( '<img class="hero">' ), '<img class="hero">' );
fw_seo_speed_is( 'a long item is shortened, not dropped', mb_strlen( FW_SEO_Speed::clip( str_repeat( 'a', 300 ) ) ) <= 110, true );
fw_seo_speed_is( 'milliseconds render as seconds', FW_SEO_Speed::seconds( 2810 ), '2.8 s' );

fw_seo_speed_is( 'the report renders with nothing cached', false !== strpos( FW_SEO_Speed::report_html(), 'Run diagnostics' ), true );
fw_seo_speed_is( 'and contains no nested <form>, which the browser would drop', false === strpos( FW_SEO_Speed::report_html(), '<form' ), true );

printf( "\n%d passed, %d failed\n", FW_SEO_Speed_T::$pass, FW_SEO_Speed_T::$fail );

exit( FW_SEO_Speed_T::$fail ? 1 : 0 );
