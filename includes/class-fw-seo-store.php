<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Where per-post and per-term SEO overrides live.
 *
 * Discrete meta keys, one per field, rather than a single serialised blob. The
 * blob is cheaper to write and impossible to query: a bulk editor, list-table
 * columns and a sitemap that filters out noindexed posts all need WP_Query to
 * be able to see these values, and a serialised array inside another option's
 * aggregate cannot be queried at all.
 *
 * There is deliberately no migration from the 1.0.x storage.
 */
class FW_SEO_Store {

	const PREFIX = '_fw_seo_';

	/**
	 * The fields an override may set, and how each is sanitised on the way in.
	 *
	 * @return array<string,string> field => sanitiser key
	 */
	public static function fields() {
		return [
			'title'            => 'text',
			'description'      => 'text',
			'canonical'        => 'url',
			'noindex'          => 'bool',
			'nofollow'         => 'bool',
			'robots_advanced'  => 'list',
			'max_snippet'      => 'int',
			'max_image_preview' => 'choice',
			'max_video_preview' => 'int',

			// Social. The image fields hold a URL: the picker posts an array,
			// and reducing it here keeps every reader downstream dealing with
			// one shape.
			'og_title'            => 'text',
			'og_description'      => 'text',
			'og_image'            => 'image',
			'twitter_title'       => 'text',
			'twitter_description' => 'text',
			'twitter_image'       => 'image',
			'twitter_card'        => 'choice',
		];
	}

	/**
	 * @param string $field
	 *
	 * @return string
	 */
	public static function meta_key( $field ) {
		return self::PREFIX . $field;
	}

	/**
	 * Read one override for a post.
	 *
	 * @param int    $post_id
	 * @param string $field
	 *
	 * @return mixed '' / false / [] when unset, per the field's type.
	 */
	public static function get_post( $post_id, $field ) {
		$post_id = (int) $post_id;

		if ( ! $post_id || ! isset( self::fields()[ $field ] ) ) {
			return self::empty_value( $field );
		}

		$value = get_post_meta( $post_id, self::meta_key( $field ), true );

		return self::normalize( $field, $value );
	}

	/**
	 * Read one override for a term.
	 *
	 * @param int    $term_id
	 * @param string $field
	 *
	 * @return mixed
	 */
	public static function get_term( $term_id, $field ) {
		$term_id = (int) $term_id;

		if ( ! $term_id || ! isset( self::fields()[ $field ] ) ) {
			return self::empty_value( $field );
		}

		$value = get_term_meta( $term_id, self::meta_key( $field ), true );

		return self::normalize( $field, $value );
	}

	/**
	 * Read an override from whichever object the context is about.
	 *
	 * @param FW_SEO_Context $ctx
	 * @param string         $field
	 *
	 * @return mixed
	 */
	public static function get( FW_SEO_Context $ctx, $field ) {
		if ( $ctx->term_id() ) {
			return self::get_term( $ctx->term_id(), $field );
		}

		if ( $ctx->post_id() ) {
			return self::get_post( $ctx->post_id(), $field );
		}

		return self::empty_value( $field );
	}

	/**
	 * Write one override for a post. An empty value deletes the row rather than
	 * storing '' — an absent key and "the user cleared this" mean the same thing
	 * here, and not storing empties keeps the meta table clean.
	 *
	 * @param int    $post_id
	 * @param string $field
	 * @param mixed  $value
	 */
	public static function set_post( $post_id, $field, $value ) {
		$post_id = (int) $post_id;

		if ( ! $post_id || ! isset( self::fields()[ $field ] ) ) {
			return;
		}

		$value = self::sanitize( $field, $value );

		if ( self::is_empty( $value ) ) {
			delete_post_meta( $post_id, self::meta_key( $field ) );

			return;
		}

		update_post_meta( $post_id, self::meta_key( $field ), $value );
	}

