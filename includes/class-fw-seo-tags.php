<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The %%tag%% registry and renderer.
 *
 * Three things distinguish this from the flat precomputed array it replaces:
 *
 *  1. Resolvers are LAZY. A tag costs nothing until a template actually uses it,
 *     and the result is memoised per context. Registering a tag no longer means
 *     computing its value up front, before the query is even known — which is
 *     what made context-aware third-party tags impossible before.
 *
 *  2. Families are PREFIX-MATCHED. One registration serves unlimited tags:
 *     %%cf_<key>%% reads any custom field, %%tax_<taxonomy>%% lists any
 *     taxonomy's terms. This is what lets a template reference arbitrary site
 *     data without us shipping a tag for every field a site might have.
 *
 *  3. Empty tags COLLAPSE. When a tag resolves to nothing, the separator that
 *     was holding its place goes with it, so a template never renders
 *     "Title |  | Site". Getting this wrong is visible on every page of a site.
 */
class FW_SEO_Tags {

	/** @var array<string,array> Exact-match tags, keyed by id. */
	protected static $tags = [];

	/** @var array<string,array> Prefix-matched families, keyed by prefix (with trailing underscore). */
	protected static $families = [];

	/** @var array<int,array<string,string>> Resolved values, keyed by context object then tag key. */
	protected static $cache = [];

	/** @var bool */
	protected static $registered = false;

	/**
	 * Characters that count as "a separator holding a place". A literal made of
	 * only these (plus whitespace) is dropped along with the empty tag beside it.
	 * `.` is deliberately absent — a trailing full stop is prose, not furniture.
	 */
	const SEPARATOR_CHARS = "|-\xE2\x80\x93\xE2\x80\x94/:,\xC2\xB7\xE2\x80\xA2\xC2\xBB\xC2\xAB<>~";

	/**
	 * Register an exact tag.
	 *
	 * @param string $id   Tag id without the %% delimiters, e.g. 'sitename'.
	 * @param array  $args {
	 *     @type string   $label    Human label for the tag browser. Required.
	 *     @type string   $desc     One-line explanation.
	 *     @type string   $group    Browser grouping key: site|post|term|author|archive|search|paging.
	 *     @type array    $contexts Context types this tag applies to. Empty = all.
	 *     @type callable $resolve  fn( FW_SEO_Context $ctx ): string. Required.
	 * }
	 */
	public static function register( $id, array $args ) {
		$id = self::normalize_id( $id );

		if ( '' === $id || empty( $args['resolve'] ) || ! is_callable( $args['resolve'] ) ) {
			return;
		}

		self::$tags[ $id ] = array_merge(
			[
				'label'    => $id,
				'desc'     => '',
				'group'    => 'site',
				'contexts' => [],
			],
			$args
		);
	}

	/**
	 * Register a prefix-matched family, e.g. `cf_` serving %%cf_anything%%.
	 *
	 * The resolver receives everything after the prefix as its second argument.
	 *
	 * @param string $prefix Without the %% delimiters, e.g. 'cf_'.
	 * @param array  $args   As `register()`, but `resolve` is fn( $ctx, string $param ): string.
	 */
	public static function register_family( $prefix, array $args ) {
		$prefix = self::normalize_id( $prefix );

		if ( '' === $prefix || empty( $args['resolve'] ) || ! is_callable( $args['resolve'] ) ) {
			return;
		}

		self::$families[ $prefix ] = array_merge(
			[
				'label'       => $prefix,
				'desc'        => '',
				'group'       => 'dynamic',
				'contexts'    => [],
				'placeholder' => $prefix . 'key',
			],
			$args
		);
	}

	/**
	 * Load the built-in tags. Idempotent; safe to call from anywhere that needs
	 * the registry populated (the front end, the settings page, the AJAX preview).
	 */
	public static function boot() {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		require_once dirname( __FILE__ ) . '/tags-default.php';

		/** Fires after the built-in SEO tags are registered. Register custom %%tags%% here. */
		do_action( 'fw_seo_register_tags' );
	}

