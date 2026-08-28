<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * SEO.
 *
 * The extension class is deliberately thin: it boots the engine, resolves the
 * context once, and wires the framework's hooks to it. Everything with logic in
 * it lives in `includes/` as a plain class, which keeps that logic testable
 * without a WordPress request around it.
 */
class FW_Extension_SEO extends FW_Extension {

	/**
	 * Nonce actions. They live here rather than on FW_SEO_Admin because that
	 * class is only loaded in the admin, while the option types that need the
	 * preview nonce are registered on both sides.
	 */
	const NONCE_SAVE    = 'fw_seo_save';
	const NONCE_PREVIEW = 'fw_seo_preview';

	/** @var FW_SEO_Context|null Resolved on `wp`. */
	protected $context = null;

	/**
	 * @internal
	 */
	public function _init() {
		$this->load_engine();

		add_action( 'fw_option_types_init', [ $this, '_action_register_option_types' ] );

		if ( is_admin() ) {
			$this->add_admin_hooks();
		} else {
			$this->add_theme_hooks();
		}
	}

	/**
	 * The engine classes. Loaded on both sides — the admin needs them to render
	 * the live preview, which resolves exactly what the front end will.
	 */
	protected function load_engine() {
		$includes = dirname( __FILE__ ) . '/includes/';

		require_once $includes . 'class-fw-seo-context.php';
		require_once $includes . 'class-fw-seo-tags.php';
		require_once $includes . 'class-fw-seo-content.php';
		require_once $includes . 'class-fw-seo-image.php';
		require_once $includes . 'class-fw-seo-schema.php';
		require_once $includes . 'class-fw-seo-import.php';
		require_once $includes . 'class-fw-seo-store.php';
		require_once $includes . 'class-fw-seo-locations.php';
		require_once $includes . 'class-fw-seo-settings.php';
		require_once $includes . 'class-fw-seo-chain.php';
		require_once $includes . 'class-fw-seo-head.php';
		require_once $includes . 'class-fw-seo-sitemap.php';

		// The sitemap wires itself on both sides: the rewrite rules must exist
		// for the admin's permalink flush, not only for the front end.
		FW_SEO_Sitemap::init();
	}

	/**
	 * @internal
	 */
	public function _action_register_option_types() {
		$types = dirname( __FILE__ ) . '/includes/option-types/';

		require_once $types . 'seo-template/class-fw-option-type-seo-template.php';
		require_once $types . 'seo-preview/class-fw-option-type-seo-preview.php';
	}

	// -------------------------------------------------------------------------
	// Front end
	// -------------------------------------------------------------------------

	protected function add_theme_hooks() {
		add_action( 'wp', [ $this, '_action_resolve_context' ], 5 );

		// 999 so a theme that sets its own title has already had its say.
		add_filter( 'pre_get_document_title', [ $this, '_filter_document_title' ], 999 );

		add_action( 'wp_head', [ $this, '_action_render_head' ], 1 );
		add_action( 'fw_seo_collect_head', [ $this, '_action_collect_head' ] );

		// Core prints its own robots tag since 5.7; take that over rather than
		// emitting a second, competing one.
		add_filter( 'wp_robots', [ $this, '_filter_robots' ], 999 );

		// The parent theme ships a metadata fallback for sites with no SEO
		// plugin. Claim each surface we emit so it stands down there and keeps
		// the rest — the description, the canonical, and now the social tags.
		//
		// Both are attached unconditionally and decide inside the callback. Do
		// NOT read the extension settings here: this runs during _init, and the
		// settings reader forces Unyson's option-type initialisation, which at
		// this point happens BEFORE the page-builder extension has registered
		// its `page-builder` option type. The builder then fatals on every page
		// built with it. (Same trap the portfolio extension documents.)
		add_filter( 'unysonplus_emit_meta_description', '__return_false' );
		add_filter( 'unysonplus_emit_meta_canonical', [ $this, '_filter_theme_canonical' ] );
		add_filter( 'unysonplus_emit_meta_social', [ $this, '_filter_theme_social' ] );
		add_filter( 'unysonplus_emit_schema', [ $this, '_filter_theme_schema' ] );
	}

	/**
	 * @internal
	 */
	public function _action_resolve_context() {
		$this->context = FW_SEO_Context::from_query();

		// Core prints its own canonical. Ours is the one that honours the
		// per-post override, so drop core's rather than shipping two — two
		// canonicals on a page is worse than either alone, because a search
		// engine is entitled to ignore both.
		if ( FW_SEO_Settings::flag( 'canonical_enabled', true ) ) {
			remove_action( 'wp_head', 'rel_canonical' );
		}
	}

	/**
	 * @return FW_SEO_Context|null
	 */
	public function get_context() {
		return $this->context;
	}

	/**
	 * Suppress the theme's canonical only while we are emitting our own.
	 *
	 * @internal
	 *
	 * @return bool
	 */
	public function _filter_theme_canonical() {
		return ! FW_SEO_Settings::flag( 'canonical_enabled', true );
	}

