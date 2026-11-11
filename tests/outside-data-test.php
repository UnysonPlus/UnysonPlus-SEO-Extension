<?php
/**
 * Contract tests for the broken link checker, backlinks, search volumes, the
 * rendered-page checks and the crawler import.
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/outside-data-test.php
 *
 * No request leaves the machine: Bing and Open PageRank are answered by a fake
 * transport (pre_http_request), so this proves the handling, not the live APIs.
 */

class FW_SEO_OD_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_od_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_OD_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_OD_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/* Judging an address ---------------------------------------------------------- */

$u = 'https://a.test/';
fw_seo_od_is( 'a 200 is fine', FW_SEO_Link_Check::classify( [ [ $u, 200 ] ] ), 'ok' );
fw_seo_od_is( 'a 404 is broken', FW_SEO_Link_Check::classify( [ [ $u, 404 ] ] ), 'broken' );
fw_seo_od_is( 'a 410 is broken', FW_SEO_Link_Check::classify( [ [ $u, 410 ] ] ), 'broken' );
fw_seo_od_is( 'one redirect then 200 is redirected', FW_SEO_Link_Check::classify( [ [ $u, 301 ], [ $u . 'b', 200 ] ] ), 'redirected' );
fw_seo_od_is( 'two redirects are a chain', FW_SEO_Link_Check::classify( [ [ $u, 301 ], [ $u . 'b', 302 ], [ $u . 'c', 200 ] ] ), 'chain' );
fw_seo_od_is( 'a redirect to a 404 is broken', FW_SEO_Link_Check::classify( [ [ $u, 301 ], [ $u . 'b', 404 ] ] ), 'broken' );
fw_seo_od_is( 'an address seen twice is a loop', FW_SEO_Link_Check::classify( [ [ $u, 301 ], [ $u . 'b', 301 ], [ $u, 301 ] ] ), 'loop' );
fw_seo_od_is( 'a robot wall is not broken', FW_SEO_Link_Check::classify( [ [ $u, 403 ] ] ), 'unknown' );
fw_seo_od_is( 'rate limiting is not broken', FW_SEO_Link_Check::classify( [ [ $u, 429 ] ] ), 'unknown' );
fw_seo_od_is( 'no answer is not broken', FW_SEO_Link_Check::classify( [ [ $u, 0 ] ] ), 'unknown' );
fw_seo_od_is( 'a first server error is not broken', FW_SEO_Link_Check::classify( [ [ $u, 503 ] ], 0 ), 'unknown' );
fw_seo_od_is( 'a second server error is', FW_SEO_Link_Check::classify( [ [ $u, 503 ] ], 1 ), 'broken' );
fw_seo_od_is( 'nothing to judge is unknown', FW_SEO_Link_Check::classify( [] ), 'unknown' );

/* Redirect rules leading to rules ---------------------------------------------- */

$rule = static function ( $s, $t, $c = 301, $rx = 0 ) {
	return [ 'source' => $s, 'target' => $t, 'code' => $c, 'is_regex' => $rx ];
};
$chains = FW_SEO_Link_Check::rule_chains( [ $rule( '/a', '/b' ), $rule( '/b', '/c' ), $rule( '/x', '/y' ) ], 'site.test' );
fw_seo_od_is( 'a rule leading to a rule is found once', count( $chains ), 1 );
fw_seo_od_is( 'its steps are listed', $chains[0]['steps'], [ '/a', '/b', '/c' ] );
$loops = FW_SEO_Link_Check::rule_chains( [ $rule( '/a', '/b' ), $rule( '/b', '/a' ) ], 'site.test' );
fw_seo_od_is( 'a circle is a loop', $loops[0]['loop'], true );
fw_seo_od_is( 'and is listed once', count( $loops ), 1 );
fw_seo_od_is( 'a rule to another site ends the chain', FW_SEO_Link_Check::rule_chains( [ $rule( '/a', 'https://other.test/b' ), $rule( '/b', '/c' ) ], 'site.test' ), [] );
fw_seo_od_is( 'the site\'s own full address still counts', count( FW_SEO_Link_Check::rule_chains( [ $rule( '/a', 'https://www.site.test/b' ), $rule( '/b', '/c' ) ], 'site.test' ) ), 1 );
fw_seo_od_is( 'a 410 has no target to follow', FW_SEO_Link_Check::rule_chains( [ $rule( '/a', '/b', 410 ), $rule( '/b', '/c' ) ], 'site.test' ), [] );
fw_seo_od_is( 'regex rules are left out', FW_SEO_Link_Check::rule_chains( [ $rule( '/a', '/b', 301, 1 ), $rule( '/b', '/c' ) ], 'site.test' ), [] );