	/**
	 * Render a template: substitute every tag, then collapse what came back empty.
	 *
	 * @param string          $template Raw template, e.g. '%%title%% %%sep%% %%sitename%%'.
	 * @param FW_SEO_Context  $ctx
	 *
	 * @return string
	 */
	public static function render( $template, FW_SEO_Context $ctx ) {
		if ( ! is_string( $template ) || '' === trim( $template ) ) {
			return '';
		}

		self::boot();

		$parts = [];

		foreach ( self::tokenize( $template ) as $token ) {
			if ( 'literal' === $token['kind'] ) {
				$parts[] = [ 'text' => $token['text'], 'empty' => false ];
				continue;
			}

			$value = self::resolve( $token['id'], $token['modifiers'], $ctx );

			$parts[] = [ 'text' => $value, 'empty' => ( '' === $value ) ];
		}

		return self::tidy( self::collapse( $parts ) );
	}

	/**
	 * Remove every empty tag together with the separator that was framing it.
	 *
	 * This works on the RESOLVED parts rather than on the template, because the
	 * separator is very often a tag itself — `%%title%% %%sep%% %%x%%` — and a
	 * pass that only recognised literal separators would leave the "|" from
	 * %%sep%% stranded, which was the exact bug this replaced.
	 *
	 * Preference is backwards then forwards, never both, so a single empty tag
	 * can never eat the separators on either side of it.
	 *
	 * @param array<int,array{text:string,empty:bool}> $parts
	 *
	 * @return string
	 */
	protected static function collapse( array $parts ) {
		$count   = count( $parts );
		$removed = [];

		for ( $i = 0; $i < $count; $i ++ ) {
			if ( ! $parts[ $i ]['empty'] || isset( $removed[ $i ] ) ) {
				continue;
			}

			$removed[ $i ] = true;

			// Backwards: step over whitespace, then take a separator if we find one.
			$j = self::seek( $parts, $removed, $i, -1 );

			if ( null !== $j && self::is_separator( $parts[ $j ]['text'] ) ) {
				$removed[ $j ] = true;
				self::drop_between( $removed, $j, $i );

				continue;
			}

			// Forwards, only when there was nothing to take behind us.
			$k = self::seek( $parts, $removed, $i, 1 );

			if ( null !== $k && self::is_separator( $parts[ $k ]['text'] ) ) {
				$removed[ $k ] = true;
				self::drop_between( $removed, $i, $k );
			}
		}

		$out = '';

		foreach ( $parts as $index => $part ) {
			if ( ! isset( $removed[ $index ] ) ) {
				$out .= $part['text'];
			}
		}

		return $out;
	}

	/**
	 * The nearest neighbour in $direction that is neither already removed nor
	 * pure whitespace.
	 *
	 * @param array $parts
	 * @param array $removed
	 * @param int   $from
	 * @param int   $direction -1 or 1.
	 *
	 * @return int|null
	 */
	protected static function seek( array $parts, array $removed, $from, $direction ) {
		$count = count( $parts );

		for ( $i = $from + $direction; $i >= 0 && $i < $count; $i += $direction ) {
			if ( isset( $removed[ $i ] ) ) {
				continue;
			}

			if ( '' === trim( $parts[ $i ]['text'] ) ) {
				continue;
			}

			return $i;
		}

		return null;
	}

	/**
	 * Mark the (whitespace) parts between two indexes as removed too, so a
	 * dropped separator does not leave its padding behind.
	 *
	 * @param array $removed By reference.
	 * @param int   $from
	 * @param int   $to
	 */
	protected static function drop_between( array &$removed, $from, $to ) {
		for ( $i = $from + 1; $i < $to; $i ++ ) {
			$removed[ $i ] = true;
		}
	}

