<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Attachment pages — what happens to the page WordPress makes for every upload.
 *
 * Each uploaded file gets its own page: a title, the image, and little else.
 * They are thin, near-duplicate pages, and search engines index them anyway.
 *
 * WordPress 6.4 added `wp_attachment_pages_enabled`, which redirects them to the
 * file. But it is OFF only on sites installed after 6.4 — every older site that
 * upgraded kept the pages — and there is no screen to change it. This puts the
 * choice on the SEO settings, with one option core lacks: send the visitor to
 * the post the file belongs to, which is usually what they wanted.
 *
 * Nothing here runs before `wp`. Core reads the option during `init` (for a menu
 * label), and reading SEO settings that early initialises the option types
 * before this extension has registered its own — the trap that made editor
 * saves parse as empty. Hooking on `wp` means it is only ever consulted for a
 * real front-end request that is actually an attachment.
 *
 * @since 2.0.37
 */
class FW_SEO_Attachments {

	/** The modes, in the order the setting offers them. */
	const MODES = [ 'core', 'parent', 'file', 'keep' ];

	public static function init() {
		add_action( 'wp', [ __CLASS__, '_on_wp' ] );
	}

	/**
	 * @return string One of MODES.
	 */
	public static function mode() {
		$mode = class_exists( 'FW_SEO_Settings' ) ? (string) FW_SEO_Settings::get( 'attachment_pages', 'core' ) : 'core';

		return in_array( $mode, self::MODES, true ) ? $mode : 'core';
	}

	/**
	 * What core's option should read as for a mode, or null to leave it alone.
	 *
	 * @param string $mode
	 *
	 * @return string|null
	 */
	public static function core_value( $mode ) {
		switch ( $mode ) {
			case 'keep':
				return '1';
			case 'file':
			case 'parent':
				// For "parent", the file is the fallback when there is no
				// published parent, and core's own redirect does that part.
				return '0';
			default:
				return null;
		}
	}

	/**
	 * Where "parent" mode sends an attachment, or '' when it has no published parent.
	 *
	 * @param int $attachment_id
	 *
	 * @return string
	 */
	public static function parent_url( $attachment_id ) {
		$parent = (int) wp_get_post_parent_id( (int) $attachment_id );

		if ( ! $parent || 'publish' !== get_post_status( $parent ) ) {
			return '';
		}

		return (string) get_permalink( $parent );
	}

	/** @internal */
	public static function _on_wp() {
		if ( is_admin() || ! is_attachment() ) {
			return;
		}

		$mode  = self::mode();
		$value = self::core_value( $mode );

		if ( null === $value ) {
			return;
		}

		add_filter( 'pre_option_wp_attachment_pages_enabled', static function () use ( $value ) {
			return $value;
		} );

		if ( 'parent' === $mode ) {
			// Before core's canonical redirect (priority 10), which would send
			// the visitor to the file instead.
			add_action( 'template_redirect', [ __CLASS__, '_redirect_to_parent' ], 1 );
		}
	}

	/** @internal */
	public static function _redirect_to_parent() {
		$url = self::parent_url( (int) get_queried_object_id() );

		if ( '' === $url ) {
			return; // No published parent: core sends it to the file.
		}

		wp_safe_redirect( $url, 301, 'Unyson+ SEO' );
		exit;
	}

	/**
	 * A sentence describing what the site does today under "core", for the
	 * setting's description — so "leave it to WordPress" is not a guess.
	 *
	 * @return string
	 */
	public static function core_state_text() {
		return '1' === (string) get_option( 'wp_attachment_pages_enabled' )
			? __( 'On this site WordPress currently KEEPS attachment pages (it was set up before WordPress 6.4).', 'fw' )
			: __( 'On this site WordPress currently sends attachment pages to the file.', 'fw' );
	}
}
