<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The single place anything reaches <head> from.
 *
 * Providers add tags to a bag keyed by name; the bag is deduplicated and
 * ordered, then printed once. The old extension let each sub-module echo
 * directly into wp_head, which is how it shipped a blog index that emitted two
 * competing meta descriptions — one of them holding the keywords. A collector
 * makes that class of bug unrepresentable: the second writer to claim
 * `description` replaces the first rather than appending beside it.
 */
class FW_SEO_Head {

	/** @var array<string,array> */
	protected static $bag = [];

	/** @var int Insertion counter, so equal priorities keep registration order. */
	protected static $sequence = 0;

	/**
	 * Add (or replace) a meta tag.
	 *
	 * @param string $key      Bag key. Reusing a key replaces the earlier tag.
	 * @param array  $attr     Tag attributes, e.g. [ 'name' => 'robots', 'content' => 'noindex' ].
	 * @param string $tag      'meta' or 'link'.
	 * @param int    $priority Lower prints earlier.
	 */
	public static function add( $key, array $attr, $tag = 'meta', $priority = 10 ) {
		self::$bag[ $key ] = [
			'tag'      => in_array( $tag, [ 'meta', 'link' ], true ) ? $tag : 'meta',
			'attr'     => $attr,
			'priority' => (int) $priority,
			'sequence' => self::$sequence ++,
		];
	}

	/**
	 * Add raw markup — for a JSON-LD block or anything that is not a simple
	 * self-closing tag. The caller owns its escaping.
	 *
	 * @param string $key
	 * @param string $html
	 * @param int    $priority
	 */
	public static function add_raw( $key, $html, $priority = 10 ) {
		self::$bag[ $key ] = [
			'tag'      => 'raw',
			'html'     => (string) $html,
			'priority' => (int) $priority,
			'sequence' => self::$sequence ++,
		];
	}

	/**
	 * @param string $key
	 */
	public static function remove( $key ) {
		unset( self::$bag[ $key ] );
	}

	/**
	 * @return array<string,array>
	 */
	public static function bag() {
		return self::$bag;
	}

	/**
	 * Collect from every provider, then print.
	 *
	 * @param FW_SEO_Context $ctx
	 */
	public static function render( FW_SEO_Context $ctx ) {
		self::$bag = [];

		/** Fires so providers can add their tags to the SEO head bag. */
		do_action( 'fw_seo_collect_head', $ctx );

		/** Filters the complete SEO head bag immediately before it is printed. */
		$bag = (array) apply_filters( 'fw_seo_head_tags', self::$bag, $ctx );

		if ( ! $bag ) {
			return;
		}

		uasort( $bag, function ( $a, $b ) {
			if ( $a['priority'] === $b['priority'] ) {
				return $a['sequence'] <=> $b['sequence'];
			}

			return $a['priority'] <=> $b['priority'];
		} );

		echo "\n<!-- UnysonPlus SEO -->\n";

		foreach ( $bag as $item ) {
			echo self::markup( $item ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in markup().
		}

		echo "<!-- /UnysonPlus SEO -->\n";
	}

	/**
	 * @param array $item
	 *
	 * @return string
	 */
	protected static function markup( array $item ) {
		if ( 'raw' === $item['tag'] ) {
			return $item['html'] . "\n";
		}

		$attributes = '';

		foreach ( $item['attr'] as $name => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}

			$attributes .= sprintf(
				' %s="%s"',
				esc_attr( $name ),
				'href' === $name ? esc_url( $value ) : esc_attr( $value )
			);
		}

		if ( '' === $attributes ) {
			return '';
		}

		return '<' . $item['tag'] . $attributes . " />\n";
	}
}
