<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * XML sitemaps.
 *
 * A registry of providers rather than a class that knows about posts and terms.
 * A provider says how many entries it has and how to fetch one page of them;
 * everything else — chunking, the index, the XSL, the rewrite rules, the
 * noindex filtering — is handled here once. That is what lets a portfolio, an
 * events archive or anything else join the sitemap without touching this file.
 *
 * Two things the 1.0.x sitemap did that are deliberately NOT carried over:
 *
 *  - **Search-engine pinging.** Google retired its ping endpoint in 2023 and
 *    Bing followed; both now discover sitemaps from robots.txt and Search
 *    Console. Pinging is a request that is guaranteed to do nothing.
 *  - **Hardcoded priority and changefreq.** Google has said for years that it
 *    ignores both. Emitting them is noise that also invites tuning that cannot
 *    possibly have an effect.
 */
class FW_SEO_Sitemap {

	/** Bumped whenever the rewrite rules below change, to trigger one flush. */
	const REWRITE_VERSION = '1';

	const OPTION_REWRITE = 'fw_seo_rewrite_version';

	/** @var array<string,array> */
	protected static $providers = [];

	/** @var bool */
	protected static $registered = false;

	/**
	 * Register a sitemap provider.
	 *
	 * @param string $key  URL-safe id; becomes /sitemap-<key>-1.xml.
	 * @param array  $args {
	 *     @type string   $label Human label for the stylesheet.
	 *     @type callable $count fn(): int — total entries.
	 *     @type callable $urls  fn( int $page, int $per_page ): array<int,array{
	 *                           loc:string, lastmod?:string, images?:array<int,string>}>
	 * }
	 */
	public static function register_provider( $key, array $args ) {
		$key = sanitize_key( $key );

		if ( '' === $key || empty( $args['count'] ) || empty( $args['urls'] ) ) {
			return;
		}

		self::$providers[ $key ] = array_merge( [ 'label' => $key ], $args );
	}

	/**
	 * Load the built-in providers. Idempotent.
	 */
	public static function boot() {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		require_once dirname( __FILE__ ) . '/sitemap-providers.php';

		/** Fires after the built-in sitemap providers are registered. */
		do_action( 'fw_seo_register_sitemap_providers' );
	}

	/**
	 * @return array<string,array>
	 */
	public static function providers() {
		self::boot();

		return self::$providers;
	}

	/**
	 * How many URLs go in one sitemap file.
	 *
	 * @return int
	 */
	public static function per_page() {
		/** Filters how many URLs each sitemap file holds. Google's hard ceiling is 50,000. */
		$per_page = (int) apply_filters( 'fw_seo_sitemap_per_page', 1000 );

		return max( 1, min( 50000, $per_page ) );
	}

	/**
	 * @return bool
	 */
	public static function is_enabled() {
		return FW_SEO_Settings::flag( 'sitemap_enabled', true );
	}

	// -------------------------------------------------------------------------
	// Wiring
	// -------------------------------------------------------------------------

	/**
	 * Hooks. Called on both sides — the rewrite rules have to exist for the
	 * admin's permalink flush as well as for the front end.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'add_rewrite_rules' ] );
		add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_render' ], 0 );
		add_filter( 'robots_txt', [ __CLASS__, 'filter_robots_txt' ], 10, 2 );

		// Core has shipped its own sitemaps since 5.5. Two sitemaps at two URLs
		// is a support question waiting to happen, so ours replaces them unless
		// the site says otherwise.
		//
		// Attached on `init`, NOT at wiring time. Core consults
		// `wp_sitemaps_enabled` while it bootstraps its sitemap server early on
		// init, and our callback reads the extension settings — which forces
		// Unyson's option-type initialisation before the page-builder extension
		// has registered its `page-builder` type, fataling every builder page.
		// Attaching after core has booted means the callback only ever runs at
		// request time (template_redirect), which is safely late.
		//
		// Core's robots.txt line is registered during that same early bootstrap
		// and is therefore beyond this filter's reach; filter_robots_txt() below
		// strips it instead.
		add_action( 'init', [ __CLASS__, 'register_core_sitemap_filter' ], 11 );
	}

	/**
	 * @internal
	 */
	public static function register_core_sitemap_filter() {
		add_filter( 'wp_sitemaps_enabled', [ __CLASS__, 'filter_core_sitemaps_enabled' ] );
	}

