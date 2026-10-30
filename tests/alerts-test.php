<?php
/**
 * Contract tests for alerts (FW_SEO_Alerts).
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/alerts-test.php
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

require_once $base . '/includes/class-fw-seo-alerts.php';

class FW_SEO_Alerts_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_alert_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_Alerts_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_Alerts_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/* The judgements and their floors ----------------------------------------- */

fw_seo_alert_is( 'seen 4,000 times on page one, clicked 36: low click-through', FW_SEO_Alerts::is_low_ctr( 4000, 36, 6.2 ), true );
fw_seo_alert_is( 'below the impressions floor it says nothing', FW_SEO_Alerts::is_low_ctr( 600, 1, 4.0 ), false );
fw_seo_alert_is( 'on page three, few clicks are expected, not a title problem', FW_SEO_Alerts::is_low_ctr( 4000, 5, 24.0 ), false );
fw_seo_alert_is( 'a healthy rate is not flagged', FW_SEO_Alerts::is_low_ctr( 4000, 200, 5.0 ), false );

fw_seo_alert_is( 'halved from 1,200 to 500: dropped', FW_SEO_Alerts::has_dropped( 500, 1200 ), true );
fw_seo_alert_is( 'a 30% dip is not an alert', FW_SEO_Alerts::has_dropped( 840, 1200 ), false );
fw_seo_alert_is( '120 to 20 is below the floor: small sites are not alarmed', FW_SEO_Alerts::has_dropped( 20, 120 ), false );

fw_seo_alert_is( 'old and still bringing 45 visitors: worth a refresh', FW_SEO_Alerts::is_stale_with_traffic( 30, 45, 12 ), true );
fw_seo_alert_is( 'old and visited by nobody does not matter', FW_SEO_Alerts::is_stale_with_traffic( 30, 3, 12 ), false );
fw_seo_alert_is( 'recent pages are not stale', FW_SEO_Alerts::is_stale_with_traffic( 4, 400, 12 ), false );

fw_seo_alert_is( 'noindex on a page that was getting clicks', FW_SEO_Alerts::is_lost_to_noindex( true, 40 ), true );
fw_seo_alert_is( 'noindex on a page nobody visited is a decision', FW_SEO_Alerts::is_lost_to_noindex( true, 2 ), false );

/* Building the list -------------------------------------------------------- */

$pages = [
	'https://x.test/old-guide/' => [ 'post_id' => 11, 'title' => 'Old guide', 'edit' => 'e11', 'now' => [ 'impressions' => 900, 'clicks' => 60, 'position' => 7 ], 'before' => [ 'impressions' => 950 ], 'months' => 26, 'noindex' => false ],
	'https://x.test/pricing/'   => [ 'post_id' => 12, 'title' => 'Pricing', 'edit' => 'e12', 'now' => [ 'impressions' => 5000, 'clicks' => 20, 'position' => 4 ], 'before' => [ 'impressions' => 5100 ], 'months' => 2 ],
	'https://x.test/launch/'    => [ 'post_id' => 13, 'title' => 'Launch', 'edit' => 'e13', 'now' => [ 'impressions' => 200, 'clicks' => 5 ], 'before' => [ 'impressions' => 1400, 'clicks' => 30 ], 'months' => 1 ],
	'https://x.test/hidden/'    => [ 'post_id' => 14, 'title' => 'Hidden', 'edit' => 'e14', 'now' => [ 'impressions' => 10, 'clicks' => 0 ], 'before' => [ 'impressions' => 800, 'clicks' => 25 ], 'months' => 1, 'noindex' => true ],
	'https://x.test/fine/'      => [ 'post_id' => 15, 'title' => 'Fine', 'edit' => 'e15', 'now' => [ 'impressions' => 3000, 'clicks' => 240, 'position' => 3 ], 'before' => [ 'impressions' => 2900 ], 'months' => 3 ],
];
$near = [
	[ 'page' => 'https://x.test/fine/', 'query' => 'best widgets', 'position' => 9.4, 'impressions' => 300 ],
	[ 'page' => 'https://x.test/fine/', 'query' => 'widgets cheap', 'position' => 11.0, 'impressions' => 90 ],
];
$searches = [ (object) [ 'term' => 'returns policy', 'hits' => 9 ], (object) [ 'term' => 'zzz', 'hits' => 1 ] ];

$alerts = FW_SEO_Alerts::build( $pages, $near, $searches, [], 12 );
$types  = array_column( $alerts, 'type', 'title' );