	/**
	 * @param int    $term_id
	 * @param string $field
	 * @param mixed  $value
	 */
	public static function set_term( $term_id, $field, $value ) {
		$term_id = (int) $term_id;

		if ( ! $term_id || ! isset( self::fields()[ $field ] ) ) {
			return;
		}

		$value = self::sanitize( $field, $value );

		if ( self::is_empty( $value ) ) {
			delete_term_meta( $term_id, self::meta_key( $field ) );

			return;
		}

		update_term_meta( $term_id, self::meta_key( $field ), $value );
	}

	/**
	 * Every override for a post, as a field => value map.
	 *
	 * @param int $post_id
	 *
	 * @return array
	 */
	public static function all_post( $post_id ) {
		$values = [];

		foreach ( array_keys( self::fields() ) as $field ) {
			$values[ $field ] = self::get_post( $post_id, $field );
		}

		return $values;
	}

	/**
	 * @param int $term_id
	 *
	 * @return array
	 */
	public static function all_term( $term_id ) {
		$values = [];

		foreach ( array_keys( self::fields() ) as $field ) {
			$values[ $field ] = self::get_term( $term_id, $field );
		}

		return $values;
	}

	/**
	 * The security boundary: whatever arrives from a form becomes a known shape.
	 *
	 * @param string $field
	 * @param mixed  $value
	 *
	 * @return mixed
	 */
	public static function sanitize( $field, $value ) {
		switch ( self::fields()[ $field ] ?? 'text' ) {
			case 'url':
				return esc_url_raw( trim( (string) $value ) );

			case 'bool':
				return ( 'true' === $value || true === $value || '1' === $value || 1 === $value );

			case 'int':
				return (int) $value;

			case 'image':
				return FW_SEO_Image::url_from_value( $value );

			case 'choice':
				$allowed = self::choices( $field );
				$value   = (string) $value;

				return in_array( $value, $allowed, true ) ? $value : '';

			case 'list':
				$allowed = [ 'noarchive', 'nosnippet', 'noimageindex', 'notranslate' ];

				if ( ! is_array( $value ) ) {
					// multi-select posts a delimited string.
					$value = '' === trim( (string) $value ) ? [] : explode( ',', (string) $value );
				}

				return array_values( array_intersect( array_map( 'trim', $value ), $allowed ) );

			case 'text':
			default:
				// Templates are plain text: they end up in a <title> or a meta
				// attribute, so tags would be escaped noise at best.
				return trim( wp_strip_all_tags( (string) $value, true ) );
		}
	}

	/**
	 * The values a choice field may hold.
	 *
	 * Per field rather than one shared allowlist: `max_image_preview` and
	 * `twitter_card` are both choices and share not a single valid value, so a
	 * single list would have to accept the union — and then quietly store
	 * `large` as a Twitter card type.
	 *
	 * @param string $field
	 *
	 * @return array<int,string>
	 */
	public static function choices( $field ) {
		switch ( $field ) {
			case 'twitter_card':
				return [ '', 'summary', 'summary_large_image' ];

			case 'max_image_preview':
			default:
				return [ '', 'none', 'standard', 'large' ];
		}
	}

	/**
	 * @param string $field
	 * @param mixed  $value
	 *
	 * @return mixed
	 */
	protected static function normalize( $field, $value ) {
		switch ( self::fields()[ $field ] ?? 'text' ) {
			case 'bool':
				return (bool) $value;

			case 'int':
				return '' === $value || null === $value ? 0 : (int) $value;

			case 'list':
				return is_array( $value ) ? $value : [];

			default:
				return null === $value ? '' : (string) $value;
		}
	}

	/**
	 * @param string $field
	 *
	 * @return mixed
	 */
	protected static function empty_value( $field ) {
		switch ( self::fields()[ $field ] ?? 'text' ) {
			case 'bool':
				return false;

			case 'int':
				return 0;

			case 'list':
				return [];

			default:
				return '';
		}
	}

	/**
	 * @param mixed $value
	 *
	 * @return bool
	 */
	public static function is_empty( $value ) {
		if ( is_array( $value ) ) {
			return ! $value;
		}

		if ( is_bool( $value ) ) {
			return ! $value;
		}

		if ( is_int( $value ) ) {
			return 0 === $value;
		}

		return '' === trim( (string) $value );
	}
}
