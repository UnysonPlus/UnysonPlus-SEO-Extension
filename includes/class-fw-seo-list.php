<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * SEO columns, filters and inline editing on the posts and terms list tables.
 *
 * This is the surface the storage design was chosen for. Overrides live in
 * discrete meta keys rather than the Unyson option blob precisely so WP_Query
 * can see them — without that, everything here would be impossible.
 *
 * What the columns show is the RESOLVED value, not the stored one. Showing
 * stored values would leave most rows blank on a correctly configured site,
 * because most pages quite properly have no override at all: the column would
 * read as "nothing set" for a site that is fully described.
 */
class FW_SEO_List {

	const NONCE_QUICK = 'fw_seo_quick_edit';

	/** Titles are measured in pixels elsewhere; the list is a rough guide. */
	const TITLE_CHARS = 60;

	/** @var FW_Extension_SEO */
	protected $extension;

	/**
	 * @param FW_Extension_SEO $extension
	 */
	public function __construct( $extension ) {
		$this->extension = $extension;

		add_action( 'admin_init', [ $this, '_action_register' ] );

		add_action( 'quick_edit_custom_box', [ $this, '_action_quick_edit_box' ], 10, 2 );
		add_action( 'save_post', [ $this, '_action_save_quick_edit' ], 10, 2 );

		add_action( 'restrict_manage_posts', [ $this, '_action_filter_dropdown' ] );
		add_action( 'pre_get_posts', [ $this, '_action_apply_filter' ] );
	}

	/**
	 * Columns are registered per post type and per taxonomy, on `admin_init` so
	 * the location registry is complete by the time we ask it what exists.
	 *
	 * @internal
	 */
	public function _action_register() {
		foreach ( array_keys( FW_SEO_Locations::post_types() ) as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", [ $this, '_filter_columns' ] );
			add_action( "manage_{$post_type}_posts_custom_column", [ $this, '_action_post_column' ], 10, 2 );
		}

		foreach ( array_keys( FW_SEO_Locations::taxonomies() ) as $taxonomy ) {
			add_filter( "manage_edit-{$taxonomy}_columns", [ $this, '_filter_columns' ] );
			add_filter( "manage_{$taxonomy}_custom_column", [ $this, '_filter_term_column' ], 10, 3 );
		}
	}

	/**
	 * @internal
	 *
	 * @param array $columns
	 *
	 * @return array
	 */
	public function _filter_columns( $columns ) {
		$columns['fw_seo_title']       = __( 'SEO title', 'fw' );
		$columns['fw_seo_description'] = __( 'Meta description', 'fw' );

		return $columns;
	}