/* Keywords and scores ----------------------------------------------------------- */

fw_seo_od_is( 'a keyword record is read', FW_SEO_Bing::parse_keyword( [ 'Query' => 'crab', 'Impressions' => 12, 'BroadImpressions' => 40 ] ), [ 'phrase' => 'crab', 'exact' => 12, 'broad' => 40 ] );
fw_seo_od_is( 'an empty answer is no record', FW_SEO_Bing::parse_keyword( null ), null );
$scores = FW_SEO_Bing::merge_scores( [ 'old.test' => 2.0 ], [ 'results' => [ [ 'domain' => 'Big.test', 'found' => true, 'open_page_rank' => 6.5 ], [ 'domain' => 'none.test', 'found' => false, 'open_page_rank' => null ] ] ] );
fw_seo_od_is( 'scores merge, and an unknown domain gets none', $scores, [ 'old.test' => 2.0, 'big.test' => 6.5 ] );
fw_seo_od_is( 'a subdomain uses its domain\'s score', FW_SEO_Bing::score_for( 'blog.big.test', $scores ), 6.5 );
fw_seo_od_is( 'no score is null, not zero', FW_SEO_Bing::score_for( 'nobody.test', $scores ), null );
fw_seo_od_is( 'text from other sites cannot run in a spreadsheet', FW_SEO_Bing::csv_safe( '=HYPERLINK("x")' ), "'=HYPERLINK(\"x\")" );
fw_seo_od_is( 'ordinary text is untouched', FW_SEO_Bing::csv_safe( 'Great crab' ), 'Great crab' );

/* A whole backlink run, against a fake Bing -------------------------------------- */

global $wpdb;
$settings_before = fw_get_db_ext_settings_option( 'seo' );
$fake            = [ 'links' => [ 'https://blog.big.test/post' => 'Good read', 'https://small.test/' => '=bad' ], 'calls' => [], 'fail' => false, 'total' => 1 ];
$own             = home_url( '/gone-page-xyz/' );
$live            = home_url( '/' );

$transport = static function ( $pre, $args, $url ) use ( &$fake, $own ) {
	if ( false !== strpos( $url, 'ssl.bing.com' ) ) {
		$fake['calls'][] = $url;
		if ( $fake['fail'] ) {
			return [ 'response' => [ 'code' => 400, 'message' => '' ], 'body' => wp_json_encode( [ 'Message' => 'NotAuthorized' ] ), 'headers' => [], 'cookies' => [] ];
		}
		$d = [];
		if ( false !== strpos( $url, 'GetLinkCounts' ) ) {
			$d = [ 'Links' => [ [ 'Count' => 2, 'Url' => $own ] ], 'TotalPages' => $fake['total'] ];
		} elseif ( false !== strpos( $url, 'GetUrlLinks' ) ) {
			$rows = [];
			foreach ( $fake['links'] as $source => $text ) {
				$rows[] = [ 'AnchorText' => $text, 'Url' => $source ];
			}
			$d = [ 'Details' => $rows, 'TotalPages' => 1 ];
		}

		return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => wp_json_encode( [ 'd' => $d ] ), 'headers' => [], 'cookies' => [] ];
	}
	if ( false !== strpos( $url, 'openpagerank' ) ) {
		$fake['opr'] = $args;

		return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => wp_json_encode( [ 'results' => [ [ 'domain' => 'big.test', 'found' => true, 'open_page_rank' => 7.0 ] ] ] ), 'headers' => [], 'cookies' => [] ];
	}
	if ( $url === $own ) {
		return [ 'response' => [ 'code' => 404, 'message' => 'Not Found' ], 'body' => '', 'headers' => [], 'cookies' => [] ];
	}

	return $pre;
};
add_filter( 'pre_http_request', $transport, 10, 3 );

