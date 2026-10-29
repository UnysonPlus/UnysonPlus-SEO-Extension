<?php
/**
 * Contract tests for index coverage (FW_SEO_Inspect).
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/inspect-test.php
 *
 * Exit 0 = all pass, 1 = something regressed.
 *
 * The live API cannot be exercised here — it needs a connected property — so
 * these pin the parts that are ours and that fail silently: the shape we keep
 * from Google's response, the state each shape means, and the daily budget,
 * which is the thing standing between a background sweep and a rate limit.
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

require_once $base . '/includes/class-fw-seo-inspect.php';

class FW_SEO_Inspect_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_inspect_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_Inspect_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_Inspect_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/** A response shaped like Google's, with the index block overridden. */
function fw_seo_inspect_response( array $index = array(), array $rich = array() ) {
	return array(
		'inspectionResult' => array(
			'inspectionResultLink' => 'https://search.google.com/search-console/inspect?x=1',
			'indexStatusResult'    => array_merge( array(
				'verdict'        => 'PASS',
				'coverageState'  => 'Submitted and indexed',
				'robotsTxtState' => 'ALLOWED',
				'indexingState'  => 'INDEXING_ALLOWED',
				'pageFetchState' => 'SUCCESSFUL',
				'lastCrawlTime'  => '2026-10-20T04:11:02Z',
				'crawledAs'      => 'MOBILE',
				'userCanonical'  => 'https://example.test/a/',
				'googleCanonical'=> 'https://example.test/a/',
			), $index ),
			'richResultsResult'    => $rich,
		),
	);
}

/* -------------------------------------------------------------------------- *
 * parse() — the shape we keep
 * -------------------------------------------------------------------------- */

$row = FW_SEO_Inspect::parse( fw_seo_inspect_response() );

fw_seo_inspect_is( 'parse keeps the verdict', $row['verdict'], 'PASS' );
fw_seo_inspect_is( 'parse keeps the coverage string, which is the actionable part', $row['coverage'], 'Submitted and indexed' );
fw_seo_inspect_is( 'parse keeps the crawl time verbatim for the caller to format', $row['last_crawl'], '2026-10-20T04:11:02Z' );
fw_seo_inspect_is( 'parse keeps the inspection link', $row['link'], 'https://search.google.com/search-console/inspect?x=1' );
fw_seo_inspect_is( 'parse stamps when it was taken', is_int( $row['checked'] ) && $row['checked'] > 0, true );

fw_seo_inspect_is(
	'matching canonicals are not a mismatch',
	$row['canonical_mismatch'],
	false
);

$row = FW_SEO_Inspect::parse( fw_seo_inspect_response( array(
	'googleCanonical' => 'https://example.test/b/',
) ) );
fw_seo_inspect_is(
	'a different Google canonical IS a mismatch — the row is reporting on another page',
	$row['canonical_mismatch'],
	true
);

$row = FW_SEO_Inspect::parse( fw_seo_inspect_response( array(
	'userCanonical'   => '',
	'googleCanonical' => 'https://example.test/b/',
) ) );
fw_seo_inspect_is(
	'no declared canonical is not a mismatch — there is nothing to disagree with',
	$row['canonical_mismatch'],
	false
);

fw_seo_inspect_is(
	'an empty response parses rather than fataling',
	FW_SEO_Inspect::parse( array() )['verdict'],
	''
);

/* -------------------------------------------------------------------------- *
 * state() — what the row means
 * -------------------------------------------------------------------------- */

fw_seo_inspect_is(
	'PASS is indexed',
	FW_SEO_Inspect::state( FW_SEO_Inspect::parse( fw_seo_inspect_response() ) ),
	'indexed'
);

fw_seo_inspect_is(
	'a FAIL Google tried and did not keep is "missing" — the one worth acting on',
	FW_SEO_Inspect::state( FW_SEO_Inspect::parse( fw_seo_inspect_response( array(
		'verdict'       => 'FAIL',
		'coverageState' => 'Crawled - currently not indexed',
	) ) ) ),
	'missing'
);

fw_seo_inspect_is(
	'a noindex is excluded, not missing — it is doing what it was told',
	FW_SEO_Inspect::state( FW_SEO_Inspect::parse( fw_seo_inspect_response( array(
		'verdict'       => 'FAIL',
		'indexingState' => 'BLOCKED_BY_META_TAG',
	) ) ) ),
	'excluded'
);

fw_seo_inspect_is(
	'a robots.txt block is excluded too',
	FW_SEO_Inspect::state( FW_SEO_Inspect::parse( fw_seo_inspect_response( array(
		'verdict'        => 'FAIL',
		'robotsTxtState' => 'DISALLOWED',
	) ) ) ),
	'excluded'
);