	/**
	 * @internal
	 *
	 * @param bool $enabled
	 *
	 * @return bool
	 */
	public static function filter_core_sitemaps_enabled( $enabled ) {
		if ( self::is_enabled() && FW_SEO_Settings::flag( 'sitemap_replace_core', true ) ) {
			return false;
		}

		return $enabled;
	}

	/**
	 * @internal
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule( '^sitemap\.xml$', 'index.php?fw_seo_sitemap=index', 'top' );
		add_rewrite_rule( '^sitemap\.xsl$', 'index.php?fw_seo_sitemap=xsl', 'top' );
		add_rewrite_rule(
			'^sitemap-([a-z0-9_-]+)-(\d+)\.xml$',
			'index.php?fw_seo_sitemap=$matches[1]&fw_seo_sitemap_page=$matches[2]',
			'top'
		);

		// Flush exactly once per rules change, never on every request.
		if ( get_option( self::OPTION_REWRITE ) !== self::REWRITE_VERSION ) {
			flush_rewrite_rules( false );
			update_option( self::OPTION_REWRITE, self::REWRITE_VERSION );
		}
	}

	/**
	 * @internal
	 *
	 * @param array $vars
	 *
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'fw_seo_sitemap';
		$vars[] = 'fw_seo_sitemap_page';

		return $vars;
	}

	/**
	 * @internal
	 */
	public static function maybe_disable_core_sitemaps() {
		if ( ! self::is_enabled() || ! FW_SEO_Settings::flag( 'sitemap_replace_core', true ) ) {
			return;
		}

		add_filter( 'wp_sitemaps_enabled', '__return_false' );
	}

	/**
	 * @internal
	 *
	 * @param string $output
	 * @param string $public
	 *
	 * @return string
	 */
	public static function filter_robots_txt( $output, $public ) {
		if ( ! self::is_enabled() || ! $public ) {
			return $output;
		}

		$output = (string) $output;

		// Core adds its own `Sitemap: .../wp-sitemap.xml` line when it boots its
		// sitemap server, and it does so without checking whether sitemaps are
		// still enabled by the time robots.txt is built. When we have replaced
		// core's sitemap that URL is a 404, so the line has to come out —
		// advertising a dead sitemap is worse than advertising none.
		if ( FW_SEO_Settings::flag( 'sitemap_replace_core', true ) ) {
			$output = preg_replace( '#^\s*Sitemap:\s*\S*wp-sitemap\.xml\s*$#im', '', $output );
			$output = preg_replace( "#\n{3,}#", "\n\n", (string) $output );
		}

		// robots.txt is how the search engines actually discover a sitemap now
		// that the ping endpoints are gone.
		return rtrim( (string) $output ) . "\n\nSitemap: " . esc_url( self::index_url() ) . "\n";
	}

	/**
	 * @return string
	 */
	public static function index_url() {
		global $wp_rewrite;

		if ( $wp_rewrite && $wp_rewrite->using_permalinks() ) {
			return home_url( '/sitemap.xml' );
		}

		return add_query_arg( 'fw_seo_sitemap', 'index', home_url( '/' ) );
	}

	/**
	 * @param string $key
	 * @param int    $page
	 *
	 * @return string
	 */
	public static function provider_url( $key, $page = 1 ) {
		global $wp_rewrite;

		if ( $wp_rewrite && $wp_rewrite->using_permalinks() ) {
			return home_url( sprintf( '/sitemap-%s-%d.xml', $key, $page ) );
		}

		return add_query_arg(
			[ 'fw_seo_sitemap' => $key, 'fw_seo_sitemap_page' => $page ],
			home_url( '/' )
		);
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	/**
	 * @internal
	 */
	public static function maybe_render() {
		$which = get_query_var( 'fw_seo_sitemap' );

		if ( '' === $which || null === $which ) {
			return;
		}

		if ( ! self::is_enabled() ) {
			return; // Fall through to a normal 404.
		}

		self::boot();

		if ( 'xsl' === $which ) {
			self::render_stylesheet();
		}

		if ( 'index' === $which ) {
			self::render_index();
		}

		$page = max( 1, (int) get_query_var( 'fw_seo_sitemap_page' ) );

		if ( isset( self::$providers[ $which ] ) ) {
			self::render_provider( $which, $page );
		}

		// An unknown key is a 404, not an empty sitemap — an empty one would
		// tell a crawler the section legitimately has no URLs.
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
	}

	/**
	 * Common headers for every XML response.
	 */
	protected static function send_headers() {
		if ( headers_sent() ) {
			return;
		}

		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow', true );
	}

	/**
	 * The index: one entry per provider page.
	 */
	protected static function render_index() {
		self::send_headers();

		$entries = [];

		foreach ( self::$providers as $key => $provider ) {
			$total = (int) call_user_func( $provider['count'] );

			if ( $total < 1 ) {
				continue;
			}

			$pages = (int) ceil( $total / self::per_page() );

			for ( $page = 1; $page <= $pages; $page ++ ) {
				$entries[] = [
					'loc'     => self::provider_url( $key, $page ),
					'lastmod' => isset( $provider['lastmod'] ) ? call_user_func( $provider['lastmod'] ) : '',
				];
			}
		}

		/** Filters the entries listed in the sitemap index. */
		$entries = (array) apply_filters( 'fw_seo_sitemap_index_entries', $entries );

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<?xml-stylesheet type="text/xsl" href="' . esc_url( self::stylesheet_url() ) . '"?>' . "\n";
		echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $entries as $entry ) {
			echo "\t<sitemap>\n";
			echo "\t\t<loc>" . esc_url( $entry['loc'] ) . "</loc>\n";

			if ( ! empty( $entry['lastmod'] ) ) {
				echo "\t\t<lastmod>" . esc_html( $entry['lastmod'] ) . "</lastmod>\n";
			}

			echo "\t</sitemap>\n";
		}

		echo '</sitemapindex>';
		exit;
	}

