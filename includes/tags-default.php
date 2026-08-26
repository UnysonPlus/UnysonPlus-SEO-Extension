<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The built-in %%tags%%.
 *
 * Loaded once by FW_SEO_Tags::boot(). Each resolver receives the context and
 * returns a plain string; returning '' lets the renderer collapse the tag and
 * its separator away, which is usually what you want for anything optional.
 *
 * Dates go through wp_date(), never date() — the site timezone and the site
 * locale both matter here, and the old extension honoured neither.
 */

// -----------------------------------------------------------------------------
// Site
// -----------------------------------------------------------------------------

FW_SEO_Tags::register( 'sitename', [
	'label'   => __( 'Site title', 'fw' ),
	'desc'    => __( 'The site title from Settings → General.', 'fw' ),
	'group'   => 'site',
	'resolve' => function () {
		return get_bloginfo( 'name' );
	},
] );

FW_SEO_Tags::register( 'sitedesc', [
	'label'   => __( 'Tagline', 'fw' ),
	'desc'    => __( 'The site tagline from Settings → General.', 'fw' ),
	'group'   => 'site',
	'resolve' => function () {
		return get_bloginfo( 'description' );
	},
] );

FW_SEO_Tags::register( 'sep', [
	'label'   => __( 'Separator', 'fw' ),
	'desc'    => __( 'The separator character chosen in the SEO settings.', 'fw' ),
	'group'   => 'site',
	'resolve' => function () {
		return fw_seo_separator();
	},
] );

FW_SEO_Tags::register( 'permalink', [
	'label'   => __( 'Permalink', 'fw' ),
	'desc'    => __( 'The URL of the current page.', 'fw' ),
	'group'   => 'site',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		return $ctx->url();
	},
] );

FW_SEO_Tags::register( 'currentdate', [
	'label'   => __( 'Current date', 'fw' ),
	'desc'    => __( 'Today, in the site date format.', 'fw' ),
	'group'   => 'site',
	'resolve' => function () {
		return wp_date( (string) get_option( 'date_format' ) );
	},
] );

FW_SEO_Tags::register( 'currenttime', [
	'label'   => __( 'Current time', 'fw' ),
	'desc'    => __( 'The current time, in the site time format.', 'fw' ),
	'group'   => 'site',
	'resolve' => function () {
		return wp_date( (string) get_option( 'time_format' ) );
	},
] );

FW_SEO_Tags::register( 'currentday', [
	'label'   => __( 'Current day', 'fw' ),
	'desc'    => __( 'The current day of the month.', 'fw' ),
	'group'   => 'site',
	'resolve' => function () {
		return wp_date( 'j' );
	},
] );

FW_SEO_Tags::register( 'currentmonth', [
	'label'   => __( 'Current month', 'fw' ),
	'desc'    => __( 'The current month name, translated.', 'fw' ),
	'group'   => 'site',
	'resolve' => function () {
		return wp_date( 'F' );
	},
] );

FW_SEO_Tags::register( 'currentyear', [
	'label'   => __( 'Current year', 'fw' ),
	'desc'    => __( 'The current year — handy for keeping a title evergreen.', 'fw' ),
	'group'   => 'site',
	'resolve' => function () {
		return wp_date( 'Y' );
	},
] );

// -----------------------------------------------------------------------------
// The current post
// -----------------------------------------------------------------------------

FW_SEO_Tags::register( 'title', [
	'label'   => __( 'Title', 'fw' ),
	'desc'    => __( 'Title of the current post, page or term.', 'fw' ),
	'group'   => 'post',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		if ( $ctx->has_post() ) {
			return get_the_title( $ctx->post() );
		}

		if ( $ctx->term() ) {
			return $ctx->term()->name;
		}

		return fw_seo_archive_title( $ctx );
	},
] );

FW_SEO_Tags::register( 'excerpt', [
	'label'   => __( 'Excerpt', 'fw' ),
	'desc'    => __( 'The post excerpt, generated from the content when none is set.', 'fw' ),
	'group'   => 'post',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		return FW_SEO_Content::excerpt( $ctx, true );
	},
] );

FW_SEO_Tags::register( 'excerpt_only', [
	'label'   => __( 'Excerpt (manual only)', 'fw' ),
	'desc'    => __( 'The hand-written excerpt. Empty when the post has none.', 'fw' ),
	'group'   => 'post',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		return FW_SEO_Content::excerpt( $ctx, false );
	},
] );

FW_SEO_Tags::register( 'post_content', [
	'label'   => __( 'Content', 'fw' ),
	'desc'    => __( 'The body text of the post, stripped to plain prose.', 'fw' ),
	'group'   => 'post',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		return FW_SEO_Content::text( $ctx );
	},
] );

FW_SEO_Tags::register( 'date', [
	'label'   => __( 'Published date', 'fw' ),
	'desc'    => __( 'Publish date of the post, in the site date format.', 'fw' ),
	'group'   => 'post',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		return $ctx->has_post() ? (string) get_the_date( '', $ctx->post() ) : '';
	},
] );

