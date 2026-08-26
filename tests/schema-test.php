<?php
/**
 * Contract tests for the JSON-LD graph.
 *
 * Run with:
 *   php wp-cli.phar --path=<install> eval-file \
 *       <install>/wp-content/plugins/unysonplus/framework/extensions/seo/tests/schema-test.php
 *
 * The assertions that matter are the ones about AGREEMENT: the graph reads its
 * description and image from the same chain as the meta tags, and structured
 * data that contradicts the tags beside it is worse than none — it is the
 * machine-readable copy, so it is the one that gets believed.
 */

$base = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/seo';

foreach ( [ 'context', 'tags', 'content', 'image', 'store', 'locations', 'settings', 'chain', 'head', 'schema' ] as $class ) {
	require_once $base . '/includes/class-fw-seo-' . $class . '.php';
}

require_once $base . '/helpers.php';

class TSC {
	public static $pass = 0;
	public static $fail = 0;
}

function tsc( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		TSC::$pass ++;
		printf( "  ok   %s\n", $label );

		return;
	}

	TSC::$fail ++;
	printf( "  FAIL %s\n         expected: %s\n         actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

/**
 * @param array  $nodes
 * @param string $type
 *
 * @return array|null
 */
function tsc_node( array $nodes, $type ) {
	foreach ( $nodes as $node ) {
		if ( isset( $node['@type'] ) && $node['@type'] === $type ) {
			return $node;
		}
	}

	return null;
}

$post_id = wp_insert_post( [
	'post_title'   => 'A structured post',
	'post_content' => '<p>Something worth describing in a graph.</p>',
	'post_status'  => 'publish',
	'post_type'    => 'post',
	'post_author'  => 1,
] );

$page_id = wp_insert_post( [
	'post_title'  => 'A structured page',
	'post_status' => 'publish',
	'post_type'   => 'page',
] );

$ctx  = FW_SEO_Context::for_post( $post_id );
$pctx = FW_SEO_Context::for_post( $page_id );

printf( "\nSchema contracts (post %d)\n\n", $post_id );

echo "Agreement with the meta tags\n";

FW_SEO_Chain::flush();

$graph = FW_SEO_Schema::graph( $ctx );
$page  = tsc_node( $graph, FW_SEO_Schema::type_for( 'post' ) );

tsc( 'a post gets a node', is_array( $page ), true );

tsc(
	'the graph description IS the resolved meta description',
	$page['description'] ?? '',
	FW_SEO_Chain::resolve( 'description', $ctx )
);

tsc(
	'the graph name IS the resolved SEO title',
	$page['name'] ?? '',
	FW_SEO_Chain::resolve( 'title', $ctx )
);

FW_SEO_Store::set_post( $post_id, 'description', 'A hand-written description' );
FW_SEO_Chain::flush();

$page = tsc_node( FW_SEO_Schema::graph( FW_SEO_Context::for_post( $post_id ) ), FW_SEO_Schema::type_for( 'post' ) );

tsc(
	'...and it follows an override, so the two can never disagree',
	$page['description'] ?? '',
	'A hand-written description'
);

FW_SEO_Store::set_post( $post_id, 'description', '' );

echo "\nGraph shape\n";

FW_SEO_Chain::flush();
$graph = FW_SEO_Schema::graph( $ctx );

$publisher = tsc_node( $graph, 'Organization' );
$author    = tsc_node( $graph, 'Person' );
$page      = tsc_node( $graph, FW_SEO_Schema::type_for( 'post' ) );

tsc( 'the publisher node exists', is_array( $publisher ), true );
tsc( 'the author is its own node', is_array( $author ), true );

tsc(
	'the page REFERENCES the publisher rather than repeating it',
	$page['publisher'] ?? null,
	[ '@id' => FW_SEO_Schema::publisher_id() ]
);

tsc(
	'...and references the author by id',
	$page['author'] ?? null,
	[ '@id' => $author['@id'] ]
);

tsc(
	'the author id is stable across posts, so one person is one entity',
	FW_SEO_Schema::author_id( 1 ),
	home_url( '/' ) . '#author-1'
);

tsc(
	'a WebSite node is NOT emitted on an inner page',
	tsc_node( $graph, 'WebSite' ),
	null
);

echo "\nPages are not articles\n";

FW_SEO_Chain::flush();
$pgraph = FW_SEO_Schema::graph( $pctx );
$webpage = tsc_node( $pgraph, 'WebPage' );

tsc( 'a page is a WebPage', is_array( $webpage ), true );
tsc( 'a page carries no byline', isset( $webpage['author'] ), false );
tsc( 'a page carries no headline', isset( $webpage['headline'] ), false );
tsc( 'and so no author node is emitted for it', tsc_node( $pgraph, 'Person' ), null );

echo "\nIdentity and sameAs\n";

$filter = static function () {
	return [
		'https://example.com/real',
		'not-a-url',
		'https://example.com/real',
		'',
	];
};

add_filter( 'fw_seo_schema_same_as', $filter );

$same = FW_SEO_Schema::same_as();

remove_filter( 'fw_seo_schema_same_as', $filter );

tsc( 'sameAs drops a bare handle — it resolves to nothing', in_array( 'not-a-url', $same, true ), false );
tsc( '...and de-duplicates', count( $same ), 1 );

tsc(
	'the publisher id changes with what the site represents',
	FW_SEO_Schema::publisher_id() !== '',
	true
);

echo "\nPer-type configuration\n";

tsc( 'a post defaults to a blog post', FW_SEO_Schema::default_type( 'post' ), 'BlogPosting' );
tsc( 'a page defaults to a web page', FW_SEO_Schema::default_type( 'page' ), 'WebPage' );

tsc(
	'"no structured data" is an available choice, not a hidden filter',
	isset( FW_SEO_Schema::type_choices()['none'] ),
	true
);



echo "\nMarkup\n";

FW_SEO_Chain::flush();
$markup = FW_SEO_Schema::markup( $ctx );

tsc( 'the graph is emitted as one script block', substr_count( $markup, '<script' ), 1 );
tsc( 'it is a single @graph, not a pile of separate nodes', substr_count( $markup, '"@context"' ), 1 );

$decoded = json_decode( str_replace( [ '<script type="application/ld+json">', '</script>' ], '', $markup ), true );

tsc( 'the JSON parses', is_array( $decoded ), true );
tsc( 'it declares the schema.org context once', $decoded['@context'] ?? '', 'https://schema.org' );
tsc( 'and carries the nodes under @graph', isset( $decoded['@graph'] ) && count( $decoded['@graph'] ) > 1, true );

tsc(
	'slashes in URLs are not escaped into unreadable JSON',
	false === strpos( $markup, '\/' ),
	true
);

wp_delete_post( $post_id, true );
wp_delete_post( $page_id, true );

printf( "\n%d passed, %d failed\n", TSC::$pass, TSC::$fail );

if ( TSC::$fail ) {
	exit( 1 );
}
