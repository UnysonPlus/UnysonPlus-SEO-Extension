<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Image alt text — a site-wide fallback for images uploaded without any, and a
 * quick way to find and write the real thing.
 *
 * `alt` is how someone using a screen reader learns what an image shows. A
 * filled-in fallback (the image's title, its caption, the page it is on) beats
 * an empty attribute, and is worse than a sentence someone wrote. So the second
 * half matters as much: the Media Library gets a "Missing alt text" filter and
 * column, and the settings screen says how many images still need one.
 *
 * The fallback is only ever used for an image with no alt text of its own, and
 * never for one marked decorative (alt="" written on purpose in the editor is
 * indistinguishable from none, so content images with an explicit empty alt are
 * left alone).
 *
 * Nothing reads settings during startup (the option-types trap).
 *
 * @since 2.0.47
 */
class FW_SEO_Alt {

	const SOURCES = [ 'none', 'title', 'caption', 'post' ];

	/** Swapped for status_html() when the settings page is drawn. */
	const PLACEHOLDER = '<!--fw-seo-alt-status-->';

	public static function init() {
		add_filter( 'wp_get_attachment_image_attributes', [ __CLASS__, '_filter_attributes' ], 20, 2 );
		add_filter( 'wp_content_img_tag', [ __CLASS__, '_filter_content_img' ], 20, 3 );

		if ( is_admin() ) {
			add_filter( 'manage_media_columns', [ __CLASS__, '_column' ] );
			add_action( 'manage_media_custom_column', [ __CLASS__, '_column_value' ], 10, 2 );
			add_action( 'restrict_manage_posts', [ __CLASS__, '_filter_link' ] );
			add_action( 'pre_get_posts', [ __CLASS__, '_apply_filter' ] );
		}
	}

	/** @return string One of SOURCES. */
	public static function source() {
		$s = (string) FW_SEO_Settings::get( 'image_alt_fallback', 'none' );

		return in_array( $s, self::SOURCES, true ) ? $s : 'none';
	}

	/**
	 * The fallback text for an attachment, or ''.
	 *
	 * @param int    $attachment_id
	 * @param string $source
	 * @param int    $post_id The page the image appears on; 0 when unknown.
	 *
	 * @return string
	 */
	public static function fallback( $attachment_id, $source, $post_id = 0 ) {
		switch ( $source ) {
			case 'title':
				$text = get_the_title( (int) $attachment_id );
				// A file name the upload turned into a title says nothing ("IMG_4021", "dsc-0003").
				if ( preg_match( '#^(img|dsc|dscn|dcim|pxl|photo|image|screenshot|screen shot|whatsapp image)?[\s_-]*[\d\s_.-]+$#i', trim( (string) $text ) ) ) {
					$text = '';
				}
				break;
			case 'caption':
				$text = wp_get_attachment_caption( (int) $attachment_id );
				break;
			case 'post':
				$text = $post_id ? get_the_title( (int) $post_id ) : '';
				break;
			default:
				$text = '';
		}

		return trim( wp_strip_all_tags( (string) $text ) );
	}

	/** @internal */
	public static function _filter_attributes( $attr, $attachment ) {
		if ( isset( $attr['alt'] ) && '' !== trim( (string) $attr['alt'] ) ) {
			return $attr;
		}

		$source = self::source();
		if ( 'none' === $source ) {
			return $attr;
		}

		$text = self::fallback( $attachment->ID, $source, (int) get_the_ID() );
		if ( '' !== $text ) {
			$attr['alt'] = $text;
		}

		return $attr;
	}

	/** @internal */
	public static function _filter_content_img( $html, $context, $attachment_id ) {
		// An alt attribute written in the content, even an empty (decorative) one, is a decision.
		if ( ! $attachment_id || preg_match( '#\salt\s*=#i', $html ) ) {
			return $html;
		}

		$source = self::source();
		if ( 'none' === $source ) {
			return $html;
		}

		$text = self::fallback( (int) $attachment_id, $source, (int) get_the_ID() );

		return '' === $text ? $html : preg_replace( '#^<img\b#i', '<img alt="' . esc_attr( $text ) . '"', $html );
	}

	/* ------------------------------------------------------------------ *
	 * Writing the real thing
	 * ------------------------------------------------------------------ */

	/** @return int Images in the library with no alt text. */
	public static function missing_count() {
		global $wpdb;

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
			 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%' AND ( m.meta_value IS NULL OR m.meta_value = '' )" // phpcs:ignore WordPress.DB
		);
	}

	/** @return string The Media Library filtered to images without alt text. */
	public static function missing_url() {
		return admin_url( 'upload.php?mode=list&post_mime_type=image&fw_seo_alt=missing' );
	}

	/** @internal */
	public static function _column( $columns ) {
		$columns['fw_seo_alt'] = __( 'Alt text', 'fw' );

		return $columns;
	}

	/** @internal */
	public static function _column_value( $column, $post_id ) {
		if ( 'fw_seo_alt' !== $column || ! wp_attachment_is_image( $post_id ) ) {
			return;
		}

		$alt = (string) get_post_meta( $post_id, '_wp_attachment_image_alt', true );

		echo '' === $alt
			? '<span style="color:#b32d2e">' . esc_html__( 'Missing', 'fw' ) . '</span> · <a href="' . esc_url( (string) get_edit_post_link( $post_id ) ) . '">' . esc_html__( 'Write it', 'fw' ) . '</a>'
			: esc_html( $alt );
	}

	/** @internal */
	public static function _filter_link( $post_type ) {
		if ( 'attachment' !== $post_type ) {
			return;
		}

		$on = isset( $_GET['fw_seo_alt'] ); // phpcs:ignore WordPress.Security.NonceVerification
		echo '<a class="button" style="margin-left:4px" href="' . esc_url( $on ? admin_url( 'upload.php?mode=list' ) : self::missing_url() ) . '">'
			. esc_html( $on ? __( 'All images', 'fw' ) : __( 'Missing alt text', 'fw' ) ) . '</a>';
	}

	/** @internal */
	public static function _apply_filter( $query ) {
		if ( ! $query->is_main_query() || ! isset( $_GET['fw_seo_alt'] ) || 'attachment' !== $query->get( 'post_type' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$query->set( 'post_mime_type', 'image' );
		$query->set( 'meta_query', [
			'relation' => 'OR',
			[ 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ],
			[ 'key' => '_wp_attachment_image_alt', 'value' => '' ],
		] );
	}

	/** @return string The line on the settings screen. */
	public static function status_html() {
		$n = self::missing_count();

		if ( ! $n ) {
			return '<p>' . esc_html__( 'Every image in the Media Library has alt text.', 'fw' ) . '</p>';
		}

		return '<p>' . esc_html( sprintf(
			/* translators: %d: number of images */
			_n( '%d image in the Media Library has no alt text.', '%d images in the Media Library have no alt text.', $n, 'fw' ),
			$n
		) ) . ' <a class="button" href="' . esc_url( self::missing_url() ) . '">' . esc_html__( 'Write them', 'fw' ) . '</a></p>';
	}
}
