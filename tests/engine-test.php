<?php
/**
 * Engine contract tests for the rebuilt SEO extension.
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/engine-test.php
 *
 * Exit 0 = all pass, 1 = something regressed. The fixture post and term are
 * created and deleted by the run, so it is safe against a live-ish install.
 *
 * Pins the behaviours that fail silently rather than loudly: tag collapsing,
 * modifiers, the dynamic families, and the description fallback chain.
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

foreach ( [
	'/includes/class-fw-seo-context.php',
	'/includes/class-fw-seo-tags.php',
	'/includes/class-fw-seo-content.php',
	'/includes/class-fw-seo-store.php',
	'/includes/class-fw-seo-locations.php',
	'/includes/class-fw-seo-settings.php',
	'/includes/class-fw-seo-chain.php',
	'/includes/class-fw-seo-head.php',
	'/includes/class-fw-seo-sitemap.php',
	'/helpers.php',
] as $file ) {
	require_once $base . $file;
}

class T {
	public static $pass = 0;
	public static $fail = 0;
}

function t( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		T::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	T::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

// -----------------------------------------------------------------------------
// Fixture: a post with a category, a custom field and some content.
// -----------------------------------------------------------------------------

$term = wp_insert_term( 'Engine Fixture Cat', 'category' );
$cat_id = is_wp_error( $term ) ? (int) get_cat_ID( 'Engine Fixture Cat' ) : (int) $term['term_id'];

$post_id = wp_insert_post( [
	'post_title'   => 'Rebuilding the SEO engine',
	'post_content' => '<p>The <strong>engine</strong> resolves tags lazily.</p><p>It also collapses empty ones, which is the part that shows on every page.</p>',
	'post_status'  => 'publish',
	'post_type'    => 'post',
	'post_category' => [ $cat_id ],
] );

update_post_meta( $post_id, 'subtitle', 'A field only this site has' );

$ctx = FW_SEO_Context::for_post( $post_id );

printf( "\nSEO engine contracts (post %d)\n\n", $post_id );

// -----------------------------------------------------------------------------
// Tag rendering
// -----------------------------------------------------------------------------

echo "Tag rendering\n";

t(
	'basic substitution',
	FW_SEO_Tags::render( '%%title%%', $ctx ),
	'Rebuilding the SEO engine'
);

t(
	'separator tag resolves',
	FW_SEO_Tags::render( '%%title%% %%sep%% %%sitename%%', $ctx ),
	'Rebuilding the SEO engine | ' . get_bloginfo( 'name' )
);

t(
	'an empty tag takes its orphaned separator with it',
	FW_SEO_Tags::render( '%%title%% %%sep%% %%searchphrase%% %%sep%% %%sitename%%', $ctx ),
	'Rebuilding the SEO engine | ' . get_bloginfo( 'name' )
);

t(
	'a leading empty tag drops the separator that FOLLOWS it',
	FW_SEO_Tags::render( '%%searchphrase%% %%sep%% %%title%%', $ctx ),
	'Rebuilding the SEO engine'
);

t(
	'an unknown tag renders as nothing, not as itself',
	FW_SEO_Tags::render( 'Hello %%no_such_tag%% World', $ctx ),
	'Hello World'
);

t(
	'whitespace-only literals are NOT treated as separators',
	FW_SEO_Tags::render( 'A %%no_such_tag%% B', $ctx ),
	'A B'
);

t(
	'everything empty collapses to an empty string',
	FW_SEO_Tags::render( '%%searchphrase%% %%sep%% %%no_such_tag%%', $ctx ),
	''
);

t(
	'a LITERAL separator collapses too, not just the %%sep%% tag',
	FW_SEO_Tags::render( '%%title%% - %%searchphrase%%', $ctx ),
	'Rebuilding the SEO engine'
);

t(
	'a literal separator in front of an empty tag is dropped forwards',
	FW_SEO_Tags::render( '%%searchphrase%%: %%title%%', $ctx ),
	'Rebuilding the SEO engine'
);

t(
	'two adjacent empty tags do not eat a real word between separators',
	FW_SEO_Tags::render( '%%searchphrase%% %%sep%% %%title%% %%sep%% %%no_such_tag%%', $ctx ),
	'Rebuilding the SEO engine'
);

t(
	'primary category resolves',
	FW_SEO_Tags::render( '%%primary_category%%', $ctx ),
	'Engine Fixture Cat'
);

// -----------------------------------------------------------------------------
// Modifiers
// -----------------------------------------------------------------------------

echo "\nModifiers\n";

t(
	'upper',
	FW_SEO_Tags::render( '%%title|upper%%', $ctx ),
	'REBUILDING THE SEO ENGINE'
);

t(
	'words',
	FW_SEO_Tags::render( '%%title|words:2%%', $ctx ),
	'Rebuilding the'
);

t(
	'truncate stops on a word boundary',
	FW_SEO_Tags::render( '%%title|truncate:14%%', $ctx ),
	'Rebuilding the'
);

t(
	'chained modifiers apply left to right',
	FW_SEO_Tags::render( '%%title|words:2|upper%%', $ctx ),
	'REBUILDING THE'
);

// -----------------------------------------------------------------------------
// Dynamic families
// -----------------------------------------------------------------------------

echo "\nDynamic families\n";

t(
	'%%cf_<key>%% reads an arbitrary custom field',
	FW_SEO_Tags::render( '%%cf_subtitle%%', $ctx ),
	'A field only this site has'
);

t(
	'%%cf_<key>%% for a field that does not exist is empty, not an error',
	FW_SEO_Tags::render( '%%cf_definitely_missing%%', $ctx ),
	''
);

t(
	'%%tax_<taxonomy>%% lists the terms',
	FW_SEO_Tags::render( '%%tax_category%%', $ctx ),
	'Engine Fixture Cat'
);

// -----------------------------------------------------------------------------
// The value chain
// -----------------------------------------------------------------------------

echo "\nValue chain\n";

t(
	'title falls through to the shipped template',
	FW_SEO_Chain::resolve( 'title', $ctx ),
	'Rebuilding the SEO engine | ' . get_bloginfo( 'name' )
);

t(
	'title source is the template stage',
	FW_SEO_Chain::source( 'title', $ctx ),
	'template'
);

$description = FW_SEO_Chain::resolve( 'description', $ctx );

t(
	'a description is auto-generated from the content with NO override and NO template',
	substr( $description, 0, 44 ),
	'The engine resolves tags lazily. It also col'
);

t(
	'the auto description is trimmed to the limit',
	strlen( $description ) <= fw_seo_description_limit(),
	true
);

t(
	'description source is the auto stage',
	FW_SEO_Chain::source( 'description', $ctx ),
	'auto'
);

// An override wins, and may itself contain tags.
FW_SEO_Store::set_post( $post_id, 'description', 'Read about %%title%% here.' );
FW_SEO_Chain::flush( $ctx );
FW_SEO_Tags::flush( $ctx );

t(
	'a per-post override beats the auto stage, and resolves its own tags',
	FW_SEO_Chain::resolve( 'description', $ctx ),
	'Read about Rebuilding the SEO engine here.'
);

t(
	'description source is now the override stage',
	FW_SEO_Chain::source( 'description', $ctx ),
	'override'
);

// -----------------------------------------------------------------------------
// Store round trip
// -----------------------------------------------------------------------------

echo "\nStore\n";

FW_SEO_Store::set_post( $post_id, 'noindex', 'true' );

t(
	'the switch wire format ("true") stores as a real boolean',
	FW_SEO_Store::get_post( $post_id, 'noindex' ),
	true
);

FW_SEO_Store::set_post( $post_id, 'noindex', false );

t(
	'clearing a value deletes the row rather than storing an empty one',
	metadata_exists( 'post', $post_id, '_fw_seo_noindex' ),
	false
);

FW_SEO_Store::set_post( $post_id, 'robots_advanced', [ 'noarchive', 'bogus_directive' ] );

t(
	'an unknown robots directive is dropped, not stored',
	FW_SEO_Store::get_post( $post_id, 'robots_advanced' ),
	[ 'noarchive' ]
);

t(
	'discrete meta keys are queryable, which is the whole point of them',
	(int) ( new WP_Query( [
		'post_type'      => 'post',
		'meta_key'       => '_fw_seo_description',
		'fields'         => 'ids',
		'posts_per_page' => 1,
	] ) )->found_posts >= 1,
	true
);

// -----------------------------------------------------------------------------
// Robots
// -----------------------------------------------------------------------------

echo "\nRobots\n";

t(
	'a normal post is index,follow',
	array_slice( fw_seo_robots( $ctx ), 0, 2 ),
	[ 'index', 'follow' ]
);

FW_SEO_Store::set_post( $post_id, 'noindex', true );

t(
	'noindex replaces index rather than joining it',
	array_slice( fw_seo_robots( $ctx ), 0, 2 ),
	[ 'noindex', 'follow' ]
);

// -----------------------------------------------------------------------------
// Sitemap
// -----------------------------------------------------------------------------

echo "\nSitemap\n";

// The fixture post is currently noindex from the robots block above; clear it
// so we can watch it enter and leave the sitemap.
FW_SEO_Store::set_post( $post_id, 'noindex', false );

$providers = FW_SEO_Sitemap::providers();

t(
	'a provider is registered per public post type',
	isset( $providers['pt-post'] ) && isset( $providers['pt-page'] ),
	true
);

t(
	'a provider is registered per public taxonomy',
	isset( $providers['tax-category'] ),
	true
);

$in_sitemap = static function ( $post_id ) {
	$urls = fw_seo_sitemap_post_urls( 'post', 1, 1000 );
	$want = get_permalink( $post_id );

	foreach ( $urls as $url ) {
		if ( $url['loc'] === $want ) {
			return true;
		}
	}

	return false;
};

t(
	'a normal published post appears in its sitemap',
	$in_sitemap( $post_id ),
	true
);

$before = fw_seo_sitemap_post_count( 'post' );

FW_SEO_Store::set_post( $post_id, 'noindex', true );

t(
	'marking a post noindex removes it from the sitemap',
	$in_sitemap( $post_id ),
	false
);

t(
	'...and the count drops with it, so the index paginates correctly',
	fw_seo_sitemap_post_count( 'post' ),
	$before - 1
);

FW_SEO_Store::set_post( $post_id, 'noindex', false );

t(
	'clearing noindex puts it back',
	$in_sitemap( $post_id ),
	true
);

t(
	'entries carry a W3C lastmod, which is the only format the schema accepts',
	(bool) preg_match(
		'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
		fw_seo_sitemap_post_urls( 'post', 1, 1 )[0]['lastmod'] ?? ''
	),
	true
);

t(
	'the index URL is what robots.txt will advertise',
	FW_SEO_Sitemap::index_url(),
	home_url( '/sitemap.xml' )
);

// -----------------------------------------------------------------------------

wp_delete_post( $post_id, true );
wp_delete_term( $cat_id, 'category' );


// -----------------------------------------------------------------------------
// Cache isolation
//
// spl_object_id() is unique only among LIVE objects. Resolving one context per
// request — which is all the front end ever does — hides the fact completely;
// resolving many in a loop, as a list table does, recycles ids and serves one
// post's title for another. Pinned here because the failure is silent and
// plausible: every row shows A title, just not its own.
// -----------------------------------------------------------------------------

echo "\nCache isolation\n";

$cache_a = wp_insert_post( [ 'post_title' => 'Cache fixture A', 'post_status' => 'publish', 'post_type' => 'post' ] );
$cache_b = wp_insert_post( [ 'post_title' => 'Cache fixture B', 'post_status' => 'publish', 'post_type' => 'post' ] );

$resolved = [];

// No variable holds the context between iterations, so each one becomes
// collectable the moment the next is created — exactly the loop that broke.
foreach ( [ $cache_a, $cache_b ] as $cache_id ) {
	$resolved[ $cache_id ] = FW_SEO_Chain::resolve( 'title', FW_SEO_Context::for_post( $cache_id ) );
}

t(
	'each post in a loop resolves its OWN title, not a recycled neighbour\'s',
	$resolved[ $cache_b ] !== $resolved[ $cache_a ],
	true
);

t(
	'...and the second one is actually right',
	false !== strpos( $resolved[ $cache_b ], 'Cache fixture B' ),
	true
);

wp_delete_post( $cache_a, true );
wp_delete_post( $cache_b, true );

printf( "\n%d passed, %d failed\n", T::$pass, T::$fail );

if ( T::$fail ) {
	exit( 1 );
}
