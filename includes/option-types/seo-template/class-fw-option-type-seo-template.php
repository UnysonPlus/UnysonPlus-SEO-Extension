<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

if ( ! class_exists( 'FW_Option_Type_SEO_Template' ) ) :

	/**
	 * A template field: a text input plus the affordances that make %%tags%%
	 * usable by someone who does not know they exist.
	 *
	 * Quick-insert buttons for the tags that matter in this field, a searchable
	 * browser for the rest, and a counter measured on the RESOLVED text — the
	 * length of "%%title%% %%sep%% %%sitename%%" is not a useful number, and
	 * showing it as one is worse than showing nothing.
	 *
	 * Config keys beyond the defaults:
	 *   field      'title' | 'description' — which chain field this edits.
	 *   multiline  bool    — render a textarea instead of an input.
	 *   measure    'pixels' | 'characters' — how the counter counts.
	 *   limit      int     — the recommended maximum.
	 *   insert     array   — tag ids offered as quick-insert buttons.
	 */
	class FW_Option_Type_SEO_Template extends FW_Option_Type {

		/** @var bool Shared payload is localized once per request. */
		protected static $localized = false;

		public function get_type() {
			return 'seo-template';
		}

		/**
		 * @internal
		 */
		protected function _get_defaults() {
			return [
				'value'     => '',
				'field'     => 'title',
				'multiline' => false,
				'measure'   => 'characters',
				'limit'     => 0,
				'insert'    => [],
			];
		}

		/**
		 * @internal
		 */
		public function _get_backend_width_type() {
			return 'fixed';
		}

		/**
		 * @internal
		 */
		protected function _enqueue_static( $id, $option, $data ) {
			$extension = fw()->extensions->get( 'seo' );

			if ( ! $extension ) {
				return;
			}

			$version = $extension->manifest->get_version();

			wp_enqueue_style(
				'fw-option-seo-template',
				$extension->get_declared_URI( '/includes/option-types/seo-template/static/css/style.css' ),
				[],
				$version
			);

			wp_enqueue_script(
				'fw-option-seo-template',
				$extension->get_declared_URI( '/includes/option-types/seo-template/static/js/scripts.js' ),
				[ 'jquery' ],
				$version,
				true
			);

			if ( ! self::$localized ) {
				self::$localized = true;

				wp_localize_script( 'fw-option-seo-template', 'fwSeoData', self::get_js_data() );
			}
		}

		/**
		 * The tag catalogue, the AJAX handle, and the strings the control needs.
		 *
		 * @return array
		 */
		protected static function get_js_data() {
			$post_id = self::current_post_id();
			$ctx     = $post_id ? FW_SEO_Context::for_post( $post_id ) : null;

			return [
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( FW_Extension_SEO::NONCE_PREVIEW ),
				'postId'   => $post_id,
				'tags'     => FW_SEO_Tags::browse( $ctx ),
				'groups'   => [
					'site'    => __( 'Site', 'fw' ),
					'post'    => __( 'Post', 'fw' ),
					'term'    => __( 'Term', 'fw' ),
					'author'  => __( 'Author', 'fw' ),
					'archive' => __( 'Archive', 'fw' ),
					'search'  => __( 'Search', 'fw' ),
					'paging'  => __( 'Paging', 'fw' ),
					'dynamic' => __( 'Dynamic fields', 'fw' ),
				],
				'l10n'     => [
					'allTags'     => __( 'View all tags', 'fw' ),
					'browseTitle' => __( 'Insert a tag', 'fw' ),
					'search'      => __( 'Search tags…', 'fw' ),
					'close'       => __( 'Close', 'fw' ),
					'noResults'   => __( 'No tags match that search.', 'fw' ),
					'example'     => __( 'Example', 'fw' ),
					/* translators: 1: measured length, 2: recommended maximum. */
					'pixels'      => __( '%1$s out of %2$s max recommended pixels.', 'fw' ),
					/* translators: 1: measured length, 2: recommended maximum. */
					'characters'  => __( '%1$s out of %2$s max recommended characters.', 'fw' ),
					'sourceLabel' => [
						'override' => __( 'Your text', 'fw' ),
						'template' => __( 'From the template', 'fw' ),
						'auto'     => __( 'Generated from the content', 'fw' ),
						'fallback' => __( 'Fallback', 'fw' ),
						'none'     => __( 'Nothing to show', 'fw' ),
					],
				],
				'defaults' => [
					'separator' => fw_seo_separator(),
				],
			];
		}

		/**
		 * The post being edited, when there is one.
		 *
		 * @return int
		 */
		protected static function current_post_id() {
			if ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return (int) $_GET['post']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}

			$post = get_post();

			return $post instanceof WP_Post ? (int) $post->ID : 0;
		}

		/**
		 * @internal
		 */
		protected function _render( $id, $option, $data ) {
			$attr = $option['attr'];

			$attr['class']            = trim( ( $attr['class'] ?? '' ) . ' fw-seo-template-input' );
			$attr['data-seo-field']   = (string) $option['field'];
			$attr['data-seo-measure'] = (string) $option['measure'];
			$attr['data-seo-limit']   = (string) (int) $option['limit'];
			$attr['autocomplete']     = 'off';

			$value = is_scalar( $data['value'] ) ? (string) $data['value'] : '';

			if ( $option['multiline'] ) {
				unset( $attr['value'] );

				$attr['rows'] = $attr['rows'] ?? '3';

				$control = '<textarea ' . fw_attr_to_html( $attr ) . '>'
					. htmlspecialchars( $value, ENT_COMPAT, 'UTF-8' )
					. '</textarea>';
			} else {
				$attr['value'] = $value;

				$control = '<input ' . fw_attr_to_html( $attr ) . ' type="text" />';
			}

			return '<div class="fw-seo-template">'
				. $this->render_toolbar( $option )
				. $control
				. $this->render_counter( $option )
				. '</div>';
		}

		/**
		 * @param array $option
		 *
		 * @return string
		 */
		protected function render_toolbar( array $option ) {
			$buttons = $option['insert'];

			if ( ! $buttons ) {
				$buttons = 'description' === $option['field']
					? [ 'excerpt', 'sep', 'sitename' ]
					: [ 'title', 'sep', 'sitename' ];
			}

			$html = '<div class="fw-seo-template-toolbar">';

			foreach ( $buttons as $tag_id ) {
				$html .= sprintf(
					'<button type="button" class="button button-small fw-seo-insert" data-tag="%s">+ %s</button>',
					esc_attr( '%%' . $tag_id . '%%' ),
					esc_html( self::tag_label( $tag_id ) )
				);
			}

			$html .= '<button type="button" class="button-link fw-seo-browse">'
				. esc_html__( 'View all tags', 'fw' ) . ' &rarr;</button>';

			return $html . '</div>';
		}

		/**
		 * @param string $tag_id
		 *
		 * @return string
		 */
		protected static function tag_label( $tag_id ) {
			foreach ( FW_SEO_Tags::browse() as $row ) {
				if ( $row['tag'] === '%%' . $tag_id . '%%' ) {
					return $row['label'];
				}
			}

			return $tag_id;
		}

		/**
		 * @param array $option
		 *
		 * @return string
		 */
		protected function render_counter( array $option ) {
			if ( (int) $option['limit'] < 1 ) {
				return '';
			}

			return '<div class="fw-seo-template-counter" aria-live="polite"></div>';
		}

		/**
		 * @internal
		 */
		protected function _get_value_from_input( $option, $input_value ) {
			if ( is_null( $input_value ) ) {
				return (string) $option['value'];
			}

			// A template ends up inside a <title> or a meta attribute, so markup
			// in it is never meaningful — strip rather than escape, so the user
			// does not end up staring at &lt;b&gt; in a search result.
			return trim( wp_strip_all_tags( (string) $input_value, true ) );
		}
	}

	FW_Option_Type::register( 'FW_Option_Type_SEO_Template' );

endif;
