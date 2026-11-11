<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Rendered-page checks — what a fetched page shows, which the database cannot.
 *
 * Reading from inside WordPress misses what the theme and other plugins add on
 * the way out. This fetches each published page as a logged-out visitor and
 * looks at what came back: http:// files on an https:// page, a title or
 * canonical other than the one the SEO settings produce, images far bigger than
 * the width they are given, and hreflang pairs that do not point back.
 *
 * The findings join the Audit (see FW_SEO_Audit::by_issue()).
 *
 * @since 2.0.56
 */
class FW_SEO_Render_Check {

	const META       = '_fw_seo_render';
	const STATE      = 'fw_seo_render_state';
	const CRON_WEEK  = 'fw_seo_render_weekly';
	const CRON_BATCH = 'fw_seo_render_batch';
	const BATCH      = 5;
	const NONCE      = 'fw_seo_render_run';

	/** A file this much wider than the width it is given is a waste. */
	const OVERSIZE = 2;

	/** Below this, a too-big image costs too little to mention. */
	const MIN_WIDTH = 800;

	/** A single image this heavy is worth a look whatever its width. */
	const HEAVY = 1048576;

	public static function init() {
		add_action( self::CRON_WEEK, [ __CLASS__, 'start' ] );
		add_action( self::CRON_BATCH, [ __CLASS__, 'run_batch' ] );
		add_action( 'admin_init', [ __CLASS__, '_schedule' ] );
		add_action( 'admin_post_' . self::NONCE, [ __CLASS__, '_action_run' ] );
	}

	/** @internal */
	public static function _schedule() {
		if ( ! wp_next_scheduled( self::CRON_WEEK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_WEEK );
		}
	}

	/** @return array<string, string> issue id => what it means and what to do. */
	public static function issue_labels() {
		return [
			'mixed_content'    => __( 'Mixed content: an http:// file on an https:// page. Browsers block or warn about it. Change the address to https://.', 'fw' ),
			'served_title'     => __( 'The page is served a different title from the one the SEO settings produce: the theme or another plugin is overriding it.', 'fw' ),
			'served_canonical' => __( 'The page is served a different canonical from the SEO settings, or more than one: the theme or another plugin is adding its own.', 'fw' ),
			'big_images'       => __( 'Images far larger than the space they are shown in, or very heavy files. Use a smaller size.', 'fw' ),
			'hreflang'         => __( 'A language version does not link back to this page. Language links only count when each side names the other.', 'fw' ),
		];
	}

	/* ------------------------------------------------------------------ *
	 * Reading a page — pure
	 * ------------------------------------------------------------------ */

	/** A tag's attributes as lower-case name => value. */
	protected static function attrs( $tag ) {
		$out = [];
		if ( preg_match_all( '#([a-zA-Z][\w:-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#', (string) $tag, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $a ) {
				$out[ strtolower( $a[1] ) ] = html_entity_decode( $a[4] ?? ( '' !== ( $a[3] ?? '' ) ? $a[3] : $a[2] ), ENT_QUOTES );
			}
		}

		return $out;
	}

	/** The page without scripts' bodies, styles or comments, whose text is not markup. */
	protected static function markup( $html ) {
		return (string) preg_replace( '#<!--.*?-->|(<script\b[^>]*>).*?</script>|<style\b[^>]*>.*?</style>#is', '$1', (string) $html );
	}