	/**
	 * One provider's page of URLs.
	 *
	 * @param string $key
	 * @param int    $page
	 */
	protected static function render_provider( $key, $page ) {
		$provider = self::$providers[ $key ];
		$urls     = (array) call_user_func( $provider['urls'], $page, self::per_page() );

		/** Filters one sitemap page's URL entries. */
		$urls = (array) apply_filters( 'fw_seo_sitemap_urls', $urls, $key, $page );

		if ( ! $urls ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );

			return;
		}

		self::send_headers();

		$has_images = (bool) array_filter( wp_list_pluck( $urls, 'images' ) );

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<?xml-stylesheet type="text/xsl" href="' . esc_url( self::stylesheet_url() ) . '"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';

		if ( $has_images ) {
			echo ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
		}

		echo '>' . "\n";

		foreach ( $urls as $url ) {
			if ( empty( $url['loc'] ) ) {
				continue;
			}

			echo "\t<url>\n";
			echo "\t\t<loc>" . esc_url( $url['loc'] ) . "</loc>\n";

			if ( ! empty( $url['lastmod'] ) ) {
				echo "\t\t<lastmod>" . esc_html( $url['lastmod'] ) . "</lastmod>\n";
			}

			foreach ( (array) ( $url['images'] ?? [] ) as $image ) {
				if ( ! $image ) {
					continue;
				}

				echo "\t\t<image:image>\n";
				echo "\t\t\t<image:loc>" . esc_url( $image ) . "</image:loc>\n";
				echo "\t\t</image:image>\n";
			}

			echo "\t</url>\n";
		}

		echo '</urlset>';
		exit;
	}

	/**
	 * @return string
	 */
	public static function stylesheet_url() {
		global $wp_rewrite;

		if ( $wp_rewrite && $wp_rewrite->using_permalinks() ) {
			return home_url( '/sitemap.xsl' );
		}

		return add_query_arg( 'fw_seo_sitemap', 'xsl', home_url( '/' ) );
	}

	/**
	 * The human-readable stylesheet. A sitemap is machine output, but a person
	 * opens it far more often than a crawler explains itself, so it is worth
	 * being legible.
	 */
	protected static function render_stylesheet() {
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xslt+xml; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow', true );
		}

		// FW_Extension::render_view() is final protected, so the view is located
		// directly rather than through the extension.
		$view = dirname( __FILE__ ) . '/../views/sitemap-xsl.php';

		if ( file_exists( $view ) ) {
			fw_render_view( $view, [], false );
		}

		exit;
	}

	/**
	 * Post ids the site has marked noindex, so the sitemap never advertises a
	 * URL that the page itself tells crawlers to ignore.
	 *
	 * This is the query that the old storage model made impossible: the value
	 * lived inside a serialised option blob, invisible to WP_Query.
	 *
	 * @return array<int,int>
	 */
	public static function excluded_post_ids() {
		$ids = get_posts( [
			'post_type'      => 'any',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => FW_SEO_Store::meta_key( 'noindex' ),
			'meta_value'     => '1',
			'no_found_rows'  => true,
		] );

		return array_map( 'intval', (array) $ids );
	}
}
