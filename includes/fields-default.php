<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The built-in fields, each expressed as its four stages.
 *
 * Note what is NOT here: any per-location branching. A field describes how to
 * find its value in general, and the context tells each stage which object it
 * is looking at. That is the whole point of the chain.
 */

// -----------------------------------------------------------------------------
// Title
// -----------------------------------------------------------------------------

FW_SEO_Chain::register_field( 'title', [
	'override' => function ( FW_SEO_Context $ctx ) {
		// An override may itself contain tags — someone templating a single
		// important page still wants %%sitename%% to resolve.
		return FW_SEO_Tags::render( (string) FW_SEO_Store::get( $ctx, 'title' ), $ctx );
	},
	'template' => function ( FW_SEO_Context $ctx ) {
		return FW_SEO_Tags::render( FW_SEO_Settings::template( 'title', $ctx ), $ctx );
	},
	'auto'     => function ( FW_SEO_Context $ctx ) {
		// Only reached when the template was emptied deliberately.
		return FW_SEO_Tags::render( '%%title%%', $ctx );
	},
	'fallback' => function () {
		return get_bloginfo( 'name' );
	},
] );

// -----------------------------------------------------------------------------
// Description
//
// This is the field the rebuild exists for. The old extension had no auto stage
// at all, so a site that had not hand-written a description on every post simply
// emitted no description tag anywhere.
// -----------------------------------------------------------------------------

FW_SEO_Chain::register_field( 'description', [
	'override' => function ( FW_SEO_Context $ctx ) {
		return FW_SEO_Tags::render( (string) FW_SEO_Store::get( $ctx, 'description' ), $ctx );
	},
	'template' => function ( FW_SEO_Context $ctx ) {
		return FW_SEO_Tags::render( FW_SEO_Settings::template( 'description', $ctx ), $ctx );
	},
	'auto'     => function ( FW_SEO_Context $ctx ) {
		if ( ! FW_SEO_Settings::flag( 'autogen_description', true ) ) {
			return '';
		}

		return fw_seo_truncate( fw_seo_auto_description( $ctx ), fw_seo_description_limit() );
	},
	'fallback' => function ( FW_SEO_Context $ctx ) {
		// Only the homepage gets the tagline as a last resort. Everywhere else,
		// no description is better than the same description on every page —
		// duplicate descriptions across a site are actively unhelpful.
		if ( ! $ctx->is( FW_SEO_Context::FRONT_PAGE ) ) {
			return '';
		}

		return (string) get_bloginfo( 'description' );
	},
] );

// -----------------------------------------------------------------------------
// Canonical
// -----------------------------------------------------------------------------

FW_SEO_Chain::register_field( 'canonical', [
	'override' => function ( FW_SEO_Context $ctx ) {
		return (string) FW_SEO_Store::get( $ctx, 'canonical' );
	},
	'auto'     => function ( FW_SEO_Context $ctx ) {
		if ( ! FW_SEO_Settings::flag( 'canonical_enabled', true ) ) {
			return '';
		}

		return fw_seo_canonical_url( $ctx );
	},
] );

// -----------------------------------------------------------------------------
// Social — Open Graph and Twitter
//
// Note how little is here. A social field is not a second copy of the title
// logic: its template stage IS the resolved `title` field, so a site-wide
// template change reaches the share card without the card knowing anything about
// templates. Twitter then falls back to Open Graph the same way, which is why
// the per-page panel can offer one set of fields and still emit both.
// -----------------------------------------------------------------------------

FW_SEO_Chain::register_field( 'og_title', [
	'override' => function ( FW_SEO_Context $ctx ) {
		return FW_SEO_Tags::render( (string) FW_SEO_Store::get( $ctx, 'og_title' ), $ctx );
	},
	'template' => function ( FW_SEO_Context $ctx ) {
		return FW_SEO_Chain::resolve( 'title', $ctx );
	},
] );

FW_SEO_Chain::register_field( 'og_description', [
	'override' => function ( FW_SEO_Context $ctx ) {
		return FW_SEO_Tags::render( (string) FW_SEO_Store::get( $ctx, 'og_description' ), $ctx );
	},
	'template' => function ( FW_SEO_Context $ctx ) {
		return FW_SEO_Chain::resolve( 'description', $ctx );
	},
] );

FW_SEO_Chain::register_field( 'og_image', [
	'override' => function ( FW_SEO_Context $ctx ) {
		return (string) FW_SEO_Store::get( $ctx, 'og_image' );
	},
	'auto'     => function ( FW_SEO_Context $ctx ) {
		return FW_SEO_Image::find( $ctx );
	},
	'fallback' => function () {
		// The picker stores an array; every reader downstream wants a URL.
		return FW_SEO_Image::url_from_value( FW_SEO_Settings::get( 'social_default_image', '' ) );
	},
] );

// Twitter's own fields exist so a page can say something different on one
// network — the common case is that it does not, and then these resolve to the
// Open Graph values without the user configuring anything.

FW_SEO_Chain::register_field( 'twitter_title', [
	'override' => function ( FW_SEO_Context $ctx ) {
		return FW_SEO_Tags::render( (string) FW_SEO_Store::get( $ctx, 'twitter_title' ), $ctx );
	},
	'template' => function ( FW_SEO_Context $ctx ) {
		return FW_SEO_Chain::resolve( 'og_title', $ctx );
	},
] );

FW_SEO_Chain::register_field( 'twitter_description', [
	'override' => function ( FW_SEO_Context $ctx ) {
		return FW_SEO_Tags::render( (string) FW_SEO_Store::get( $ctx, 'twitter_description' ), $ctx );
	},
	'template' => function ( FW_SEO_Context $ctx ) {
		return FW_SEO_Chain::resolve( 'og_description', $ctx );
	},
] );

FW_SEO_Chain::register_field( 'twitter_image', [
	'override' => function ( FW_SEO_Context $ctx ) {
		return (string) FW_SEO_Store::get( $ctx, 'twitter_image' );
	},
	'template' => function ( FW_SEO_Context $ctx ) {
		return FW_SEO_Chain::resolve( 'og_image', $ctx );
	},
] );
