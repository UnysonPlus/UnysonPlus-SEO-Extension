<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The editor surfaces: the post metabox, the term fields, and the AJAX endpoint
 * that resolves templates so the preview shows real values rather than %%title%%.
 *
 * The overrides are NOT wired through `fw_post_options`. That filter would hand
 * us the UI and the saving for free, but it stores into the Unyson option blob,
 * where WP_Query cannot see the values — which permanently rules out a bulk
 * editor, list-table columns and a sitemap that can filter noindexed posts. So
 * we borrow the renderer (`render_options`) and the input parser
 * (`fw_get_options_values_from_input`) and keep the storage our own.
 */
class FW_SEO_Admin {

	const NONCE_SAVE    = FW_Extension_SEO::NONCE_SAVE;
	const NONCE_PREVIEW = FW_Extension_SEO::NONCE_PREVIEW;

	/** @var FW_Extension_SEO */
	protected $extension;

	/**
	 * @param FW_Extension_SEO $extension
	 */
	public function __construct( $extension ) {
		$this->extension = $extension;

		add_action( 'add_meta_boxes', [ $this, '_action_add_meta_box' ] );
		add_action( 'save_post', [ $this, '_action_save_post' ], 10, 2 );

		add_action( 'admin_init', [ $this, '_action_register_term_fields' ] );

		add_action( 'wp_ajax_fw_seo_preview', [ $this, '_action_ajax_preview' ] );

		add_action( 'admin_enqueue_scripts', [ $this, '_action_enqueue' ] );
	}

	// -------------------------------------------------------------------------
	// Post metabox
	// -------------------------------------------------------------------------