$s                 = $settings_before;
$s['bing_api_key'] = 'test-key';
$s['opr_api_key']  = 'opr-key';
fw_set_db_ext_settings_option( 'seo', null, $s );
FW_SEO_Settings::flush();

$saved_state = get_option( FW_SEO_Bing::STATE );
delete_option( FW_SEO_Bing::STATE );
FW_SEO_Bing::install();
$wpdb->query( 'TRUNCATE ' . FW_SEO_Bing::table() );

FW_SEO_Bing::start();
$state = FW_SEO_Bing::run_batch();
fw_seo_od_is( 'a run finishes', $state['status'], 'ready' );
fw_seo_od_is( 'the key goes with the call', false !== strpos( $fake['calls'][0], 'apikey=test-key' ), true );
fw_seo_od_is( 'the site address is encoded', false !== strpos( $fake['calls'][0], 'siteUrl=' . rawurlencode( $live ) ), true );
$sites = FW_SEO_Bing::referring();
fw_seo_od_is( 'both referring sites are stored', count( $sites ), 2 );
fw_seo_od_is( 'a link to a missing page is found', get_option( FW_SEO_Bing::DEAD ), [ $own ] );
fw_seo_od_is( 'the first run is not "new"', FW_SEO_Bing::changes( 'new' ), [] );
fw_seo_od_is( 'the score key travels in the header', $fake['opr']['headers']['Authorization'], 'Bearer opr-key' );
fw_seo_od_is( 'scores are kept', FW_SEO_Bing::score_for( 'blog.big.test', (array) get_option( FW_SEO_Bing::AUTHORITY ) ), 7.0 );

// A week later: one link gone, one arrived.
$wpdb->query( 'UPDATE ' . FW_SEO_Bing::table() . ' SET first_seen = first_seen - 604800, last_seen = last_seen - 604800' );
$st               = get_option( FW_SEO_Bing::STATE );
$st['first_run'] -= 604800;
update_option( FW_SEO_Bing::STATE, $st, false );
$fake['links'] = [ 'https://blog.big.test/post' => 'Good read', 'https://fresh.test/a' => 'New one' ];
FW_SEO_Bing::start();
FW_SEO_Bing::run_batch();
fw_seo_od_is( 'the vanished link is lost', array_column( FW_SEO_Bing::changes( 'lost' ), 'host' ), [ 'small.test' ] );
fw_seo_od_is( 'the arrived link is new', array_column( FW_SEO_Bing::changes( 'new' ), 'host' ), [ 'fresh.test' ] );
fw_seo_od_is( 'a lost link leaves the referring sites', count( FW_SEO_Bing::referring() ), 2 );

// An empty answer proves nothing.
$before        = count( FW_SEO_Bing::changes( 'lost' ) );
$fake['links'] = [];
FW_SEO_Bing::start();
FW_SEO_Bing::run_batch();
fw_seo_od_is( 'an empty run marks nothing as lost', count( FW_SEO_Bing::changes( 'lost' ) ), $before );

// Nor does a partial read.
$fake['links'] = [ 'https://blog.big.test/post' => 'Good read' ];
$fake['total'] = FW_SEO_Bing::MAX_PAGES + 5;
FW_SEO_Bing::start();
$n = 0;
do {
	$state = FW_SEO_Bing::run_batch();
} while ( 'ready' !== $state['status'] && ++$n < 40 );
fw_seo_od_is( 'a capped run says so', $state['capped'], true );
fw_seo_od_is( 'and marks nothing as lost', count( FW_SEO_Bing::changes( 'lost' ) ), $before );
$fake['total'] = 1;

$fake['fail'] = true;
FW_SEO_Bing::start();
$state = FW_SEO_Bing::run_batch();
fw_seo_od_is( 'a refused call stops the run with the reason', [ $state['status'], $state['error'] ], [ 'error', 'NotAuthorized' ] );
fw_seo_od_is( 'the report shows the reason', false !== strpos( FW_SEO_Bing::report_html(), 'NotAuthorized' ), true );

// Credentials.
fw_seo_od_is( 'the Bing key stays out of exports', in_array( 'bing_api_key', FW_SEO_Transfer::SECRETS, true ), true );
fw_seo_od_is( 'so does the score key', in_array( 'opr_api_key', FW_SEO_Transfer::SECRETS, true ), true );

