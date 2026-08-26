<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The one resolution ladder every field shares.
 *
 * Override -> template -> auto-generated -> fallback, first non-empty wins.
 *
 * The old extension wrote this ladder out longhand for each field inside each
 * location branch — roughly four hundred lines of the same four steps, and the
 * reason adding a fifth field (a canonical URL, an OG title) meant writing it
 * all again nine more times. Here a field is a small array of callables, so
 * adding one costs a registration.
 */
class FW_SEO_Chain {

	/** @var array<string,array> */
	protected static $fields = [];

	/** @var array<int,array<string,string>> Resolved values per context, per field. */
	protected static $cache = [];

	/**
	 * The context objects the cache is keyed on, held so PHP cannot recycle
	 * their ids.
	 *
	 * spl_object_id() is only unique among LIVE objects: once a context is
	 * garbage collected its id is handed to the next one allocated, and the
	 * new context then reads the old one's cached values. Invisible on the
	 * front end, which resolves a single context per request, and immediate
	 * on any screen that resolves many in a loop — a list table showed row
	 * three carrying row one's title. Holding the reference keeps every id
	 * distinct for as long as the cache remembers it.
	 *
	 * @var array<int,FW_SEO_Context>
	 */
	protected static $contexts = [];

	/** @var bool */
	protected static $registered = false;

	/**
	 * Register a field.
	 *
	 * @param string $field
	 * @param array  $args {
	 *     @type callable $override Optional. fn( $ctx ): string — the user's per-object value.
	 *     @type callable $template Optional. fn( $ctx ): string — the raw template, tags unresolved.
	 *     @type callable $auto     Optional. fn( $ctx ): string — generated from the content.
	 *     @type callable $fallback Optional. fn( $ctx ): string — last resort.
	 *     @type int      $limit    Optional. Trim the result to this many characters.
	 * }
	 */
	public static function register_field( $field, array $args ) {
		self::$fields[ $field ] = array_merge(
			[
				'override' => null,
				'template' => null,
				'auto'     => null,
				'fallback' => null,
				'limit'    => 0,
			],
			$args
		);
	}

	/**
	 * Load the built-in field definitions. Idempotent.
	 */
	public static function boot() {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		require_once dirname( __FILE__ ) . '/fields-default.php';

		/** Fires after the built-in SEO fields are registered. Register custom fields here. */
		do_action( 'fw_seo_register_fields' );
	}

	/**
	 * Resolve a field for a context.
	 *
	 * @param string         $field
	 * @param FW_SEO_Context $ctx
	 *
	 * @return string
	 */
	public static function resolve( $field, FW_SEO_Context $ctx ) {
		self::boot();

		$bucket = spl_object_id( $ctx );

		if ( isset( self::$cache[ $bucket ][ $field ] ) ) {
			return self::$cache[ $bucket ][ $field ];
		}

		$value = self::run( $field, $ctx );

		self::$cache[ $bucket ][ $field ] = $value;
		self::$contexts[ $bucket ]        = $ctx;

		return $value;
	}

	/**
	 * Walk the ladder. Each stage may be filtered, and the stage that produced
	 * the winning value is reported to the final filter — the editor preview
	 * uses that to tell the user whether they are looking at their own text, a
	 * template, or something we generated.
	 *
	 * @param string         $field
	 * @param FW_SEO_Context $ctx
	 *
	 * @return string
	 */
	protected static function run( $field, FW_SEO_Context $ctx ) {
		if ( ! isset( self::$fields[ $field ] ) ) {
			return '';
		}

		$definition = self::$fields[ $field ];
		$value      = '';
		$source     = 'none';

		foreach ( [ 'override', 'template', 'auto', 'fallback' ] as $stage ) {
			if ( ! is_callable( $definition[ $stage ] ) ) {
				continue;
			}

			$candidate = (string) call_user_func( $definition[ $stage ], $ctx );

			/** Filters one stage of the SEO value chain (override, template, auto, fallback). */
			$candidate = (string) apply_filters( 'fw_seo_chain_stage', $candidate, $stage, $field, $ctx );

			if ( '' !== trim( $candidate ) ) {
				$value  = $candidate;
				$source = $stage;
				break;
			}
		}

		if ( '' !== $value && $definition['limit'] > 0 ) {
			$value = fw_seo_truncate( $value, (int) $definition['limit'] );
		}

		/** Filters the final resolved value of an SEO field. $source names the winning stage. */
		return (string) apply_filters( 'fw_seo_value', $value, $field, $ctx, $source );
	}

	/**
	 * Which stage produced the current value. Recomputes the ladder rather than
	 * caching a second map, because it is only ever called by the editor preview.
	 *
	 * @param string         $field
	 * @param FW_SEO_Context $ctx
	 *
	 * @return string One of override|template|auto|fallback|none.
	 */
	public static function source( $field, FW_SEO_Context $ctx ) {
		self::boot();

		if ( ! isset( self::$fields[ $field ] ) ) {
			return 'none';
		}

		$definition = self::$fields[ $field ];

		foreach ( [ 'override', 'template', 'auto', 'fallback' ] as $stage ) {
			if ( ! is_callable( $definition[ $stage ] ) ) {
				continue;
			}

			if ( '' !== trim( (string) call_user_func( $definition[ $stage ], $ctx ) ) ) {
				return $stage;
			}
		}

		return 'none';
	}

	/**
	 * @return array<int,string>
	 */
	public static function registered_fields() {
		self::boot();

		return array_keys( self::$fields );
	}

	/**
	 * @param FW_SEO_Context|null $ctx
	 */
	public static function flush( ?FW_SEO_Context $ctx = null ) {
		if ( null === $ctx ) {
			self::$cache    = [];
			self::$contexts = [];

			return;
		}

		$bucket = spl_object_id( $ctx );

		unset( self::$cache[ $bucket ], self::$contexts[ $bucket ] );
	}
}