fw_seo_alert_is( 'each page gets the alert that fits it', $types, [
	'Hidden'                     => 'noindex',
	'Launch'                     => 'drop',
	'Pricing'                    => 'ctr',
	'Old guide'                  => 'stale',
	'Fine'                       => 'near',
	'Site search: "returns policy"' => 'search',
] );
fw_seo_alert_is( 'the most urgent come first', $alerts[0]['type'], 'noindex' );
fw_seo_alert_is( 'a page near page one is named once, not per query', count( array_filter( $alerts, static function ( $a ) {
	return 'near' === $a['type'];
} ) ), 1 );
fw_seo_alert_is( 'a rare site search is left out', false === strpos( wp_json_encode( $alerts ), 'zzz' ), true );
fw_seo_alert_is( 'the message is a sentence with the numbers in it', false !== strpos( $alerts[2]['message'], '5,000 people saw this page in Google and 20 clicked' ), true );
fw_seo_alert_is( 'each alert names its page and where to fix it', $alerts[2]['post_id'] === 12 && 'e12' === $alerts[2]['link'], true );

$many = [];
for ( $i = 0; $i < 20; $i ++ ) {
	$many[ "https://x.test/p$i/" ] = [ 'post_id' => 100 + $i, 'title' => "P$i", 'now' => [ 'impressions' => 5000, 'clicks' => 1, 'position' => 3 ] ];
}
fw_seo_alert_is( 'never more than the cap', count( FW_SEO_Alerts::build( $many, [], [] ) ), FW_SEO_Alerts::CAP );

fw_seo_alert_is( 'a broken connection is passed through, first', FW_SEO_Alerts::build( $pages, [], [], [ [ 'type' => 'connection', 'priority' => -1, 'title' => 'SC', 'message' => 'm', 'link' => '', 'url' => '', 'post_id' => 0 ] ] )[0]['type'], 'connection' );

/* Email --------------------------------------------------------------------- */

fw_seo_alert_is( 'the same list has the same fingerprint', FW_SEO_Alerts::fingerprint( $alerts ), FW_SEO_Alerts::fingerprint( $alerts ) );
fw_seo_alert_is( 'a changed list does not', FW_SEO_Alerts::fingerprint( $alerts ) !== FW_SEO_Alerts::fingerprint( array_slice( $alerts, 1 ) ), true );

$saved      = get_option( FW_SEO_Alerts::OPTION, null );
$saved_sent = get_option( FW_SEO_Alerts::SENT, null );
$settings   = get_option( 'fw_ext_settings_options:seo', [] );

$mails = [];
add_filter( 'pre_wp_mail', static function ( $null, $atts ) use ( &$mails ) {
	$mails[] = $atts;

	return true;
}, 10, 2 );

update_option( FW_SEO_Alerts::OPTION, [ 'time' => time(), 'alerts' => $alerts ], false );
delete_option( FW_SEO_Alerts::SENT );

$s                    = (array) $settings;
$s['alerts_email']    = true;
$s['alerts_email_to'] = 'owner@example.test';
update_option( 'fw_ext_settings_options:seo', $s );
if ( class_exists( 'FW_SEO_Settings' ) ) {
	$r = new ReflectionClass( 'FW_SEO_Settings' );
	foreach ( $r->getStaticProperties() as $k => $v ) {
		if ( is_array( $v ) || null === $v ) {
			$p = $r->getProperty( $k );
			$p->setAccessible( true );
			$p->setValue( null, null );
		}
	}
}

fw_seo_alert_is( 'switched on with alerts to tell: sent', FW_SEO_Alerts::send_email(), true );
fw_seo_alert_is( 'to the address given', $mails[0]['to'] ?? '', 'owner@example.test' );
fw_seo_alert_is( 'listing each alert', false !== strpos( (string) ( $mails[0]['message'] ?? '' ), 'Pricing' ), true );
fw_seo_alert_is( 'the same list is not sent twice', FW_SEO_Alerts::send_email(), false );

/* The editor note ------------------------------------------------------------ */

fw_seo_alert_is( 'a page with an alert gets a note in its SEO box', false !== strpos( FW_SEO_Alerts::editor_html( 12 ), 'clicked' ), true );
fw_seo_alert_is( 'a page without one gets nothing', FW_SEO_Alerts::editor_html( 999999 ), '' );
fw_seo_alert_is( 'the report renders', false !== strpos( FW_SEO_Alerts::report_html(), 'fw-seo-alerts' ), true );

/* Restore ---------------------------------------------------------------------- */

update_option( 'fw_ext_settings_options:seo', $settings );
null === $saved ? delete_option( FW_SEO_Alerts::OPTION ) : update_option( FW_SEO_Alerts::OPTION, $saved, false );
null === $saved_sent ? delete_option( FW_SEO_Alerts::SENT ) : update_option( FW_SEO_Alerts::SENT, $saved_sent, false );

printf( "\n%d passed, %d failed\n", FW_SEO_Alerts_T::$pass, FW_SEO_Alerts_T::$fail );

exit( FW_SEO_Alerts_T::$fail ? 1 : 0 );