remove_filter( 'pre_http_request', $transport, 10 );
$wpdb->query( 'TRUNCATE ' . FW_SEO_Bing::table() );
delete_option( FW_SEO_Bing::DEAD );
delete_option( FW_SEO_Bing::AUTHORITY );
false === $saved_state ? delete_option( FW_SEO_Bing::STATE ) : update_option( FW_SEO_Bing::STATE, $saved_state, false );
wp_clear_scheduled_hook( FW_SEO_Bing::CRON_BATCH );
fw_set_db_ext_settings_option( 'seo', null, $settings_before );
FW_SEO_Settings::flush();

/* Reading a served page ----------------------------------------------------------- */

$html = '<html><head><title>Crab &amp; Co | Home</title><link rel="canonical" href="https://s.test/a/">
<link rel="alternate" hreflang="fr" href="https://s.test/fr/a/"><link rel="stylesheet" href="http://cdn.test/x.css"></head>
<body><!-- <img src="http://hidden.test/c.png"> --><img src="http://s.test/i.jpg" width="100"><img src="https://s.test/j.jpg" width="200" srcset="a 1x">
<a href="http://plain.test/">a link is not mixed content</a><script>var x = "<img src=\'http://inscript.test/z.png\'>";</script><script src="http://s.test/s.js"></script></body></html>';
$p    = FW_SEO_Render_Check::parse( $html );
fw_seo_od_is( 'the title is decoded', $p['title'], 'Crab & Co | Home' );
fw_seo_od_is( 'the canonical is read', $p['canonicals'], [ 'https://s.test/a/' ] );
fw_seo_od_is( 'language links are read', $p['hreflang'], [ 'fr' => 'https://s.test/fr/a/' ] );
fw_seo_od_is( 'http files found; comments, scripts\' text and plain links are not', $p['insecure'], [ 'http://cdn.test/x.css', 'http://s.test/i.jpg', 'http://s.test/s.js' ] );

$ok = FW_SEO_Render_Check::compare( $p, 'https://s.test/a/', 'Crab & Co | Home', 'https://s.test/a' );
fw_seo_od_is( 'only mixed content on an https page', array_keys( $ok ), [ 'mixed_content' ] );
fw_seo_od_is( 'an http page has no mixed content', FW_SEO_Render_Check::compare( $p, 'http://s.test/a/', 'Crab &amp; Co | Home', 'http://s.test/a/' ), [] );
fw_seo_od_is( 'an overridden title is caught', FW_SEO_Render_Check::compare( $p, 'http://s.test/a/', 'Other', '' ), [ 'served_title' => 'Crab & Co | Home' ] );
fw_seo_od_is( 'an overridden canonical is caught', array_keys( FW_SEO_Render_Check::compare( $p, 'http://s.test/a/', '', 'https://s.test/b/' ) ), [ 'served_canonical' ] );
$two = [ 'canonicals' => [ 'https://s.test/a/', 'https://s.test/a/' ] ] + $p;
fw_seo_od_is( 'two canonicals are caught', array_keys( FW_SEO_Render_Check::compare( $two, 'http://s.test/a/', '', 'https://s.test/a/' ) ), [ 'served_canonical' ] );

$img = [ 'src' => 'x', 'width' => 300, 'srcset' => false ];
fw_seo_od_is( 'a 1600 file in a 300 slot is too big', FW_SEO_Render_Check::too_big( $img, 1600, 1000 ), true );
fw_seo_od_is( 'a 500 file is not worth mentioning', FW_SEO_Render_Check::too_big( $img, 500, 1000 ), false );
fw_seo_od_is( 'a srcset leaves the choice to the browser', FW_SEO_Render_Check::too_big( [ 'srcset' => true ] + $img, 1600, 1000 ), false );
fw_seo_od_is( 'no declared width cannot be judged', FW_SEO_Render_Check::too_big( [ 'width' => 0 ] + $img, 1600, 1000 ), false );
fw_seo_od_is( 'a very heavy file is flagged anyway', FW_SEO_Render_Check::too_big( [ 'width' => 0 ] + $img, 1600, 2000000 ), true );