	/**
	 * Suppress the theme's social tags only while we are emitting our own.
	 *
	 * @internal
	 *
	 * @return bool
	 */
	public function _filter_theme_social() {
		return ! FW_SEO_Settings::flag( 'social_enabled', true );
	}

	/**
	 * Suppress the theme's JSON-LD only while we are emitting our own.
	 *
	 * The theme's gate defaults to "on unless a known SEO plugin is active", and
	 * its list of known plugins does not include us — so without this the site
	 * would carry two Organization nodes claiming the same @id.
	 *
	 * @internal
	 *
	 * @return bool
	 */
	public function _filter_theme_schema() {
		return ! FW_SEO_Settings::flag( 'schema_enabled', true );
	}

	/**
	 * @internal
	 *
	 * @param string $title
	 *
	 * @return string
	 */
	public function _filter_document_title( $title ) {
		if ( ! $this->context ) {
			return $title;
		}

		$resolved = FW_SEO_Chain::resolve( 'title', $this->context );

		return '' !== $resolved ? $resolved : $title;
	}

	/**
	 * @internal
	 */
	public function _action_render_head() {
		if ( ! $this->context ) {
			return;
		}

		FW_SEO_Head::render( $this->context );
	}

	/**
	 * The built-in head providers.
	 *
	 * @internal
	 *
	 * @param FW_SEO_Context $ctx
	 */
	public function _action_collect_head( FW_SEO_Context $ctx ) {
		$description = FW_SEO_Chain::resolve( 'description', $ctx );

		if ( '' !== $description ) {
			FW_SEO_Head::add( 'description', [
				'name'    => 'description',
				'content' => $description,
			], 'meta', 10 );
		}

		$canonical = FW_SEO_Chain::resolve( 'canonical', $ctx );

		if ( '' !== $canonical ) {
			FW_SEO_Head::add( 'canonical', [
				'rel'  => 'canonical',
				'href' => $canonical,
			], 'link', 20 );
		}

		$this->collect_social( $ctx );

		$this->collect_schema( $ctx );

		foreach ( $this->get_verification_tags() as $key => $tag ) {
			FW_SEO_Head::add( $key, $tag, 'meta', 30 );
		}
	}

	/**
	 * The JSON-LD graph.
	 *
	 * @param FW_SEO_Context $ctx
	 */
	protected function collect_schema( FW_SEO_Context $ctx ) {
		if ( ! FW_SEO_Settings::flag( 'schema_enabled', true ) ) {
			return;
		}

		$markup = FW_SEO_Schema::markup( $ctx );

		if ( '' !== $markup ) {
			FW_SEO_Head::add_raw( 'schema', $markup, 60 );
		}
	}

	/**
	 * Open Graph and Twitter card tags.
	 *
	 * Both networks are fed from one resolution, which is the point: a share
	 * card that disagrees with the search result is the bug this replaces, and it
	 * happened because the two were calculated in different places.
	 *
	 * @param FW_SEO_Context $ctx
	 */
	protected function collect_social( FW_SEO_Context $ctx ) {
		if ( ! FW_SEO_Settings::flag( 'social_enabled', true ) ) {
			return;
		}

		$og_title       = FW_SEO_Chain::resolve( 'og_title', $ctx );
		$og_description = FW_SEO_Chain::resolve( 'og_description', $ctx );
		$og_image       = FW_SEO_Chain::resolve( 'og_image', $ctx );
		$url            = fw_seo_canonical_url( $ctx );

		$tags = [
			'og_type'      => [ 'property' => 'og:type', 'content' => fw_seo_og_type( $ctx ) ],
			'og_title'     => [ 'property' => 'og:title', 'content' => $og_title ],
			'og_desc'      => [ 'property' => 'og:description', 'content' => $og_description ],
			'og_url'       => [ 'property' => 'og:url', 'content' => $url ],
			'og_site_name' => [ 'property' => 'og:site_name', 'content' => get_bloginfo( 'name' ) ],
			'og_locale'    => [ 'property' => 'og:locale', 'content' => get_locale() ],
		];

		if ( '' !== $og_image ) {
			$tags['og_image'] = [ 'property' => 'og:image', 'content' => $og_image ];

			// Dimensions let a crawler reserve the space before it has fetched
			// the file. Only ever emitted for an image we host and can measure —
			// a guessed size is worse than none, because it is believed.
			$size = FW_SEO_Image::dimensions( $og_image );

			if ( $size['width'] && $size['height'] ) {
				$tags['og_image_w'] = [ 'property' => 'og:image:width', 'content' => (string) $size['width'] ];
				$tags['og_image_h'] = [ 'property' => 'og:image:height', 'content' => (string) $size['height'] ];
			}
		}

		// Article metadata. Only on a real post — putting a published time on a
		// static page tells Google the page is news, which it is not.
		if ( $ctx->is( FW_SEO_Context::SINGULAR ) && $ctx->post_id() && 'article' === fw_seo_og_type( $ctx ) ) {
			$post_id = $ctx->post_id();

			$tags['article_published'] = [
				'property' => 'article:published_time',
				'content'  => (string) get_post_time( 'c', true, $post_id ),
			];
			$tags['article_modified'] = [
				'property' => 'article:modified_time',
				'content'  => (string) get_post_modified_time( 'c', true, $post_id ),
			];
		}

		foreach ( $tags as $key => $attr ) {
			if ( '' === trim( (string) $attr['content'] ) ) {
				continue;
			}

			FW_SEO_Head::add( $key, $attr, 'meta', 40 );
		}

		$this->collect_twitter( $ctx, $og_image );
	}

