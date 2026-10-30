<?php
/**
 * Contract tests for structured data entities (FW_SEO_Entities): authors,
 * FAQ, local business, products, events, recipes and branch locations.
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/entities-test.php
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

require_once $base . '/includes/class-fw-seo-entities.php';

class FW_SEO_Ent_T {
	public static $pass = 0;
	public static $fail = 0;
}

function fw_seo_ent_is( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		FW_SEO_Ent_T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	FW_SEO_Ent_T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/* Hours ---------------------------------------------------------------------- */

fw_seo_ent_is( 'a plain range', FW_SEO_Entities::parse_hours( '09:00-17:00' ), [ [ '09:00', '17:00' ] ] );
fw_seo_ent_is( 'a lunch break', FW_SEO_Entities::parse_hours( '9:00–12:30, 13:30–18:00' ), [ [ '09:00', '12:30' ], [ '13:30', '18:00' ] ] );
fw_seo_ent_is( 'am / pm', FW_SEO_Entities::parse_hours( '9am-5:30pm' ), [ [ '09:00', '17:30' ] ] );
fw_seo_ent_is( 'closed', FW_SEO_Entities::parse_hours( 'Closed' ), [] );
fw_seo_ent_is( 'open all day', FW_SEO_Entities::parse_hours( '24 hours' ), [ [ '00:00', '23:59' ] ] );

