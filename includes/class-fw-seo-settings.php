<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Reads the extension's saved settings.
 *
 * One keyless read of the whole option array, then plain lookups — not a
 * database helper call per key, which is what a settings page with a template
 * per post type per field would otherwise cost on every request.
 */
class FW_SEO_Settings {

	/** @var array|null */
	protected static $values = null;

	/**
	 * @return array
	 */
	public static function all() {
		if ( null === self::$values ) {
			// Guarded: the settings reader warns on an extension it does not
			// know, and the engine classes are loadable (and tested) without the
			// extension being active.
			self::$values = fw()->extensions->get( 'seo' )
				? (array) fw_get_db_ext_settings_option( 'seo' )
				: [];
		}

		return self::$values;
	}

	/**
	 * @param string $key
	 * @param mixed  $default_value
	 *
	 * @return mixed
	 */
	public static function get( $key, $default_value = null ) {
		$values = self::all();

		if ( ! isset( $values[ $key ] ) || '' === $values[ $key ] ) {
			return $default_value;
		}

		return $values[ $key ];
	}

	/**
	 * @param string $key
	 * @param bool   $default_value
	 *
	 * @return bool
	 */
	public static function flag( $key, $default_value = false ) {
		$values = self::all();

		if ( ! array_key_exists( $key, $values ) ) {
			return $default_value;
		}

		return (bool) $values[ $key ];
	}

	/**
	 * The settings id holding a template.
	 *
	 * @param string $field        'title' or 'description'
	 * @param string $location_key
	 *
	 * @return string
	 */
	public static function template_id( $field, $location_key ) {
		return 'tpl_' . $field . '__' . FW_SEO_Locations::slug( $location_key );
	}

	/**
	 * The settings id holding a per-location robots flag.
	 *
	 * @param string $flag         'noindex' or 'nofollow'
	 * @param string $location_key
	 *
	 * @return string
	 */
	public static function robots_id( $flag, $location_key ) {
		return 'robots_' . $flag . '__' . FW_SEO_Locations::slug( $location_key );
	}

	/**
	 * The template configured for a field in a context, falling back to the
	 * location's shipped default.
	 *
	 * @param string         $field
	 * @param FW_SEO_Context $ctx
	 *
	 * @return string
	 */
	public static function template( $field, FW_SEO_Context $ctx ) {
		$location_key = $ctx->template_key();
		$values       = self::all();
		$id           = self::template_id( $field, $location_key );

		// array_key_exists, not get(): clearing a template is a deliberate act —
		// "no template here, fall through to auto-generation" — and treating the
		// empty string as "unset" would keep restoring the default the user just
		// removed.
		if ( array_key_exists( $id, $values ) ) {
			$saved = (string) $values[ $id ];
		} else {
			$saved = 'title' === $field
				? FW_SEO_Locations::default_title( $location_key )
				: FW_SEO_Locations::default_description( $location_key );
		}

		/** Filters the raw SEO template for a field before its tags are resolved. */
		return (string) apply_filters( 'fw_seo_template', $saved, $field, $ctx );
	}

	/**
	 * Forget the cached read. Needed after the settings form saves within the
	 * same request, so the preview reflects what was just stored.
	 */
	public static function flush() {
		self::$values = null;
	}
}