$pairs = [
	1 => [ 'url' => 'https://s.test/a/', 'hreflang' => [ 'en' => 'https://s.test/a/', 'fr' => 'https://s.test/fr/a/', 'de' => 'https://s.test/de/a/', 'es' => 'https://other.test/es/' ] ],
	2 => [ 'url' => 'https://s.test/fr/a/', 'hreflang' => [ 'en' => 'https://s.test/a' ] ],
	3 => [ 'url' => 'https://s.test/de/a/', 'hreflang' => [ 'fr' => 'https://s.test/fr/a/' ] ],
];
$un    = FW_SEO_Render_Check::unreturned( $pairs );
fw_seo_od_is( 'only the page not linking back is named; other sites cannot be judged', $un[1], [ 'https://s.test/de/a/' ] );

/* The crawler import --------------------------------------------------------------- */

$rows = FW_SEO_Crawl_Import::parse( "\xEF\xBB\xBF\"Address\",\"Status Code\",\"Title 1\",\"Meta Description 1\",\"H1-1\",\"Indexability\",\"Redirect URL\"\n\"https://s.test/a/\",\"200\",\"A\",\"D\",\"H\",\"Indexable\",\"\"\n\"https://s.test/b/\",\"301\",\"\",\"\",\"\",\"Non-Indexable\",\"https://s.test/c/\"\n\"not a url\",\"200\",\"\",\"\",\"\",\"\",\"\"\n", 'x.csv' );
fw_seo_od_is( 'a CSV is read, junk rows left out', count( $rows ), 2 );
fw_seo_od_is( 'columns are found by their names', [ $rows[0]['url'], $rows[0]['status'], $rows[0]['title'] ], [ 'https://s.test/a/', '200', 'A' ] );
$marked = FW_SEO_Crawl_Import::mark_columns( $rows );
fw_seo_od_is( 'a complete page has no findings', array_keys( FW_SEO_Crawl_Import::findings( $marked[0] ) ), [] );
$t = FW_SEO_Crawl_Import::findings( $marked[1] );
fw_seo_od_is( 'a redirect and non-indexable are found', [ $t['crawl_redirect'], isset( $t['crawl_noindex'] ) ], [ '301 → https://s.test/c/', true ] );

$tsv = FW_SEO_Crawl_Import::parse( "url\tstatus_code\ttitle\thttps\nhttps://s.test/a/\t404\tx\ty\n", 't.tsv' );
fw_seo_od_is( 'tab-separated files work', $tsv[0]['status'], '404' );
$semi = FW_SEO_Crawl_Import::parse( "URL;Status\nhttps://s.test/a/;500\n", 's.csv' );
fw_seo_od_is( 'semicolons work', $semi[0]['status'], '500' );
$json = FW_SEO_Crawl_Import::parse( wp_json_encode( [ 'results' => [ [ 'url' => 'https://s.test/a/', 'status_code' => 200, 'title' => 'A', 'h1' => '', 'issues' => [ 'Slow', 'Thin' ] ] ] ] ), 'r.json' );
fw_seo_od_is( 'a JSON export is read, lists joined', [ $json[0]['status'], $json[0]['notes'] ], [ '200', 'Slow; Thin' ] );
$plain = FW_SEO_Crawl_Import::parse( wp_json_encode( [ [ 'Address' => 'https://s.test/a/', 'indexable' => false ] ] ), 'p.json' );
fw_seo_od_is( 'a plain list works, a false reads as no', $plain[0]['indexable'], 'no' );
fw_seo_od_is( 'a file with no addresses is refused', is_wp_error( FW_SEO_Crawl_Import::parse( "name,age\nx,2\n", 'n.csv' ) ), true );
fw_seo_od_is( 'an empty file is refused', is_wp_error( FW_SEO_Crawl_Import::parse( '', 'e.csv' ) ), true );

// A crawler with no description column must not report every page as missing one.
$lean = FW_SEO_Crawl_Import::mark_columns( FW_SEO_Crawl_Import::parse( "URL,Status,Title\nhttps://s.test/a/,200,A\nhttps://s.test/b/,200,\n", 'l.csv' ) );
fw_seo_od_is( 'a column the crawler does not have is no finding', array_keys( FW_SEO_Crawl_Import::findings( $lean[0] ) ), [] );
fw_seo_od_is( 'an empty cell in a column it has is', array_keys( FW_SEO_Crawl_Import::findings( $lean[1] ) ), [ 'crawl_no_title' ] );