$spec = FW_SEO_Entities::opening_hours( [ 'mon' => '09:00-17:00', 'tue' => '09:00-17:00', 'wed' => '09:00-17:00', 'thu' => '09:00-17:00', 'fri' => '09:00-17:00', 'sat' => '10:00-14:00', 'sun' => 'closed' ] );
fw_seo_ent_is( 'days with the same hours are one entry', count( $spec ), 2 );
fw_seo_ent_is( 'weekdays together', $spec[0]['dayOfWeek'], [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday' ] );
fw_seo_ent_is( 'a closed day is simply not listed', false === strpos( wp_json_encode( $spec ), 'Sunday' ), true );

/* Small parts ------------------------------------------------------------------ */

fw_seo_ent_is( 'an address needs a street or town', FW_SEO_Entities::address( [ 'country' => 'GB' ] ), null );
fw_seo_ent_is( 'and maps its parts', FW_SEO_Entities::address( [ 'street' => '1 Quay St', 'city' => 'Harbourtown', 'postcode' => 'HT1 2AB' ] ), [ '@type' => 'PostalAddress', 'streetAddress' => '1 Quay St', 'addressLocality' => 'Harbourtown', 'postalCode' => 'HT1 2AB' ] );
fw_seo_ent_is( 'a map position from "lat, lng"', FW_SEO_Entities::geo( '51.5072, -0.1276' ), [ '@type' => 'GeoCoordinates', 'latitude' => 51.5072, 'longitude' => -0.1276 ] );
fw_seo_ent_is( 'nonsense is no position', FW_SEO_Entities::geo( 'near the pier' ), null );
fw_seo_ent_is( 'durations in ISO 8601', [ FW_SEO_Entities::duration( 90 ), FW_SEO_Entities::duration( 45 ), FW_SEO_Entities::duration( 120 ), FW_SEO_Entities::duration( 0 ) ], [ 'PT1H30M', 'PT45M', 'PT2H', '' ] );

/* Entities ---------------------------------------------------------------------- */

$page = [ 'url' => 'https://x.test/p/', 'name' => 'Page title', 'description' => 'D', 'image' => 'https://x.test/i.jpg', 'publisher_id' => 'https://x.test/#organization', 'author_id' => 'https://x.test/#author-1' ];

fw_seo_ent_is( 'a product without a price or rating is not ready', FW_SEO_Entities::missing( [ 'type' => 'product' ] ), [ __( 'a price (or a rating)', 'fw' ) ] );
$p = FW_SEO_Entities::node( [ 'type' => 'product', 'price' => '£24.99', 'currency' => 'gbp', 'brand' => 'Oakline', 'rating' => '4.6', 'rating_count' => '0' ], $page );
fw_seo_ent_is( 'a product uses the page title when unnamed', $p['name'], 'Page title' );
fw_seo_ent_is( 'its offer has a clean price and currency', [ $p['offers']['price'], $p['offers']['priceCurrency'], $p['offers']['availability'] ], [ '24.99', 'GBP', 'https://schema.org/InStock' ] );
fw_seo_ent_is( 'a rating without reviews behind it is not published', isset( $p['aggregateRating'] ), false );

fw_seo_ent_is( 'an event needs a date and a place', count( FW_SEO_Entities::missing( [ 'type' => 'event' ] ) ), 2 );
$e = FW_SEO_Entities::node( [ 'type' => 'event', 'start' => '2026-11-20T19:00', 'attendance' => 'Mixed', 'venue' => 'The Boathouse', 'city' => 'Harbourtown', 'online_url' => 'https://stream.test/x' ], $page );
fw_seo_ent_is( 'a mixed event is at a venue and online', array_column( $e['location'], '@type' ), [ 'Place', 'VirtualLocation' ] );
fw_seo_ent_is( 'with its attendance mode', $e['eventAttendanceMode'], 'https://schema.org/MixedEventAttendanceMode' );
fw_seo_ent_is( 'organised by the site', $e['organizer'], [ '@id' => 'https://x.test/#organization' ] );

$r = FW_SEO_Entities::node( [ 'type' => 'recipe', 'prep' => '20', 'cook' => '70', 'ingredients' => "200 g flour\n2 eggs\n", 'steps' => "Heat the oven.\nMix." ], $page );
fw_seo_ent_is( 'recipe times, including the total', [ $r['prepTime'], $r['cookTime'], $r['totalTime'] ], [ 'PT20M', 'PT1H10M', 'PT1H30M' ] );
fw_seo_ent_is( 'one ingredient per line', $r['recipeIngredient'], [ '200 g flour', '2 eggs' ] );
fw_seo_ent_is( 'steps as HowToStep', $r['recipeInstructions'][1], [ '@type' => 'HowToStep', 'text' => 'Mix.' ] );
fw_seo_ent_is( 'by the post\'s author', $r['author'], [ '@id' => 'https://x.test/#author-1' ] );

$l = FW_SEO_Entities::node( [ 'type' => 'location', 'business_type' => 'Bakery', 'street' => '2 Mill Lane', 'city' => 'Harbourtown', 'mon' => '07:00-15:00', 'geo' => '50.1, -5.0' ], $page );
fw_seo_ent_is( 'a branch is its kind of business', $l['@type'], 'Bakery' );
fw_seo_ent_is( 'linked to the business as a whole', $l['parentOrganization'], [ '@id' => 'https://x.test/#organization' ] );
fw_seo_ent_is( 'with its own hours', $l['openingHoursSpecification'][0]['opens'], '07:00' );
fw_seo_ent_is( 'an unknown kind falls back to LocalBusiness', FW_SEO_Entities::node( [ 'type' => 'location', 'business_type' => '<script>', 'city' => 'X' ], $page )['@type'], 'LocalBusiness' );
fw_seo_ent_is( 'no type, no node', FW_SEO_Entities::node( [ 'type' => '' ], $page ), null );

/* FAQ from the builder ------------------------------------------------------------ */

$tree = [ [ 'type' => 'section', '_items' => [
	[ 'type' => 'simple', 'shortcode' => 'accordion', 'atts' => [ 'faq_schema' => 'yes', 'tabs' => [
		[ 'tab_title' => 'Do you deliver?', 'tab_content' => '<p>Yes, <strong>free</strong> over £50.</p>' ],
		[ 'tab_title' => 'Empty answer', 'tab_content' => '' ],
	] ] ],
	[ 'type' => 'simple', 'shortcode' => 'accordion', 'atts' => [ 'faq_schema' => 'no', 'tabs' => [ [ 'tab_title' => 'Not an FAQ', 'tab_content' => 'x' ] ] ] ],
	[ 'type' => 'simple', 'shortcode' => 'accordion', 'atts' => [ 'faq_schema' => 'yes', 'tabs' => [ [ 'tab_title' => 'do you deliver?', 'tab_content' => 'Duplicate' ] ] ] ],
] ] ];
$faq = FW_SEO_Entities::faq_from_builder( $tree );
fw_seo_ent_is( 'only switched-on accordions, empty answers and repeats left out', count( $faq ), 1 );
fw_seo_ent_is( 'the answer is plain text', $faq[0]['acceptedAnswer']['text'], 'Yes, free over £50.' );

/* The graph, end to end --------------------------------------------------------------- */

$post = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Entities fixture: lemon tart', 'post_author' => 1 ] );
update_post_meta( $post, FW_SEO_Entities::META, [ 'type' => 'recipe', 'prep' => '30', 'cook' => '25', 'ingredients' => "3 lemons\n200 g sugar", 'steps' => "Zest.\nBake." ] );
update_post_meta( $post, 'fw:opt:ext:pb:page-builder:json', wp_slash( wp_json_encode( $tree ) ) );
update_user_meta( 1, 'fw_seo_author_knows', 'Pastry, Baking' );

$ctx   = FW_SEO_Context::for_post( get_post( $post ) );
$graph = FW_SEO_Schema::graph( $ctx );
$types = array_map( static function ( $n ) {
	return is_array( $n['@type'] ) ? implode( '+', $n['@type'] ) : $n['@type'];
}, $graph );
fw_seo_ent_is( 'the recipe joins the graph', in_array( 'Recipe', $types, true ), true );
$pagenode = current( array_filter( $graph, static function ( $n ) {
	return '#page' === substr( (string) ( $n['@id'] ?? '' ), -5 );
} ) );
fw_seo_ent_is( 'the page is also the FAQ page, with its questions', [ in_array( 'FAQPage', (array) $pagenode['@type'], true ), count( $pagenode['mainEntity'] ?? [] ) ], [ true, 1 ] );
fw_seo_ent_is( 'and is about the recipe', $pagenode['about']['@id'] ?? '', get_permalink( $post ) . '#entity' );
$person = current( array_filter( $graph, static function ( $n ) {
	return 'Person' === ( $n['@type'] ?? '' );
} ) );
fw_seo_ent_is( 'the author knows about what they said', $person['knowsAbout'] ?? null, [ 'Pastry', 'Baking' ] );
fw_seo_ent_is( 'so the accordion leaves its own FAQ out', apply_filters( 'fw_sc_accordion_faq_inline', true ), false );

wp_delete_post( $post, true );
delete_user_meta( 1, 'fw_seo_author_knows' );

printf( "\n%d passed, %d failed\n", FW_SEO_Ent_T::$pass, FW_SEO_Ent_T::$fail );

exit( FW_SEO_Ent_T::$fail ? 1 : 0 );