	/**
	 * Split a template into literal and tag tokens.
	 *
	 * @param string $template
	 *
	 * @return array<int,array>
	 */
	protected static function tokenize( $template ) {
		// %%id%% or %%id|modifier:arg|modifier%%
		$pattern = '/%%([a-z0-9_-]+)((?:\|[a-z0-9_]+(?::[^%|]*)?)*)%%/i';
		$tokens  = [];
		$offset  = 0;

		if ( preg_match_all( $pattern, $template, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $index => $match ) {
				[ $full, $position ] = $match;

				if ( $position > $offset ) {
					$tokens[] = [ 'kind' => 'literal', 'text' => substr( $template, $offset, $position - $offset ) ];
				}

				$tokens[] = [
					'kind'      => 'tag',
					'id'        => self::normalize_id( $matches[1][ $index ][0] ),
					'modifiers' => self::parse_modifiers( $matches[2][ $index ][0] ),
				];

				$offset = $position + strlen( $full );
			}
		}

		if ( $offset < strlen( $template ) ) {
			$tokens[] = [ 'kind' => 'literal', 'text' => substr( $template, $offset ) ];
		}

		return $tokens;
	}

	/**
	 * '|truncate:160|upper' => [ ['name'=>'truncate','arg'=>'160'], ['name'=>'upper','arg'=>''] ]
	 *
	 * @param string $chain
	 *
	 * @return array<int,array{name:string,arg:string}>
	 */
	protected static function parse_modifiers( $chain ) {
		$chain = trim( (string) $chain, '|' );

		if ( '' === $chain ) {
			return [];
		}

		$modifiers = [];

		foreach ( explode( '|', $chain ) as $piece ) {
			$parts = explode( ':', $piece, 2 );

			$modifiers[] = [
				'name' => strtolower( trim( $parts[0] ) ),
				'arg'  => isset( $parts[1] ) ? trim( $parts[1] ) : '',
			];
		}

		return $modifiers;
	}

	/**
	 * Resolve one tag against a context, memoised, then apply its modifiers.
	 *
	 * @param string         $id
	 * @param array          $modifiers
	 * @param FW_SEO_Context $ctx
	 *
	 * @return string
	 */
	protected static function resolve( $id, array $modifiers, FW_SEO_Context $ctx ) {
		$bucket = spl_object_id( $ctx );

		if ( ! isset( self::$cache[ $bucket ][ $id ] ) ) {
			self::$cache[ $bucket ][ $id ] = self::compute( $id, $ctx );
		}

		$value = self::$cache[ $bucket ][ $id ];

		foreach ( $modifiers as $modifier ) {
			$value = self::apply_modifier( $value, $modifier['name'], $modifier['arg'] );
		}

		return $value;
	}

	/**
	 * The uncached resolution: exact tag first, then the longest matching family.
	 *
	 * @param string         $id
	 * @param FW_SEO_Context $ctx
	 *
	 * @return string
	 */
	protected static function compute( $id, FW_SEO_Context $ctx ) {
		self::boot();

		$value = null;

		if ( isset( self::$tags[ $id ] ) ) {
			$value = call_user_func( self::$tags[ $id ]['resolve'], $ctx );
		} else {
			// Longest prefix wins, so a family `cf_` cannot shadow a more specific `cf_wc_`.
			$best = '';

			foreach ( self::$families as $prefix => $family ) {
				if ( 0 === strpos( $id, $prefix ) && strlen( $prefix ) > strlen( $best ) ) {
					$best = $prefix;
				}
			}

			if ( '' !== $best ) {
				$value = call_user_func(
					self::$families[ $best ]['resolve'],
					$ctx,
					substr( $id, strlen( $best ) )
				);
			}
		}

		/**
		 * Filters one resolved SEO tag value. $value is null when no tag or
		 * family matched the id, which is how an unknown %%tag%% is told apart
		 * from one that legitimately resolved to an empty string.
		 */
		$value = apply_filters( 'fw_seo_tag_value', $value, $id, $ctx );

		if ( null === $value ) {
			return '';
		}

		return self::clean( $value );
	}

	/**
	 * @param string $value
	 * @param string $name
	 * @param string $arg
	 *
	 * @return string
	 */
	protected static function apply_modifier( $value, $name, $arg ) {
		if ( '' === $value ) {
			return $value;
		}

		switch ( $name ) {
			case 'truncate':
				$limit = (int) $arg;

				return $limit > 0 ? fw_seo_truncate( $value, $limit ) : $value;

			case 'words':
				$limit = (int) $arg;

				return $limit > 0 ? wp_trim_words( $value, $limit, '' ) : $value;

			case 'upper':
				return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );

			case 'lower':
				return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );

			case 'capitalize':
				return function_exists( 'mb_convert_case' )
					? mb_convert_case( $value, MB_CASE_TITLE, 'UTF-8' )
					: ucwords( $value );

			default:
				/** Filters the result of an unrecognised SEO tag modifier, so custom modifiers can be added. */
				return apply_filters( 'fw_seo_tag_modifier', $value, $name, $arg );
		}
	}

	/**
	 * A tag value is plain text destined for a meta attribute or a <title>.
	 * Markup, entities and newlines all have to go before it reaches either.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	protected static function clean( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		$value = wp_strip_all_tags( (string) $value, true );
		$value = wp_specialchars_decode( $value, ENT_QUOTES );
		$value = preg_replace( '/\s+/u', ' ', $value );

		return trim( (string) $value );
	}

	/**
	 * Is this part pure separator furniture (so it can go with an empty tag)?
	 *
	 * Whitespace deliberately is NOT: dropping the space in
	 * "Hello %%empty%% World" would weld the words together. Whitespace is
	 * stepped over by seek() instead, and swept up by drop_between().
	 *
	 * @param string $text
	 *
	 * @return bool
	 */
	protected static function is_separator( $text ) {
		$trimmed = trim( $text );

		if ( '' === $trimmed ) {
			return false;
		}

		return '' === trim( $trimmed, self::SEPARATOR_CHARS );
	}

	/**
	 * Final pass: squeeze the whitespace left behind by collapsed tags and strip
	 * any separator now stranded at either end.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	protected static function tidy( $value ) {
		$value = preg_replace( '/\s+/u', ' ', (string) $value );

		return trim( (string) $value, " \t\n\r\0\x0B" . self::SEPARATOR_CHARS );
	}

	/**
	 * @param string $id
	 *
	 * @return string
	 */
	protected static function normalize_id( $id ) {
		return strtolower( trim( (string) $id, "% \t\n\r" ) );
	}

	/**
	 * Every registered tag and family, for the tag browser and the autocomplete.
	 *
	 * @param FW_SEO_Context|null $ctx When given, resolves an example value for
	 *                                 each tag and drops tags that do not apply
	 *                                 to this context.
	 *
	 * @return array<int,array{tag:string,label:string,desc:string,group:string,example:string}>
	 */
	public static function browse( ?FW_SEO_Context $ctx = null ) {
		self::boot();

		$rows = [];

		foreach ( self::$tags as $id => $tag ) {
			if ( $ctx && ! empty( $tag['contexts'] ) && ! in_array( $ctx->type(), (array) $tag['contexts'], true ) ) {
				continue;
			}

			$rows[] = [
				'tag'     => '%%' . $id . '%%',
				'label'   => $tag['label'],
				'desc'    => $tag['desc'],
				'group'   => $tag['group'],
				'example' => $ctx ? self::resolve( $id, [], $ctx ) : '',
			];
		}

		foreach ( self::$families as $prefix => $family ) {
			if ( $ctx && ! empty( $family['contexts'] ) && ! in_array( $ctx->type(), (array) $family['contexts'], true ) ) {
				continue;
			}

			$rows[] = [
				'tag'     => '%%' . $family['placeholder'] . '%%',
				'label'   => $family['label'],
				'desc'    => $family['desc'],
				'group'   => $family['group'],
				'example' => '',
			];
		}

		/** Filters the SEO tag browser rows shown in the editor and settings screens. */
		return apply_filters( 'fw_seo_tag_browser_rows', $rows, $ctx );
	}

	/**
	 * Drop the memoised values for a context. Only needed when something the
	 * resolvers read has changed mid-request (the editor preview saving a post).
	 *
	 * @param FW_SEO_Context|null $ctx Null clears every context.
	 */
	public static function flush( ?FW_SEO_Context $ctx = null ) {
		if ( null === $ctx ) {
			self::$cache = [];

			return;
		}

		unset( self::$cache[ spl_object_id( $ctx ) ] );
	}
}