// Round trips: what is exported imports back unchanged.
$tricky = [ [ 'url' => 'https://s.test/a/', 'status' => '200', 'title' => 'Say "hi", then\\go', 'description' => "two\nlines", 'h1' => 'É', 'redirect' => '', 'indexable' => 'yes', 'notes' => 'a; b' ] ];
fw_seo_od_is( 'CSV round trip', FW_SEO_Crawl_Import::parse( FW_SEO_Crawl_Import::to_csv( $tricky ), 'c.csv' ), $tricky );
fw_seo_od_is( 'JSON round trip', FW_SEO_Crawl_Import::parse( wp_json_encode( [ 'format' => FW_SEO_Crawl_Import::FORMAT, 'version' => 1, 'rows' => $tricky ] ), 'c.json' ), $tricky );

// Matched to a real page, and shown in the audit.
$saved_import = get_option( FW_SEO_Crawl_Import::OPTION );
$post         = wp_insert_post( [ 'post_title' => 'Crawl target', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => 'Words.' ] );
$r            = FW_SEO_Crawl_Import::save( [ [ 'url' => get_permalink( $post ), 'status' => '404', 'title' => '', 'description' => '', 'h1' => '', 'redirect' => '', 'indexable' => '', 'notes' => '' ], [ 'url' => home_url( '/category/nothing-here/' ), 'status' => '200', 'title' => 'x', 'description' => '', 'h1' => '', 'redirect' => '', 'indexable' => '', 'notes' => '' ] ], 'f.csv' );
fw_seo_od_is( 'a page is matched, an archive is not', [ $r['pages'], $r['unmatched'] ], [ 1, 1 ] );
fw_seo_od_is( 'the finding joins the audit', in_array( $post, FW_SEO_Audit::by_issue()['crawl_error'] ?? [], true ), true );

update_post_meta( $post, FW_SEO_Render_Check::META, [ 'issues' => [ 'mixed_content' => 'http://x.test/a.png' ], 'time' => time() ] );
fw_seo_od_is( 'a rendered finding joins the audit', in_array( $post, FW_SEO_Audit::by_issue()['mixed_content'] ?? [], true ), true );
fw_seo_od_is( 'every finding has a label', array_values( array_diff( array_keys( FW_SEO_Audit::by_issue() ), array_keys( FW_SEO_Audit::issue_labels() ) ) ), [] );

wp_delete_post( $post, true );
false === $saved_import ? delete_option( FW_SEO_Crawl_Import::OPTION ) : update_option( FW_SEO_Crawl_Import::OPTION, $saved_import, false );

/* Against the real site: the checker and the fetcher -------------------------------- */

$page = wp_insert_post( [ 'post_title' => 'Link check target', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => 'Words on the page.' ] );
$good = get_permalink( $page );

$hops = FW_SEO_Link_Check::walk( $good );
fw_seo_od_is( 'a real page answers 200', FW_SEO_Link_Check::classify( $hops ), 'ok' );
$hops = FW_SEO_Link_Check::walk( home_url( '/no-such-page-' . wp_generate_password( 8, false ) . '/' ) );
fw_seo_od_is( 'a missing page is broken', FW_SEO_Link_Check::classify( $hops ), 'broken' );
$hops = FW_SEO_Link_Check::walk( untrailingslashit( $good ) );
fw_seo_od_is( 'WordPress adding a slash is a redirect', FW_SEO_Link_Check::classify( $hops ), 'redirected' );
fw_seo_od_is( 'and it ends at the page', end( $hops )[0], $good );

$issues = FW_SEO_Render_Check::check_post( $page );
fw_seo_od_is( 'a real page, fetched: title and canonical agree', array_values( array_intersect( array_keys( (array) $issues ), [ 'served_title', 'served_canonical' ] ) ), [] );
fw_seo_od_is( 'and the result is stored', is_array( get_post_meta( $page, FW_SEO_Render_Check::META, true ) ), true );

wp_delete_post( $page, true );

printf( "\n%d passed, %d failed\n", FW_SEO_OD_T::$pass, FW_SEO_OD_T::$fail );