	/** Addresses compared the way a search engine would: scheme and host lower-case, no trailing slash. */
	public static function same_url( $a, $b ) {
		$norm = static function ( $u ) {
			$p = wp_parse_url( trim( (string) $u ) );

			return strtolower( (string) ( $p['host'] ?? '' ) ) . rtrim( (string) ( $p['path'] ?? '' ), '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
		};

		return $norm( $a ) === $norm( $b );
	}

	/**
	 * What a page's HTML shows.
	 *
	 * @param string $html
	 *
	 * @return array{title: string, canonicals: string[], hreflang: array<string, string>, images: array[], insecure: string[]}
	 */
	public static function parse( $html ) {
		$page = self::markup( $html );
		$out  = [ 'title' => '', 'canonicals' => [], 'hreflang' => [], 'images' => [], 'insecure' => [] ];

		if ( preg_match( '#<title\b[^>]*>(.*?)</title>#is', $page, $m ) ) {
			$out['title'] = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		if ( preg_match_all( '#<link\b[^>]*>#i', $page, $m ) ) {
			foreach ( $m[0] as $tag ) {
				$a   = self::attrs( $tag );
				$rel = strtolower( (string) ( $a['rel'] ?? '' ) );
				if ( 'canonical' === $rel && ! empty( $a['href'] ) ) {
					$out['canonicals'][] = $a['href'];
				} elseif ( 'alternate' === $rel && ! empty( $a['hreflang'] ) && ! empty( $a['href'] ) ) {
					$out['hreflang'][ strtolower( $a['hreflang'] ) ] = $a['href'];
				} elseif ( 'stylesheet' === $rel && 0 === stripos( (string) ( $a['href'] ?? '' ), 'http://' ) ) {
					$out['insecure'][] = $a['href'];
				}
			}
		}

		if ( preg_match_all( '#<(img|script|iframe|source|video|audio|embed)\b[^>]*>#i', $page, $m ) ) {
			foreach ( $m[0] as $i => $tag ) {
				$a = self::attrs( $tag );
				if ( 0 === stripos( (string) ( $a['src'] ?? '' ), 'http://' ) ) {
					$out['insecure'][] = $a['src'];
				}
				if ( 'img' === strtolower( $m[1][ $i ] ) && ! empty( $a['src'] ) ) {
					$out['images'][] = [ 'src' => $a['src'], 'width' => (int) ( $a['width'] ?? 0 ), 'srcset' => ! empty( $a['srcset'] ) ];
				}
			}
		}

		$out['insecure'] = array_values( array_unique( $out['insecure'] ) );

		return $out;
	}

	/**
	 * Is this image too big for its place?
	 *
	 * Only an image that declares its width can be judged, and one with a srcset
	 * is left alone, because the browser chooses the size.
	 *
	 * @param array $image   From parse().
	 * @param int   $natural The file's width in pixels.
	 * @param int   $bytes   The file's size.
	 *
	 * @return bool
	 */
	public static function too_big( array $image, $natural, $bytes ) {
		if ( $bytes >= self::HEAVY ) {
			return true;
		}

		return ! $image['srcset'] && $image['width'] > 0 && $natural >= self::MIN_WIDTH && $natural >= self::OVERSIZE * $image['width'];
	}

	/**
	 * The problems in what was served.
	 *
	 * @param array  $page      From parse().
	 * @param string $url       The page's address.
	 * @param string $title     The title the SEO settings produce.
	 * @param string $canonical The canonical they produce.
	 *
	 * @return array<string, string> issue id => detail.
	 */
	public static function compare( array $page, $url, $title, $canonical ) {
		$issues = [];

		if ( 0 === stripos( (string) $url, 'https://' ) && $page['insecure'] ) {
			$issues['mixed_content'] = implode( ', ', array_slice( $page['insecure'], 0, 3 ) );
		}

		$squash = static function ( $t ) {
			return trim( (string) preg_replace( '#\s+#u', ' ', html_entity_decode( (string) $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		};
		if ( '' !== $squash( $title ) && $squash( $title ) !== $squash( $page['title'] ) ) {
			$issues['served_title'] = $page['title'];
		}

		if ( count( $page['canonicals'] ) > 1 ) {
			$issues['served_canonical'] = implode( ', ', $page['canonicals'] );
		} elseif ( '' !== (string) $canonical && $page['canonicals'] && ! self::same_url( $page['canonicals'][0], $canonical ) ) {
			$issues['served_canonical'] = $page['canonicals'][0];
		}

		return $issues;
	}

	/**
	 * hreflang links that are not returned.
	 *
	 * @param array<int, array{url: string, hreflang: array}> $pages Keyed by post id.
	 *
	 * @return array<int, string[]> post id => the addresses that do not link back.
	 */
	public static function unreturned( array $pages ) {
		$out = [];

		foreach ( $pages as $id => $page ) {
			foreach ( (array) $page['hreflang'] as $href ) {
				if ( self::same_url( $href, $page['url'] ) ) {
					continue;
				}
				foreach ( $pages as $other ) {
					if ( ! self::same_url( $other['url'], $href ) ) {
						continue;
					}
					$back = array_filter( (array) $other['hreflang'], static function ( $h ) use ( $page ) {
						return self::same_url( $h, $page['url'] );
					} );
					if ( ! $back ) {
						$out[ $id ][] = $href;
					}
				}
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * The run
	 * ------------------------------------------------------------------ */

	/** The file behind an image in this site's uploads, or ''. */
	protected static function local_file( $src ) {
		$up   = wp_get_upload_dir();
		$path = (string) wp_parse_url( (string) $src, PHP_URL_PATH );
		$base = (string) wp_parse_url( $up['baseurl'], PHP_URL_PATH );

		if ( '' === $base || 0 !== strpos( $path, $base . '/' ) ) {
			return '';
		}

		$file = realpath( $up['basedir'] . substr( rawurldecode( $path ), strlen( $base ) ) );

		return $file && 0 === strpos( wp_normalize_path( $file ), wp_normalize_path( realpath( $up['basedir'] ) ) ) ? $file : '';
	}

	/** Fetch one page and store what is wrong with it. */
	public static function check_post( $post_id ) {
		$url = (string) get_permalink( $post_id );
		$r   = wp_remote_get( $url, [ 'timeout' => 15, 'redirection' => 3, 'sslverify' => false ] );

		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			delete_post_meta( $post_id, self::META );

			return null;
		}

		$page = self::parse( (string) wp_remote_retrieve_body( $r ) );
		$ctx  = FW_SEO_Context::for_post( get_post( $post_id ) );

		$issues = self::compare( $page, $url, FW_SEO_Chain::resolve( 'title', $ctx ), fw_seo_canonical_url( $ctx ) );

		$big = [];
		foreach ( $page['images'] as $image ) {
			$file = self::local_file( $image['src'] );
			$size = $file ? @getimagesize( $file ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a file that is not an image is simply skipped.
			if ( $file && self::too_big( $image, $size ? (int) $size[0] : 0, (int) filesize( $file ) ) ) {
				$big[] = basename( $file );
			}
		}
		if ( $big ) {
			$issues['big_images'] = implode( ', ', array_slice( array_unique( $big ), 0, 3 ) );
		}

		update_post_meta( $post_id, self::META, [ 'issues' => $issues, 'hreflang' => $page['hreflang'], 'url' => $url, 'time' => time() ] );

		return $issues;
	}

	public static function state() {
		$s = (array) get_option( self::STATE, [] );

		return [ 'status' => (string) ( $s['status'] ?? 'new' ), 'last' => (int) ( $s['last'] ?? 0 ), 'done' => (int) ( $s['done'] ?? 0 ), 'failed' => (int) ( $s['failed'] ?? 0 ), 'time' => (int) ( $s['time'] ?? 0 ) ];
	}

	public static function start() {
		update_option( self::STATE, [ 'status' => 'running', 'last' => 0, 'done' => 0, 'failed' => 0, 'time' => self::state()['time'] ], false );

		if ( ! wp_next_scheduled( self::CRON_BATCH ) ) {
			wp_schedule_single_event( time(), self::CRON_BATCH );
		}
	}

	/** @return int[] */
	protected static function ids_after( $last, $limit ) {
		global $wpdb;

		$types = array_keys( FW_SEO_Locations::post_types() );
		if ( ! $types ) {
			return [];
		}
		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_password = '' AND post_type IN ($in) AND ID > %d ORDER BY ID LIMIT %d", // phpcs:ignore WordPress.DB
			array_merge( $types, [ (int) $last, (int) $limit ] )
		) ) );
	}

	/** @return array The state after this batch. */
	public static function run_batch() {
		$s = self::state();
		if ( 'running' !== $s['status'] ) {
			return $s;
		}

		$ids = self::ids_after( $s['last'], self::BATCH );
		foreach ( $ids as $id ) {
			null === self::check_post( $id ) ? $s['failed'] ++ : $s['done'] ++;
			$s['last'] = $id;
		}

		if ( count( $ids ) < self::BATCH ) {
			self::compare_hreflang();
			$s['status'] = 'ready';
			$s['time']   = time();
		} else {
			wp_schedule_single_event( time() + 20, self::CRON_BATCH );
		}

		update_option( self::STATE, $s, false );

		return $s;
	}

	/** With every page read, see which language links are returned. */
	public static function compare_hreflang() {
		$pages = [];
		foreach ( self::ids_after( 0, 20000 ) as $id ) {
			$m = get_post_meta( $id, self::META, true );
			if ( is_array( $m ) && ! empty( $m['hreflang'] ) ) {
				$pages[ $id ] = [ 'url' => (string) $m['url'], 'hreflang' => (array) $m['hreflang'] ];
			}
		}

		$missing = self::unreturned( $pages );
		foreach ( array_keys( $pages ) as $id ) {
			$m = get_post_meta( $id, self::META, true );
			unset( $m['issues']['hreflang'] );
			if ( ! empty( $missing[ $id ] ) ) {
				$m['issues']['hreflang'] = implode( ', ', array_slice( $missing[ $id ], 0, 3 ) );
			}
			update_post_meta( $id, self::META, $m );
		}
	}

	/** @internal */
	public static function _action_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'fw' ), 403 );
		}
		check_admin_referer( self::NONCE );
		self::start();
		self::run_batch();
		wp_safe_redirect( admin_url( 'admin.php?page=fw-seo-settings&view=audit_tab' ) );
		exit;
	}

	/** @return string The line under the audit: when pages were last fetched. */
	public static function status_html() {
		$s   = self::state();
		$run = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::NONCE ), self::NONCE );

		if ( 'running' === $s['status'] ) {
			$text = sprintf(
				/* translators: %d: pages fetched */
				__( 'Fetching pages to check them as served: %d done so far.', 'fw' ),
				$s['done']
			);
		} elseif ( 'new' === $s['status'] ) {
			$text = __( 'Pages have not been fetched yet. This checks what is actually served: mixed content, overridden titles and canonicals, oversized images and language links.', 'fw' );
		} else {
			$text = sprintf(
				/* translators: 1: pages, 2: time ago, 3: pages that did not answer */
				__( 'Served pages: %1$d fetched %2$s ago, again every week. %3$d did not answer.', 'fw' ),
				$s['done'],
				human_time_diff( $s['time'] ),
				$s['failed']
			);
		}

		return '<p class="description">' . esc_html( $text ) . ' <a class="button button-small" href="' . esc_url( $run ) . '">' . esc_html__( 'Fetch pages now', 'fw' ) . '</a></p>';
	}
}