	/**
	 * @internal
	 */
	public function _action_add_meta_box() {
		foreach ( array_keys( FW_SEO_Locations::post_types() ) as $post_type ) {
			add_meta_box(
				'fw-seo',
				__( 'SEO', 'fw' ),
				[ $this, 'render_meta_box' ],
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * @param WP_Post $post
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_SAVE, 'fw_seo_nonce' );

		$ctx = FW_SEO_Context::for_post( $post );

		$values = [];

		foreach ( self::option_map() as $option_id => $field ) {
			$values[ $option_id ] = self::to_option_value( $field, FW_SEO_Store::get_post( $post->ID, $field ) );
		}

		$values = self::apply_prefill( $values, $ctx );

		self::render_tabs( self::get_tabs( $post->ID, null, $ctx ), $values, 'fw-seo-metabox' );
	}

	/**
	 * Render the tabs.
	 *
	 * Uses the framework's own `tab` CONTAINER rather than hand-rolled
	 * nav-tab-wrapper markup. The theme's "Page Settings" metabox is built the
	 * same way, and matching it is the whole point: a metabox that styles its own
	 * tabs sits next to one that doesn't and reads as broken.
	 *
	 * @param array  $tabs
	 * @param array  $values
	 * @param string $wrapper_class
	 */
	protected static function render_tabs( array $tabs, array $values, $wrapper_class ) {
		$options = [];

		foreach ( $tabs as $key => $tab ) {
			$options[ 'fw_seo_tab_' . $key ] = [
				'title'   => $tab['label'],
				'type'    => 'tab',
				// Render every tab up front rather than lazily. The framework does
				// inject lazy tabs into the form on submit, so this is not fixing
				// a data-loss bug — it keeps the DOM predictable (and testable),
				// and this metabox is far too small for lazy rendering to buy
				// anything.
				'lazy_tabs' => false,
				'options'   => $tab['options'],
			];
		}

		echo '<div class="' . esc_attr( $wrapper_class ) . '">';
		// phpcs:ignore WordPress.Security.EscapeOutput -- render_options returns prepared markup.
		echo fw()->backend->render_options( $options, $values );
		echo '</div>';
	}

	/**
	 * Show the pre-filled value wherever the user has not set their own.
	 *
	 * @param array               $values
	 * @param FW_SEO_Context|null $ctx
	 *
	 * @return array
	 */
	protected static function apply_prefill( array $values, ?FW_SEO_Context $ctx ) {
		$prefill = self::prefill_values( $ctx );

		foreach ( $prefill as $option_id => $default ) {
			if ( '' === trim( (string) ( $values[ $option_id ] ?? '' ) ) ) {
				$values[ $option_id ] = $default;
			}

			$values[ self::pristine_id( $option_id ) ] = $default;
		}

		return $values;
	}

	/**
	 * @internal
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public function _action_save_post( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// A metabox absent from the submitted form must not be read as "the user
		// cleared every field" — that is how a quick-edit or a REST save would
		// silently wipe the overrides.
		if ( ! isset( $_POST['fw_seo_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fw_seo_nonce'] ) ), self::NONCE_SAVE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! FW_SEO_Locations::has_post_type( $post->post_type ) ) {
			return;
		}

		$values = fw_get_options_values_from_input( self::get_options( $post_id ) );
		$values = self::discard_untouched_prefills( $values );

		$submitted = self::submitted_keys();

		foreach ( self::option_map() as $option_id => $field ) {
			if ( 'robots_advanced' === $field ) {
				continue;
			}

			if ( ! isset( $submitted[ $option_id ] ) ) {
				continue; // Never rendered — leave whatever is stored alone.
			}

			FW_SEO_Store::set_post( $post_id, $field, $values[ $option_id ] ?? '' );
		}

		if ( array_intersect_key( $submitted, self::advanced_map() ) ) {
			FW_SEO_Store::set_post( $post_id, 'robots_advanced', self::collect_advanced( $values ) );
		}
	}

	/**
	 * The option ids actually present in the submitted form.
	 *
	 * `fw_get_options_values_from_input()` fills in each option's default for
	 * anything missing, which is right for a form that rendered every field and
	 * wrong for one that did not: a field that never reached the browser comes
	 * back as its default and overwrites whatever was stored. So the raw input
	 * is consulted to tell "the user cleared this" from "this was never on the
	 * page".
	 *
	 * Not a live bug today — the framework injects lazy tabs on submit, and the
	 * nonce check already rejects saves that never rendered the metabox. This
	 * covers the remaining case: the injection failing to run, e.g. after a JS
	 * error elsewhere on the page.
	 *
	 * @return array<string,bool>
	 */
	protected static function submitted_keys() {
		$input = FW_Request::POST( fw()->backend->get_options_name_attr_prefix() );

		if ( ! is_array( $input ) ) {
			return [];
		}

		return array_fill_keys( array_keys( $input ), true );
	}

	/**
	 * A pre-filled field the user never touched must not be stored.
	 *
	 * This is the whole reason pre-filling is safe. Saving the pre-fill verbatim
	 * would turn every post into an override carrying a frozen copy of the
	 * template — and a later site-wide template change would then update nothing,
	 * silently, because every post holds its own detached snapshot.
	 *
	 * The comparison is against the hidden value we actually rendered, not
	 * against a freshly recomputed one: on `save_post` the post content has
	 * already been updated, so regenerating the description would produce the NEW
	 * text while the form carries the OLD — and every save of an edited post
	 * would look like a deliberate customisation.
	 *
	 * @param array $values
	 *
	 * @return array
	 */
	protected static function discard_untouched_prefills( array $values ) {
		foreach ( [ 'seo_title', 'seo_description' ] as $option_id ) {
			$pristine  = (string) ( $values[ self::pristine_id( $option_id ) ] ?? '' );
			$submitted = (string) ( $values[ $option_id ] ?? '' );

			if ( '' === $pristine ) {
				continue;
			}

			if ( self::normalize( $submitted ) === self::normalize( $pristine ) ) {
				$values[ $option_id ] = '';
			}
		}

		return $values;
	}

	/**
	 * Whitespace-insensitive comparison, so a stray trailing space in a textarea
	 * does not read as the user having customised the field.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	protected static function normalize( $value ) {
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $value ) );
	}

	// -------------------------------------------------------------------------
	// Term fields
	// -------------------------------------------------------------------------

	/**
	 * @internal
	 */
	public function _action_register_term_fields() {
		foreach ( array_keys( FW_SEO_Locations::taxonomies() ) as $taxonomy ) {
			add_action( $taxonomy . '_edit_form', [ $this, 'render_term_fields' ], 10, 2 );
			add_action( 'edited_' . $taxonomy, [ $this, '_action_save_term' ], 10, 2 );
		}
	}

	/**
	 * @param WP_Term $term
	 */
	public function render_term_fields( $term ) {
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		wp_nonce_field( self::NONCE_SAVE, 'fw_seo_nonce' );

		$ctx = FW_SEO_Context::for_term( $term );

		$values = [];

		foreach ( self::option_map() as $option_id => $field ) {
			$values[ $option_id ] = self::to_option_value( $field, FW_SEO_Store::get_term( $term->term_id, $field ) );
		}

		$values = self::apply_prefill( $values, $ctx );

		echo '<h2>' . esc_html__( 'SEO', 'fw' ) . '</h2>';

		self::render_tabs( self::get_tabs( 0, $term, $ctx ), $values, 'fw-seo-term-fields' );
	}

	/**
	 * @internal
	 *
	 * @param int $term_id
	 */
	public function _action_save_term( $term_id ) {
		if ( ! isset( $_POST['fw_seo_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fw_seo_nonce'] ) ), self::NONCE_SAVE ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		$values = fw_get_options_values_from_input( self::get_options( 0 ) );
		$values = self::discard_untouched_prefills( $values );

		$submitted = self::submitted_keys();

		foreach ( self::option_map() as $option_id => $field ) {
			if ( 'robots_advanced' === $field ) {
				continue;
			}

			if ( ! isset( $submitted[ $option_id ] ) ) {
				continue; // Never rendered — leave whatever is stored alone.
			}

			FW_SEO_Store::set_term( $term_id, $field, $values[ $option_id ] ?? '' );
		}

		if ( array_intersect_key( $submitted, self::advanced_map() ) ) {
			FW_SEO_Store::set_term( $term_id, 'robots_advanced', self::collect_advanced( $values ) );
		}
	}

	// -------------------------------------------------------------------------
	// The option schema shared by both surfaces
	// -------------------------------------------------------------------------

	/**
	 * Option id => store field.
	 *
	 * @return array<string,string>
	 */
	/**
	 * The two fields that ship pre-filled, and how each is pre-filled.
	 *
	 * The title carries the raw TEMPLATE, tags and all, the way Yoast and AIOSEO
	 * show it — so the user can see and edit the pattern itself. The description
	 * carries the resolved, generated prose, because a description template is
	 * usually empty and tags there would be showing the user machinery instead
	 * of the sentence that will actually appear.
	 *
	 * @param FW_SEO_Context|null $ctx
	 *
	 * @return array<string,string> option id => pre-filled value
	 */
	public static function prefill_values( ?FW_SEO_Context $ctx ) {
		if ( ! $ctx ) {
			return [ 'seo_title' => '', 'seo_description' => '' ];
		}

		// Both are computed with the override stage suppressed: the pre-fill is
		// "what this page gets when the user has said nothing", which is exactly
		// the chain minus its first rung.
		$suppress = static function ( $candidate, $stage ) {
			return 'override' === $stage ? '' : $candidate;
		};

		add_filter( 'fw_seo_chain_stage', $suppress, 10, 2 );
		FW_SEO_Chain::flush( $ctx );

		$description = FW_SEO_Chain::resolve( 'description', $ctx );

		remove_filter( 'fw_seo_chain_stage', $suppress, 10 );
		FW_SEO_Chain::flush( $ctx );

		return [
			'seo_title'       => FW_SEO_Settings::template( 'title', $ctx ),
			'seo_description' => $description,
		];
	}

	/**
	 * The hidden companion that records what we pre-filled.
	 *
	 * @param string $option_id
	 *
	 * @return string
	 */
	public static function pristine_id( $option_id ) {
		return $option_id . '__pristine';
	}
	/**
	 * Re-inflate a stored value into the shape its option type expects.
	 *
	 * The store keeps an image as a plain URL, because that is what every reader
	 * downstream — the head, the sitemap, a future schema graph — actually wants.
	 * The media picker wants the array it posts. This is the one seam between
	 * those two truths, and it is deliberately here rather than in the store:
	 * a storage format shaped by one admin widget is a storage format that
	 * breaks when the widget changes.
	 *
	 * @param string $field
	 * @param mixed  $value
	 *
	 * @return mixed
	 */
	protected static function to_option_value( $field, $value ) {
		if ( ! in_array( $field, [ 'og_image', 'twitter_image' ], true ) ) {
			return $value;
		}

		$url = (string) $value;

		if ( '' === $url ) {
			return '';
		}

		$attachment_id = attachment_url_to_postid( $url );

		return $attachment_id
			? [ 'attachment_id' => $attachment_id, 'url' => $url ]
			: [ 'url' => $url ];
	}


	/**
	 * @return array<string,string>
	 */
	public static function option_map() {
		return [
			'seo_title'             => 'title',
			'seo_description'       => 'description',
			'seo_canonical'         => 'canonical',
			'seo_noindex'           => 'noindex',
			'seo_nofollow'          => 'nofollow',
			'seo_max_snippet'       => 'max_snippet',
			'seo_max_image_preview' => 'max_image_preview',
			'seo_max_video_preview' => 'max_video_preview',
			'seo_og_title'          => 'og_title',
			'seo_og_description'    => 'og_description',
			'seo_og_image'          => 'og_image',
			'seo_twitter_card'      => 'twitter_card',
		];
	}

	/**
	 * The advanced robots directives, each its own switch, folded into the list
	 * the store keeps.
	 *
	 * @return array<string,string>
	 */
	public static function advanced_map() {
		return [
			'seo_noarchive'    => 'noarchive',
			'seo_nosnippet'    => 'nosnippet',
			'seo_noimageindex' => 'noimageindex',
		];
	}

	/**
	 * @param array $values
	 *
	 * @return array<int,string>
	 */
	protected static function collect_advanced( array $values ) {
		$directives = [];

		foreach ( self::advanced_map() as $option_id => $directive ) {
			if ( ! empty( $values[ $option_id ] ) ) {
				$directives[] = $directive;
			}
		}

		return $directives;
	}

	/**
	 * @param int          $post_id
	 * @param WP_Term|null $term
	 *
	 * @return array
	 */
	public static function get_options( $post_id = 0, $term = null, ?FW_SEO_Context $ctx = null ) {
		$options = [];

		foreach ( self::get_tabs( $post_id, $term, $ctx ) as $tab ) {
			$options = array_merge( $options, $tab['options'] );
		}

		return $options;
	}

	/**
	 * The metabox, split into tabs.
	 *
	 * One panel per tab, each rendered by its own render_options() call. Every
	 * field stays in the DOM — the tabs only hide panels with CSS — so the save
	 * path still sees the complete schema from get_options() and nothing is lost
	 * by submitting from a tab the user never opened.
	 *
	 * @param int                 $post_id
	 * @param WP_Term|null        $term
	 * @param FW_SEO_Context|null $ctx
	 *
	 * @return array<string,array{label:string,options:array}>
	 */
	public static function get_tabs( $post_id = 0, $term = null, ?FW_SEO_Context $ctx = null ) {
		$prefill = self::prefill_values( $ctx );
		$advanced = [];

		foreach ( self::advanced_map() as $option_id => $directive ) {
			$advanced[ $option_id ] = [
				'label' => $directive,
				'desc'  => self::advanced_description( $directive ),
				'type'  => 'switch',
				'value' => false,
			];
		}

		$tabs = [];

		$tabs['general'] = [
			'label'   => __( 'General', 'fw' ),
			'options' => [
			'seo_preview' => [
				'type'  => 'seo-preview',
				'label' => false,
			],
			'seo_general' => [
				'type'    => 'group',
				'options' => [
					'seo_title'                            => [
						'label'   => __( 'SEO title', 'fw' ),
						'desc'    => __( 'Pre-filled with the template for this content type. Edit it to give this page its own title, or clear it to go back to the template.', 'fw' ),
						'type'    => 'seo-template',
						'value'   => '',
						'field'   => 'title',
						'measure' => 'pixels',
						'limit'   => 600,
					],
					self::pristine_id( 'seo_title' )       => [
						'type'  => 'hidden',
						'label' => false,
						'value' => $prefill['seo_title'],
					],
					'seo_description'                      => [
						'label'     => __( 'Meta description', 'fw' ),
						'desc'      => __( 'Pre-filled with the description generated from this page\'s content. Edit it to write your own, or clear it to go back to the generated one.', 'fw' ),
						'type'      => 'seo-template',
						'value'     => '',
						'field'     => 'description',
						'multiline' => true,
						'measure'   => 'characters',
						'limit'     => 160,
					],
					self::pristine_id( 'seo_description' ) => [
						'type'  => 'hidden',
						'label' => false,
						'value' => $prefill['seo_description'],
					],
				],
			],
			],
		];

		$tabs['social'] = [
			'label'   => __( 'Social', 'fw' ),
			'options' => [
			'seo_social' => [
				'type'    => 'group',
				'options' => [
					'seo_og_title'       => [
						'label' => __( 'Share title', 'fw' ),
						'desc'  => __( 'Leave empty to use the SEO title. Fill it in when a shared link should say something different from a search result — a shorter, more conversational line usually performs better.', 'fw' ),
						'type'  => 'text',
						'value' => '',
					],
					'seo_og_description' => [
						'label' => __( 'Share description', 'fw' ),
						'desc'  => __( 'Leave empty to use the meta description.', 'fw' ),
						'type'  => 'textarea',
						'value' => '',
					],
					'seo_og_image'       => [
						'label' => __( 'Share image', 'fw' ),
						'desc'  => __( 'Leave empty and the featured image is used, then the first image on the page, then your site-wide default. 1200 × 630 pixels crops cleanly everywhere.', 'fw' ),
						'type'  => 'upload',
						'images_only' => true,
						'value' => '',
					],
					'seo_twitter_card'   => [
						'label'   => __( 'Card style on X', 'fw' ),
						'desc'    => __( 'Overrides the site-wide card style for this page only.', 'fw' ),
						'type'    => 'select',
						'value'   => '',
						'choices' => [
							''                    => __( 'Use the site setting', 'fw' ),
							'summary_large_image' => __( 'Large image', 'fw' ),
							'summary'             => __( 'Small thumbnail', 'fw' ),
						],
					],
				],
			],
			],
		];

		$tabs['advanced'] = [
			'label'   => __( 'Advanced', 'fw' ),
			'options' => [
			'seo_robots_default' => [
				'label' => __( 'Robots meta', 'fw' ),
				'desc'  => __( 'Use the settings for this content type. Switch off to give this page its own indexing rules.', 'fw' ),
				'type'  => 'switch',
				'value' => true,
			],
			'seo_advanced' => [
				'type'    => 'group',
				'options' => array_merge(
					[
						'seo_canonical' => [
							'label' => __( 'Canonical URL', 'fw' ),
							'desc'  => __( 'Point search engines at a different URL as the original. Leave empty to use this page\'s own address.', 'fw' ),
							'type'  => 'text',
							'value' => '',
						],
						'seo_noindex'   => [
							'label' => __( 'No index', 'fw' ),
							'desc'  => __( 'Ask search engines to keep this out of their index.', 'fw' ),
							'type'  => 'switch',
							'value' => false,
						],
						'seo_nofollow'  => [
							'label' => __( 'No follow', 'fw' ),
							'desc'  => __( 'Ask search engines not to follow the links on this page.', 'fw' ),
							'type'  => 'switch',
							'value' => false,
						],
					],
					$advanced,
					[
						'seo_max_snippet'       => [
							'label' => __( 'Max snippet length', 'fw' ),
							'desc'  => __( 'Longest text snippet a search engine may show, in characters. 0 leaves it to them, -1 removes the limit.', 'fw' ),
							'type'  => 'text',
							'value' => '',
						],
						'seo_max_image_preview' => [
							'label'   => __( 'Max image preview', 'fw' ),
							'desc'    => __( 'How large an image preview may be shown in results.', 'fw' ),
							'type'    => 'select',
							'value'   => '',
							'choices' => [
								''         => __( 'Search engine default', 'fw' ),
								'none'     => __( 'None', 'fw' ),
								'standard' => __( 'Standard', 'fw' ),
								'large'    => __( 'Large', 'fw' ),
							],
						],
						'seo_max_video_preview' => [
							'label' => __( 'Max video preview', 'fw' ),
							'desc'  => __( 'Longest video preview in seconds. 0 leaves it to them, -1 removes the limit.', 'fw' ),
							'type'  => 'text',
							'value' => '',
						],
					]
				),
			],
			],
		];

		/** Filters the SEO metabox tabs shown on the post editor and term edit screens. */
		return apply_filters( 'fw_seo_object_tabs', $tabs, $post_id, $term, $ctx );
	}

	/**
	 * @param string $directive
	 *
	 * @return string
	 */
	protected static function advanced_description( $directive ) {
		switch ( $directive ) {
			case 'noarchive':
				return __( 'Do not keep a cached copy of this page.', 'fw' );

			case 'nosnippet':
				return __( 'Do not show any text snippet or video preview in results.', 'fw' );

			case 'noimageindex':
				return __( 'Do not index the images on this page.', 'fw' );

			default:
				return '';
		}
	}

	// -------------------------------------------------------------------------
	// Live preview
	// -------------------------------------------------------------------------

	/**
	 * Resolve the templates currently in the editor against a real context.
	 *
	 * The templates arrive unsaved, so the override stage of the chain has to be
	 * suppressed: what the store holds is the last SAVED override, and echoing
	 * that back while the user is typing would show them the wrong thing.
	 *
	 * @internal
	 */
	public function _action_ajax_preview() {
		check_ajax_referer( self::NONCE_PREVIEW, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'fw' ) ], 403 );
		}

		if ( ! $post_id && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'fw' ) ], 403 );
		}

		// No post in play — the settings screen. Resolve against a real recent
		// post instead of refusing: a counter measuring the literal characters
		// of "%%title%% %%sep%% %%sitename%%" is a number with no meaning, and a
		// preview showing the raw tags teaches the user nothing.
		$sample = false;

		if ( ! $post_id ) {
			$post_id = self::sample_post_id();
			$sample  = true;
		}

		$ctx = $post_id ? FW_SEO_Context::for_post( $post_id ) : null;

		if ( ! $ctx ) {
			wp_send_json_error( [ 'message' => __( 'Nothing to preview yet.', 'fw' ) ], 400 );
		}

		$field = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';

		if ( ! in_array( $field, [ 'title', 'description' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown field.', 'fw' ) ], 400 );
		}

		$template = isset( $_POST['template'] ) ? (string) wp_unslash( $_POST['template'] ) : '';
		$template = trim( wp_strip_all_tags( $template, true ) );

		// A field still holding exactly what we pre-filled is not an override,
		// whatever it looks like. Treating it as one would label a template or a
		// generated description as "Your text" on every unedited post.
		$pristine = isset( $_POST['pristine'] ) ? (string) wp_unslash( $_POST['pristine'] ) : '';

		if ( '' !== $pristine && self::normalize( $template ) === self::normalize( $pristine ) ) {
			$template = '';
		}

		if ( '' !== $template ) {
			$value  = FW_SEO_Tags::render( $template, $ctx );
			$source = $sample ? 'template' : 'override';
		} else {
			// Empty box: show what the site would produce on its own. The store
			// still holds the last SAVED override, so that stage is suppressed —
			// echoing it back while the user is clearing the field would show
			// them the opposite of what they are about to get.
			$suppress = static function ( $candidate, $stage ) {
				return 'override' === $stage ? '' : $candidate;
			};

			add_filter( 'fw_seo_chain_stage', $suppress, 10, 2 );
			FW_SEO_Chain::flush( $ctx );

			$value  = FW_SEO_Chain::resolve( $field, $ctx );
			$source = FW_SEO_Chain::source( $field, $ctx );

			remove_filter( 'fw_seo_chain_stage', $suppress, 10 );
			FW_SEO_Chain::flush( $ctx );
		}

		wp_send_json_success( [
			'field'  => $field,
			'value'  => $value,
			'source' => $source,
			'url'    => fw_seo_canonical_url( $ctx ),
			'sample' => $sample,
			'post'   => $sample ? get_the_title( $post_id ) : '',
		] );
	}

	/**
	 * A representative published post, for previewing a template on the
	 * settings screen where no single post is in play.
	 *
	 * @return int
	 */
	protected static function sample_post_id() {
		$posts = get_posts( [
			'numberposts'      => 1,
			'post_type'        => array_keys( FW_SEO_Locations::post_types() ),
			'post_status'      => 'publish',
			'fields'           => 'ids',
			'suppress_filters' => false,
		] );

		return $posts ? (int) $posts[0] : 0;
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * @internal
	 */
	public function _action_enqueue() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$relevant = in_array( $screen->base, [ 'post', 'term', 'edit-tags', 'edit' ], true )
			|| false !== strpos( (string) $screen->id, 'fw-extensions' );

		if ( ! $relevant ) {
			return;
		}

		wp_enqueue_style(
			'fw-ext-seo-admin',
			$this->extension->get_declared_URI( '/static/css/admin.css' ),
			[],
			$this->extension->manifest->get_version()
		);

		// The inline editor only exists on a post list table, and only there is
		// `inline-edit-post` loaded for us to wrap.
		if ( 'edit' === $screen->base && isset( FW_SEO_Locations::post_types()[ (string) $screen->post_type ] ) ) {
			wp_enqueue_script(
				'fw-ext-seo-list',
				$this->extension->get_declared_URI( '/static/js/list.js' ),
				[ 'jquery', 'inline-edit-post' ],
				$this->extension->manifest->get_version(),
				true
			);
		}
	}
}