	/**
	 * @internal
	 *
	 * @param string $column
	 * @param int    $post_id
	 */
	public function _action_post_column( $column, $post_id ) {
		if ( 0 !== strpos( $column, 'fw_seo_' ) ) {
			return;
		}

		echo $this->cell( $column, FW_SEO_Context::for_post( $post_id ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in cell().
	}

	/**
	 * @internal
	 *
	 * @param string $content
	 * @param string $column
	 * @param int    $term_id
	 *
	 * @return string
	 */
	public function _filter_term_column( $content, $column, $term_id ) {
		if ( 0 !== strpos( (string) $column, 'fw_seo_' ) ) {
			return $content;
		}

		$term = get_term( $term_id );

		if ( ! $term || is_wp_error( $term ) ) {
			return $content;
		}

		return $this->cell( $column, FW_SEO_Context::for_term( $term ) );
	}

	/**
	 * One cell: the resolved value, where it came from, and anything wrong with it.
	 *
	 * @param string         $column
	 * @param FW_SEO_Context $ctx
	 *
	 * @return string
	 */
	protected function cell( $column, FW_SEO_Context $ctx ) {
		$field = 'fw_seo_title' === $column ? 'title' : 'description';

		FW_SEO_Chain::flush( $ctx );

		$value  = FW_SEO_Chain::resolve( $field, $ctx );
		$source = FW_SEO_Chain::source( $field, $ctx );

		$html = '<div class="fw-seo-cell">';

		if ( '' === $value ) {
			$html .= '<span class="fw-seo-cell-empty">' . esc_html__( 'Nothing is emitted', 'fw' ) . '</span>';
		} else {
			$html .= '<span class="fw-seo-cell-value">' . esc_html( $value ) . '</span>';
		}

		$html .= '<span class="fw-seo-cell-meta">';
		$html .= '<span class="fw-seo-source fw-seo-source-' . esc_attr( $source ) . '">'
			. esc_html( $this->source_label( $source ) ) . '</span>';

		foreach ( $this->warnings( $field, $value ) as $warning ) {
			$html .= '<span class="fw-seo-warning">' . esc_html( $warning ) . '</span>';
		}

		$html .= '</span></div>';

		// The inline editor reads the STORED override from here, never the
		// resolved value above. Copying a resolved value into the form would
		// turn every quick-edited row into a frozen override of its own
		// template — the same trap the metabox avoids with its pristine
		// companions, arrived at from a different direction.
		if ( 'fw_seo_title' === $column ) {
			$html .= $this->inline_data( $ctx );
		}

		return $html;
	}

	/**
	 * @param string $source
	 *
	 * @return string
	 */
	protected function source_label( $source ) {
		switch ( $source ) {
			case 'override':
				return __( 'Custom', 'fw' );

			case 'template':
				return __( 'Template', 'fw' );

			case 'auto':
				return __( 'Generated', 'fw' );

			case 'fallback':
				return __( 'Fallback', 'fw' );

			default:
				return __( 'None', 'fw' );
		}
	}

	/**
	 * What is wrong with a resolved value, if anything.
	 *
	 * Length only — and stated as "may be shortened", because that is what
	 * actually happens. A long title is not an error, and calling it one trains
	 * people to write to a counter instead of to a reader.
	 *
	 * @param string $field
	 * @param string $value
	 *
	 * @return array<int,string>
	 */
	protected function warnings( $field, $value ) {
		if ( '' === $value ) {
			return [];
		}

		$warnings = [];

		if ( 'title' === $field && mb_strlen( $value ) > self::TITLE_CHARS ) {
			$warnings[] = __( 'May be shortened', 'fw' );
		}

		if ( 'description' === $field && mb_strlen( $value ) > fw_seo_description_limit() ) {
			$warnings[] = __( 'May be shortened', 'fw' );
		}

		return $warnings;
	}

	/**
	 * The stored overrides, for the inline editor to read.
	 *
	 * @param FW_SEO_Context $ctx
	 *
	 * @return string
	 */
	protected function inline_data( FW_SEO_Context $ctx ) {
		$stored = [
			'title'       => (string) FW_SEO_Store::get( $ctx, 'title' ),
			'description' => (string) FW_SEO_Store::get( $ctx, 'description' ),
			'noindex'     => FW_SEO_Store::get( $ctx, 'noindex' ) ? 1 : 0,
		];

		return '<script type="application/json" class="fw-seo-inline-data">'
			. wp_json_encode( $stored )
			. '</script>';
	}

	// -------------------------------------------------------------------------
	// Filtering
	// -------------------------------------------------------------------------

	/**
	 * The filters SQL can actually answer.
	 *
	 * Deliberately not "pages with no description". A description is resolved at
	 * render time — from an override, a template, or the page's own content — so
	 * "has no description" is not a stored fact and cannot be queried without
	 * first materialising every resolved value into an index. Offering the
	 * filter anyway would mean either a wrong answer or a query that loads the
	 * whole site into memory and breaks pagination.
	 *
	 * The per-row badges cover the visible page honestly; a site-wide answer
	 * needs the audit index on the roadmap.
	 *
	 * @return array<string,string>
	 */
	public static function filters() {
		return [
			'noindex'     => __( 'Not indexed', 'fw' ),
			'custom'      => __( 'Has custom SEO', 'fw' ),
			'no_custom'   => __( 'Following the template', 'fw' ),
			'no_social'   => __( 'No custom share image', 'fw' ),
		];
	}

	/**
	 * @internal
	 *
	 * @param string $post_type
	 */
	public function _action_filter_dropdown( $post_type ) {
		if ( ! isset( FW_SEO_Locations::post_types()[ $post_type ] ) ) {
			return;
		}

		$current = isset( $_GET['fw_seo_filter'] ) ? sanitize_key( wp_unslash( $_GET['fw_seo_filter'] ) ) : '';

		echo '<label class="screen-reader-text" for="fw_seo_filter">' . esc_html__( 'Filter by SEO', 'fw' ) . '</label>';
		echo '<select name="fw_seo_filter" id="fw_seo_filter">';
		echo '<option value="">' . esc_html__( 'All SEO', 'fw' ) . '</option>';

		foreach ( self::filters() as $key => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $key ),
				selected( $current, $key, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * @internal
	 *
	 * @param WP_Query $query
	 */
	public function _action_apply_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$filter = isset( $_GET['fw_seo_filter'] ) ? sanitize_key( wp_unslash( $_GET['fw_seo_filter'] ) ) : '';

		if ( '' === $filter || ! isset( self::filters()[ $filter ] ) ) {
			return;
		}

		$key = FW_SEO_Store::meta_key( 'title' );

		switch ( $filter ) {
			case 'noindex':
				$query->set( 'meta_query', [ [
					'key'   => FW_SEO_Store::meta_key( 'noindex' ),
					'value' => '1',
				] ] );
				break;

			case 'custom':
				// Either field counts as customised — someone who wrote a
				// description and left the title alone has still customised
				// this page, and hiding it under "following the template" is
				// the kind of wrong answer that makes a filter untrustworthy.
				$query->set( 'meta_query', [
					'relation' => 'OR',
					[ 'key' => $key, 'compare' => 'EXISTS' ],
					[ 'key' => FW_SEO_Store::meta_key( 'description' ), 'compare' => 'EXISTS' ],
				] );
				break;

			case 'no_custom':
				$query->set( 'meta_query', [
					'relation' => 'AND',
					[ 'key' => $key, 'compare' => 'NOT EXISTS' ],
					[ 'key' => FW_SEO_Store::meta_key( 'description' ), 'compare' => 'NOT EXISTS' ],
				] );
				break;

			case 'no_social':
				$query->set( 'meta_query', [ [
					'key'     => FW_SEO_Store::meta_key( 'og_image' ),
					'compare' => 'NOT EXISTS',
				] ] );
				break;
		}
	}

	// -------------------------------------------------------------------------
	// Inline editing
	// -------------------------------------------------------------------------

	/**
	 * @internal
	 *
	 * @param string $column
	 * @param string $post_type
	 */
	public function _action_quick_edit_box( $column, $post_type ) {
		if ( 'fw_seo_title' !== $column || ! isset( FW_SEO_Locations::post_types()[ $post_type ] ) ) {
			return;
		}

		wp_nonce_field( self::NONCE_QUICK, 'fw_seo_quick_nonce' );

		echo '<fieldset class="inline-edit-col-right fw-seo-quick"><div class="inline-edit-col">';
		echo '<span class="title">' . esc_html__( 'SEO', 'fw' ) . '</span>';

		echo '<label><span class="title">' . esc_html__( 'Title', 'fw' ) . '</span>';
		echo '<span class="input-text-wrap"><input type="text" name="fw_seo_title" value="" /></span></label>';

		echo '<label><span class="title">' . esc_html__( 'Description', 'fw' ) . '</span>';
		echo '<span class="input-text-wrap"><textarea name="fw_seo_description" rows="2"></textarea></span></label>';

		echo '<label class="alignleft"><input type="checkbox" name="fw_seo_noindex" value="1" />';
		echo '<span class="checkbox-title">' . esc_html__( 'No index', 'fw' ) . '</span></label>';

		echo '<p class="fw-seo-quick-hint">' . esc_html__( 'Leave a field empty to keep following the template.', 'fw' ) . '</p>';

		echo '</div></fieldset>';
	}

	/**
	 * Save an inline edit.
	 *
	 * A separate nonce and a separate handler from the metabox on purpose. The
	 * metabox save bails when its own nonce is absent, which is what stops a
	 * quick edit — a form that never rendered the SEO fields — from wiping every
	 * override on the post. Reusing that path here would mean weakening exactly
	 * the check that makes it safe.
	 *
	 * @internal
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public function _action_save_quick_edit( $post_id, $post ) {
		if ( ! isset( $_POST['fw_seo_quick_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['fw_seo_quick_nonce'] ) ), self::NONCE_QUICK ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! FW_SEO_Locations::has_post_type( $post->post_type ) ) {
			return;
		}

		foreach ( [ 'title', 'description' ] as $field ) {
			if ( ! isset( $_POST[ 'fw_seo_' . $field ] ) ) {
				continue;
			}

			// The store sanitises; an empty value deletes the row, which is
			// what rejoining the template means.
			FW_SEO_Store::set_post( $post_id, $field, wp_unslash( $_POST[ 'fw_seo_' . $field ] ) );
		}

		FW_SEO_Store::set_post( $post_id, 'noindex', ! empty( $_POST['fw_seo_noindex'] ) );
	}
}
