<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

if ( ! class_exists( 'FW_Option_Type_SEO_Preview' ) ) :

	/**
	 * The search-result preview.
	 *
	 * Holds no value of its own — it is a display surface that the template
	 * fields in the same form write into. Rendered as a real option rather than
	 * echoed markup so it sits inside the options layout instead of beside it.
	 */
	class FW_Option_Type_SEO_Preview extends FW_Option_Type {

		public function get_type() {
			return 'seo-preview';
		}

		/**
		 * @internal
		 */
		protected function _get_defaults() {
			return [
				'value' => '',
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
			// The preview is driven entirely by the template control's script,
			// so it deliberately shares that handle rather than shipping a
			// second copy of the same logic.
			fw()->backend->option_type( 'seo-template' )->enqueue_static();
		}

		/**
		 * @internal
		 */
		protected function _render( $id, $option, $data ) {
			$url = home_url( '/' );

			return '<div class="fw-seo-preview" data-seo-preview>'
				. '<div class="fw-seo-preview-head">'
				. '<span class="fw-seo-preview-label">' . esc_html__( 'Search result preview', 'fw' ) . '</span>'
				. '<span class="fw-seo-preview-modes">'
				. '<button type="button" class="fw-seo-preview-mode is-active" data-mode="desktop">'
				. esc_html__( 'Desktop', 'fw' ) . '</button>'
				. '<button type="button" class="fw-seo-preview-mode" data-mode="mobile">'
				. esc_html__( 'Mobile', 'fw' ) . '</button>'
				. '</span>'
				. '</div>'
				. '<div class="fw-seo-preview-card is-desktop">'
				. '<div class="fw-seo-preview-url">' . esc_html( $url ) . '</div>'
				. '<div class="fw-seo-preview-title"></div>'
				. '<div class="fw-seo-preview-description"></div>'
				. '</div>'
				. '<div class="fw-seo-preview-sources"></div>'
				. '</div>';
		}

		/**
		 * @internal
		 */
		protected function _get_value_from_input( $option, $input_value ) {
			// Display only — never contributes a value, so nothing to store.
			return null;
		}
	}

	FW_Option_Type::register( 'FW_Option_Type_SEO_Preview' );

endif;