FW_SEO_Tags::register( 'modified', [
	'label'   => __( 'Modified date', 'fw' ),
	'desc'    => __( 'Last modified date of the post.', 'fw' ),
	'group'   => 'post',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		return $ctx->has_post() ? (string) get_the_modified_date( '', $ctx->post() ) : '';
	},
] );

FW_SEO_Tags::register( 'id', [
	'label'   => __( 'Post ID', 'fw' ),
	'desc'    => __( 'Numeric ID of the current post.', 'fw' ),
	'group'   => 'post',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		return $ctx->post_id() ? (string) $ctx->post_id() : '';
	},
] );

FW_SEO_Tags::register( 'parent_title', [
	'label'   => __( 'Parent title', 'fw' ),
	'desc'    => __( 'Title of the parent page. Empty at the top level.', 'fw' ),
	'group'   => 'post',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		$post = $ctx->post();

		if ( ! $post || ! $post->post_parent ) {
			return '';
		}

		return get_the_title( (int) $post->post_parent );
	},
] );

FW_SEO_Tags::register( 'post_type_singular', [
	'label'   => __( 'Post type (singular)', 'fw' ),
	'desc'    => __( 'Singular label of the post type, e.g. "Project".', 'fw' ),
	'group'   => 'post',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		$object = $ctx->post_type() ? get_post_type_object( $ctx->post_type() ) : null;

		return $object ? (string) $object->labels->singular_name : '';
	},
] );

FW_SEO_Tags::register( 'post_type_plural', [
	'label'   => __( 'Post type (plural)', 'fw' ),
	'desc'    => __( 'Plural label of the post type, e.g. "Projects".', 'fw' ),
	'group'   => 'post',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		$object = $ctx->post_type() ? get_post_type_object( $ctx->post_type() ) : null;

		return $object ? (string) $object->labels->name : '';
	},
] );

FW_SEO_Tags::register( 'primary_category', [
	'label'   => __( 'Primary category', 'fw' ),
	'desc'    => __( 'The post\'s primary category, or its first category.', 'fw' ),
	'group'   => 'post',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		$term = fw_seo_primary_term( $ctx->post_id(), 'category' );

		return $term ? $term->name : '';
	},
] );

FW_SEO_Tags::register( 'post_categories', [
	'label'   => __( 'Categories', 'fw' ),
	'desc'    => __( 'All categories of the post, comma separated.', 'fw' ),
	'group'   => 'post',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		return fw_seo_term_names( $ctx->post_id(), 'category' );
	},
] );

FW_SEO_Tags::register( 'post_tags', [
	'label'   => __( 'Tags', 'fw' ),
	'desc'    => __( 'All tags of the post, comma separated.', 'fw' ),
	'group'   => 'post',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		return fw_seo_term_names( $ctx->post_id(), 'post_tag' );
	},
] );

// -----------------------------------------------------------------------------
// Terms and archives
// -----------------------------------------------------------------------------

FW_SEO_Tags::register( 'term_title', [
	'label'    => __( 'Term name', 'fw' ),
	'desc'     => __( 'Name of the current category, tag or term.', 'fw' ),
	'group'    => 'term',
	'contexts' => [ FW_SEO_Context::TERM ],
	'resolve'  => function ( FW_SEO_Context $ctx ) {
		return $ctx->term() ? $ctx->term()->name : '';
	},
] );

FW_SEO_Tags::register( 'term_description', [
	'label'    => __( 'Term description', 'fw' ),
	'desc'     => __( 'Description of the current category, tag or term.', 'fw' ),
	'group'    => 'term',
	'contexts' => [ FW_SEO_Context::TERM ],
	'resolve'  => function ( FW_SEO_Context $ctx ) {
		return $ctx->term() ? $ctx->term()->description : '';
	},
] );

FW_SEO_Tags::register( 'taxonomy_title', [
	'label'    => __( 'Taxonomy name', 'fw' ),
	'desc'     => __( 'Label of the taxonomy, e.g. "Categories".', 'fw' ),
	'group'    => 'term',
	'contexts' => [ FW_SEO_Context::TERM ],
	'resolve'  => function ( FW_SEO_Context $ctx ) {
		$object = $ctx->taxonomy() ? get_taxonomy( $ctx->taxonomy() ) : null;

		return $object ? (string) $object->labels->name : '';
	},
] );

FW_SEO_Tags::register( 'archive_title', [
	'label'   => __( 'Archive title', 'fw' ),
	'desc'    => __( 'The title WordPress would give this archive.', 'fw' ),
	'group'   => 'archive',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		return fw_seo_archive_title( $ctx );
	},
] );

FW_SEO_Tags::register( 'archive_date', [
	'label'    => __( 'Archive date', 'fw' ),
	'desc'     => __( 'The period a date archive covers, e.g. "March 2026".', 'fw' ),
	'group'    => 'archive',
	'contexts' => [ FW_SEO_Context::DATE ],
	'resolve'  => function ( FW_SEO_Context $ctx ) {
		return fw_seo_archive_title( $ctx );
	},
] );

// -----------------------------------------------------------------------------
// Author
// -----------------------------------------------------------------------------