fw_seo_inspect_is(
	'a canonical mismatch is excluded — the other page is the one in the index',
	FW_SEO_Inspect::state( FW_SEO_Inspect::parse( fw_seo_inspect_response( array(
		'verdict'         => 'FAIL',
		'googleCanonical' => 'https://example.test/b/',
	) ) ) ),
	'excluded'
);

fw_seo_inspect_is(
	'nothing from Google is "unknown", never "missing"',
	FW_SEO_Inspect::state( FW_SEO_Inspect::parse( array() ) ),
	'unknown'
);

/* -------------------------------------------------------------------------- *
 * is_stale()
 * -------------------------------------------------------------------------- */

fw_seo_inspect_is( 'a result with no stamp is stale', FW_SEO_Inspect::is_stale( array() ), true );
fw_seo_inspect_is( 'a result taken now is not', FW_SEO_Inspect::is_stale( array( 'checked' => time() ) ), false );
fw_seo_inspect_is(
	'a result older than the stale window is',
	FW_SEO_Inspect::is_stale( array( 'checked' => time() - ( ( FW_SEO_Inspect::STALE_DAYS + 1 ) * DAY_IN_SECONDS ) ) ),
	true
);

/* -------------------------------------------------------------------------- *
 * The daily budget — what keeps a sweep off a rate limit
 * -------------------------------------------------------------------------- */

$saved = get_option( FW_SEO_Inspect::BUDGET_OPTION, null );

delete_option( FW_SEO_Inspect::BUDGET_OPTION );
fw_seo_inspect_is(
	'with nothing spent, the whole cap is available',
	FW_SEO_Inspect::budget_left(),
	FW_SEO_Inspect::DAILY_CAP
);

update_option( FW_SEO_Inspect::BUDGET_OPTION, array( 'date' => gmdate( 'Y-m-d' ), 'used' => 100 ), false );
fw_seo_inspect_is(
	'spending today reduces what is left',
	FW_SEO_Inspect::budget_left(),
	FW_SEO_Inspect::DAILY_CAP - 100
);

update_option( FW_SEO_Inspect::BUDGET_OPTION, array( 'date' => gmdate( 'Y-m-d' ), 'used' => FW_SEO_Inspect::DAILY_CAP + 50 ), false );
fw_seo_inspect_is(
	'an overspend floors at zero rather than going negative',
	FW_SEO_Inspect::budget_left(),
	0
);

update_option( FW_SEO_Inspect::BUDGET_OPTION, array( 'date' => '2001-01-01', 'used' => FW_SEO_Inspect::DAILY_CAP ), false );
fw_seo_inspect_is(
	'yesterday\'s spend does not count against today',
	FW_SEO_Inspect::budget_left(),
	FW_SEO_Inspect::DAILY_CAP
);

fw_seo_inspect_is(
	'the cap stays below Google\'s 2,000 a day, leaving room for on-demand checks',
	FW_SEO_Inspect::DAILY_CAP < 2000,
	true
);

// An exhausted budget must refuse before making the call, not after.
update_option( FW_SEO_Inspect::BUDGET_OPTION, array( 'date' => gmdate( 'Y-m-d' ), 'used' => FW_SEO_Inspect::DAILY_CAP ), false );
$refused = FW_SEO_Inspect::inspect_url( 'https://example.test/a/', 'https://example.test/' );
fw_seo_inspect_is(
	'with no budget left, a check is refused without calling Google',
	array( $refused['ok'], $refused['reason'] ),
	array( false, 'nobudget' )
);

if ( null === $saved ) {
	delete_option( FW_SEO_Inspect::BUDGET_OPTION );
} else {
	update_option( FW_SEO_Inspect::BUDGET_OPTION, $saved, false );
}

/* -------------------------------------------------------------------------- *
 * Reading stored results
 * -------------------------------------------------------------------------- */

fw_seo_inspect_is( 'an uninspected post has no stored row', FW_SEO_Inspect::stored( 0 ), array() );

fw_seo_inspect_is(
	'every state has a human label',
	array_values( array_diff(
		array( 'indexed', 'excluded', 'missing', 'unknown' ),
		array_keys( FW_SEO_Inspect::states() )
	) ),
	array()
);

fw_seo_inspect_is(
	'the report renders without a connection rather than fataling',
	is_string( FW_SEO_Inspect::report_html() ) && '' !== FW_SEO_Inspect::report_html(),
	true
);

printf( "\n%d passed, %d failed\n", FW_SEO_Inspect_T::$pass, FW_SEO_Inspect_T::$fail );

exit( FW_SEO_Inspect_T::$fail ? 1 : 0 );