	/**
	 * @param FW_SEO_Context $ctx
	 * @param string         $og_image
	 */
	protected function collect_twitter( FW_SEO_Context $ctx, $og_image ) {
		$image = FW_SEO_Chain::resolve( 'twitter_image', $ctx );
		$card  = (string) FW_SEO_Store::get( $ctx, 'twitter_card' );

		if ( '' === $card ) {
			$card = (string) FW_SEO_Settings::get( 'twitter_card', 'summary_large_image' );
		}

		// A large-image card with no image renders as a bare link. Degrade to
		// the small card rather than emitting a promise nothing can keep.
		if ( 'summary_large_image' === $card && '' === $image && '' === $og_image ) {
			$card = 'summary';
		}

		$tags = [
			'tw_card'  => [ 'name' => 'twitter:card', 'content' => $card ],
			'tw_title' => [ 'name' => 'twitter:title', 'content' => FW_SEO_Chain::resolve( 'twitter_title', $ctx ) ],
			'tw_desc'  => [ 'name' => 'twitter:description', 'content' => FW_SEO_Chain::resolve( 'twitter_description', $ctx ) ],
			'tw_image' => [ 'name' => 'twitter:image', 'content' => $image ],
		];

		$site = trim( (string) FW_SEO_Settings::get( 'twitter_site', '' ) );

		if ( '' !== $site ) {
			$tags['tw_site'] = [ 'name' => 'twitter:site', 'content' => fw_seo_at_handle( $site ) ];
		}

		foreach ( $tags as $key => $attr ) {
			if ( '' === trim( (string) $attr['content'] ) ) {
				continue;
			}

			FW_SEO_Head::add( $key, $attr, 'meta', 50 );
		}
	}

	/**
	 * Search-engine ownership verification tags.
	 *
	 * @return array<string,array>
	 */
	protected function get_verification_tags() {
		$providers = [
			'google'    => 'google-site-verification',
			'bing'      => 'msvalidate.01',
			'yandex'    => 'yandex-verification',
			'pinterest' => 'p:domain_verify',
			'baidu'     => 'baidu-site-verification',
		];

		$tags = [];

		foreach ( $providers as $id => $meta_name ) {
			$value = trim( (string) FW_SEO_Settings::get( 'verify_' . $id, '' ) );

			if ( '' === $value ) {
				continue;
			}

			$tags[ 'verify_' . $id ] = [
				'name'    => $meta_name,
				'content' => $value,
			];
		}

		return $tags;
	}

	/**
	 * @internal
	 *
	 * @param array $robots
	 *
	 * @return array
	 */
	public function _filter_robots( $robots ) {
		if ( ! $this->context ) {
			return $robots;
		}

		$directives = fw_seo_robots( $this->context );
		$resolved   = [];

		foreach ( $directives as $directive ) {
			if ( false !== strpos( $directive, ':' ) ) {
				[ $name, $value ] = explode( ':', $directive, 2 );

				$resolved[ $name ] = $value;
				continue;
			}

			$resolved[ $directive ] = true;
		}

		// `index` and `follow` are the defaults; emitting them is noise, and
		// pairing `index` with `noindex` in one tag is a contradiction.
		if ( isset( $resolved['noindex'] ) ) {
			unset( $resolved['index'] );
		}

		if ( isset( $resolved['nofollow'] ) ) {
			unset( $resolved['follow'] );
		}

		return $resolved;
	}

	// -------------------------------------------------------------------------
	// Admin
	// -------------------------------------------------------------------------

	protected function add_admin_hooks() {
		require_once dirname( __FILE__ ) . '/includes/class-fw-seo-admin.php';
		require_once dirname( __FILE__ ) . '/includes/class-fw-seo-list.php';
		require_once dirname( __FILE__ ) . '/includes/class-fw-seo-settings-page.php';

		new FW_SEO_Admin( $this );
		new FW_SEO_List( $this );
		new FW_SEO_Settings_Page( $this );

		FW_SEO_Import::init();
	}

	/**
	 * The extension's own settings, read once.
	 *
	 * @param string|null $key
	 * @param mixed       $default_value
	 *
	 * @return mixed
	 */
	public function get_setting( $key = null, $default_value = null ) {
		if ( null === $key ) {
			return FW_SEO_Settings::all();
		}

		return FW_SEO_Settings::get( $key, $default_value );
	}
}