FW_SEO_Tags::register( 'author_name', [
	'label'   => __( 'Author name', 'fw' ),
	'desc'    => __( 'Display name of the author.', 'fw' ),
	'group'   => 'author',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		$user = $ctx->user();

		return $user ? (string) $user->display_name : '';
	},
] );

FW_SEO_Tags::register( 'author_bio', [
	'label'   => __( 'Author bio', 'fw' ),
	'desc'    => __( 'The author\'s biographical info.', 'fw' ),
	'group'   => 'author',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		$user = $ctx->user();

		return $user ? (string) $user->description : '';
	},
] );

FW_SEO_Tags::register( 'author_id', [
	'label'   => __( 'Author ID', 'fw' ),
	'desc'    => __( 'Numeric ID of the author.', 'fw' ),
	'group'   => 'author',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		$user = $ctx->user();

		return $user ? (string) $user->ID : '';
	},
] );

// -----------------------------------------------------------------------------
// Search and paging
// -----------------------------------------------------------------------------

FW_SEO_Tags::register( 'searchphrase', [
	'label'    => __( 'Search phrase', 'fw' ),
	'desc'     => __( 'What the visitor searched for.', 'fw' ),
	'group'    => 'search',
	'contexts' => [ FW_SEO_Context::SEARCH ],
	'resolve'  => function ( FW_SEO_Context $ctx ) {
		return $ctx->search_query();
	},
] );

FW_SEO_Tags::register( 'page', [
	'label'   => __( 'Page X of Y', 'fw' ),
	'desc'    => __( 'Renders "Page 2 of 7" — and nothing at all on page one, so it collapses away with its separator.', 'fw' ),
	'group'   => 'paging',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		if ( ! $ctx->is_paged() || $ctx->max_page() < 2 ) {
			return '';
		}

		/* translators: 1: current page number, 2: total number of pages. */
		return sprintf( __( 'Page %1$d of %2$d', 'fw' ), $ctx->page(), $ctx->max_page() );
	},
] );

FW_SEO_Tags::register( 'pagenumber', [
	'label'   => __( 'Page number', 'fw' ),
	'desc'    => __( 'The current page number. Empty on page one.', 'fw' ),
	'group'   => 'paging',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		return $ctx->is_paged() ? (string) $ctx->page() : '';
	},
] );

FW_SEO_Tags::register( 'pagetotal', [
	'label'   => __( 'Total pages', 'fw' ),
	'desc'    => __( 'How many pages this archive or post has.', 'fw' ),
	'group'   => 'paging',
	'resolve' => function ( FW_SEO_Context $ctx ) {
		return $ctx->max_page() > 1 ? (string) $ctx->max_page() : '';
	},
] );

// -----------------------------------------------------------------------------
// Dynamic families — one registration each, unlimited tags
// -----------------------------------------------------------------------------

FW_SEO_Tags::register_family( 'cf_', [
	'label'       => __( 'Custom field', 'fw' ),
	'desc'        => __( 'Any custom field of the current post — %%cf_subtitle%% reads the "subtitle" field.', 'fw' ),
	'group'       => 'dynamic',
	'placeholder' => 'cf_field_name',
	'resolve'     => function ( FW_SEO_Context $ctx, $param ) {
		if ( ! $ctx->post_id() || '' === $param ) {
			return '';
		}

		return fw_seo_scalar( get_post_meta( $ctx->post_id(), $param, true ) );
	},
] );

FW_SEO_Tags::register_family( 'term_cf_', [
	'label'       => __( 'Term custom field', 'fw' ),
	'desc'        => __( 'Any meta field of the current term — %%term_cf_colour%% reads the "colour" field.', 'fw' ),
	'group'       => 'dynamic',
	'placeholder' => 'term_cf_field_name',
	'resolve'     => function ( FW_SEO_Context $ctx, $param ) {
		if ( ! $ctx->term_id() || '' === $param ) {
			return '';
		}

		return fw_seo_scalar( get_term_meta( $ctx->term_id(), $param, true ) );
	},
] );

FW_SEO_Tags::register_family( 'tax_', [
	'label'       => __( 'Taxonomy terms', 'fw' ),
	'desc'        => __( 'The post\'s terms in any taxonomy, comma separated — %%tax_product_cat%%.', 'fw' ),
	'group'       => 'dynamic',
	'placeholder' => 'tax_taxonomy_name',
	'resolve'     => function ( FW_SEO_Context $ctx, $param ) {
		if ( ! $ctx->post_id() || '' === $param ) {
			return '';
		}

		return fw_seo_term_names( $ctx->post_id(), $param );
	},
] );

FW_SEO_Tags::register_family( 'user_', [
	'label'       => __( 'Author field', 'fw' ),
	'desc'        => __( 'Any meta field of the author — %%user_twitter%% reads their "twitter" field.', 'fw' ),
	'group'       => 'dynamic',
	'placeholder' => 'user_field_name',
	'resolve'     => function ( FW_SEO_Context $ctx, $param ) {
		$user = $ctx->user();

		if ( ! $user || '' === $param ) {
			return '';
		}

		return fw_seo_scalar( get_user_meta( $user->ID, $param, true ) );
	},
] );
