<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Site-wide audit, and the guardrails for pages generated in bulk.
 *
 * The per-page checks (FW_SEO_Analysis) run across every published page, in the
 * background, and the result is a list of specific problems with the pages
 * that have them, never a score: "these eleven pages have no description" is a
 * morning's work with a clear finish line; "73/100" is not.
 *
 * The guardrails are for sites that generate many pages from one template:
 * near-duplicate detection (pages whose text is mostly the same as another's,
 * the thing that gets templated pages discounted as a whole), keeping thin
 * pages of chosen types out of the index until they say something, and how
 * many pages of each type Google actually kept (from the index coverage data).
 *
 * Per-page results live in post meta, so a page is re-checked when saved;
 * the site-wide comparisons (duplicate titles, near-duplicates) are worked out
 * at the end of each full run.
 *
 * Nothing reads settings during startup (the option-types trap).
 *
 * @since 2.0.50
 */
class FW_SEO_Audit {

	const META       = '_fw_seo_audit';
	const SIG_META   = '_fw_seo_sig';
	const STATE      = 'fw_seo_audit_state';
	const SITE       = 'fw_seo_audit_site';
	const CRON_BATCH = 'fw_seo_audit_batch';
	const CRON_DAILY = 'fw_seo_audit_daily';
	const BATCH      = 25;
	const SIMILAR    = 0.9;
	const HASHES     = 64;

	/** Swapped for report_html() when the settings page is drawn. */
	const PLACEHOLDER = '<!--fw-seo-audit-report-->';

	public static function init() {
		add_action( self::CRON_BATCH, [ __CLASS__, 'run_batch' ] );
		add_action( self::CRON_DAILY, [ __CLASS__, 'start' ] );
		add_action( 'admin_init', [ __CLASS__, '_schedule' ] );
		add_action( 'save_post', [ __CLASS__, '_on_save' ], 50, 2 );
		add_action( 'admin_post_fw_seo_audit_run', [ __CLASS__, '_action_run' ] );
		add_filter( 'fw_seo_robots', [ __CLASS__, '_filter_robots' ], 10, 2 );
		add_filter( 'fw_seo_sitemap_urls', [ __CLASS__, '_filter_sitemap' ], 10, 2 );
	}

	/** @internal */
	public static function _schedule() {
		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + 3 * HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Similarity — pure
	 * ------------------------------------------------------------------ */

	/**
	 * A MinHash signature of a text's five-word shingles: two texts' signatures
	 * agree in about the same share of places as the texts share shingles.
	 *
	 * @param string $text
	 *
	 * @return int[] HASHES integers, [] for a text too short to compare.
	 */
	public static function signature( $text ) {
		$words = explode( ' ', FW_SEO_Analysis::norm( $text ) );
		if ( count( $words ) < 50 ) {
			return [];
		}

		$shingles = [];
		for ( $i = 0, $n = count( $words ) - 4; $i < $n; $i ++ ) {
			$shingles[ implode( ' ', array_slice( $words, $i, 5 ) ) ] = true;
		}
		$shingles = array_keys( $shingles );

		$sig = [];
		for ( $k = 0; $k < self::HASHES; $k ++ ) {
			$min = PHP_INT_MAX;
			foreach ( $shingles as $s ) {
				$h = crc32( $k . '|' . $s );
				if ( $h < $min ) {
					$min = $h;
				}
			}
			$sig[] = $min;
		}

		return $sig;
	}

	/** @return float 0–1, the estimated share of shingles two signatures share. */
	public static function similarity( array $a, array $b ) {
		if ( ! $a || count( $a ) !== count( $b ) ) {
			return 0.0;
		}

		$same = 0;
		foreach ( $a as $i => $v ) {
			if ( $v === $b[ $i ] ) {
				$same ++;
			}
		}

		return (float) ( $same / count( $a ) );
	}

	/**
	 * Groups of near-duplicates among signatures of one type.
	 *
	 * @param array<int, int[]> $sigs post id => signature.
	 *
	 * @return array<int, array{ids: int[], similarity: float}>
	 */
	public static function clusters( array $sigs ) {
		$ids    = array_keys( $sigs );
		$parent = array_combine( $ids, $ids );
		$best   = [];
		$find   = static function ( $x ) use ( &$parent, &$find ) {
			return $parent[ $x ] === $x ? $x : ( $parent[ $x ] = $find( $parent[ $x ] ) );
		};

		for ( $i = 0, $n = count( $ids ); $i < $n; $i ++ ) {
			for ( $j = $i + 1; $j < $n; $j ++ ) {
				$s = self::similarity( $sigs[ $ids[ $i ] ], $sigs[ $ids[ $j ] ] );
				if ( $s >= self::SIMILAR ) {
					$ri            = $find( $ids[ $i ] );
					$rj            = $find( $ids[ $j ] );
					$parent[ $rj ] = $ri;
					$best[ $ids[ $i ] ] = max( $best[ $ids[ $i ] ] ?? 0, $s );
					$best[ $ids[ $j ] ] = max( $best[ $ids[ $j ] ] ?? 0, $s );
				}
			}
		}

		$groups = [];
		foreach ( array_keys( $best ) as $id ) {
			$groups[ $find( $id ) ][] = $id;
		}

		return array_values( array_map( static function ( $g ) use ( $best ) {
			sort( $g );

			return [ 'ids' => $g, 'similarity' => round( min( array_map( static function ( $id ) use ( $best ) {
				return $best[ $id ];
			}, $g ) ), 2 ) ];
		}, $groups ) );
	}

	/* ------------------------------------------------------------------ *
	 * One page
	 * ------------------------------------------------------------------ */

	/**
	 * The problems on one page, as [id => detail].
	 *
	 * @param int $post_id
	 *
	 * @return array
	 */
	public static function check_post( $post_id ) {
		$m      = FW_SEO_Analysis::material( $post_id );
		$issues = [];
		$own    = class_exists( 'FW_SEO_Store' ) ? trim( (string) FW_SEO_Store::get_post( $post_id, 'description' ) ) : '';
		$tlen   = function_exists( 'mb_strlen' ) ? mb_strlen( $m['title'] ) : strlen( $m['title'] );
		$dlen   = function_exists( 'mb_strlen' ) ? mb_strlen( $m['description'] ) : strlen( $m['description'] );

		if ( '' === trim( $m['description'] ) ) {
			$issues['no_description'] = '';
		} elseif ( '' === $own ) {
			$issues['auto_description'] = '';
		}
		if ( $tlen > 60 ) {
			$issues['long_title'] = (string) $tlen;
		}
		if ( $dlen > 160 ) {
			$issues['long_description'] = (string) $dlen;
		}

		foreach ( FW_SEO_Analysis::heading_problems( $m['headings'] ) as $p ) {
			$issues['headings'] = $p;
			break;
		}

		$phrase = (string) get_post_meta( $post_id, FW_SEO_Analysis::META, true );
		if ( '' === trim( $phrase ) ) {
			$issues['no_focus'] = '';
		} else {
			$failed = array_filter( FW_SEO_Analysis::keyphrase_checks( $phrase, $m ), static function ( $c ) {
				return ! $c['ok'] && in_array( $c['id'], [ 'title', 'description', 'intro', 'used' ], true );
			} );
			if ( $failed ) {
				$issues['focus_missing'] = implode( ', ', wp_list_pluck( $failed, 'id' ) );
			}
		}

		$words = FW_SEO_Analysis::words( implode( ' ', FW_SEO_Analysis::paragraphs( $m['html'] ) ) );
		if ( class_exists( 'FW_SEO_Freshness' ) && $words < FW_SEO_Freshness::thin_words() ) {
			$issues['thin'] = (string) $words;
		}

		update_post_meta( $post_id, self::META, [ 'issues' => $issues, 'title' => $m['title'], 'description' => $m['description'], 'words' => $words, 'time' => time() ] );
		update_post_meta( $post_id, self::SIG_META, self::signature( implode( ' ', FW_SEO_Analysis::paragraphs( $m['html'] ) ) ) );

		return $issues;
	}

	/** @internal Re-check a page when it is saved. */
	public static function _on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! isset( FW_SEO_Locations::post_types()[ $post->post_type ] ) ) {
			return;
		}

		self::check_post( $post_id );
	}

	/* ------------------------------------------------------------------ *
	 * The whole site
	 * ------------------------------------------------------------------ */

	/** @return int[] Published pages the audit covers. */
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

	public static function state() {
		$s = (array) get_option( self::STATE, [] );

		return [ 'status' => (string) ( $s['status'] ?? 'new' ), 'last' => (int) ( $s['last'] ?? 0 ), 'done' => (int) ( $s['done'] ?? 0 ), 'time' => (int) ( $s['time'] ?? 0 ) ];
	}

	public static function start() {
		update_option( self::STATE, [ 'status' => 'running', 'last' => 0, 'done' => 0, 'time' => time() ], false );
		if ( ! wp_next_scheduled( self::CRON_BATCH ) ) {
			wp_schedule_single_event( time(), self::CRON_BATCH );
		}
	}

	/** @return array The state after this batch. */
	public static function run_batch() {
		$state = self::state();
		if ( 'running' !== $state['status'] ) {
			return $state;
		}

		$ids = self::ids_after( $state['last'], self::BATCH );
		foreach ( $ids as $id ) {
			self::check_post( $id );
			$state['last'] = $id;
			$state['done'] ++;
		}

		if ( count( $ids ) < self::BATCH ) {
			self::compare_site();
			$state['status'] = 'ready';
			$state['time']   = time();
		} else {
			wp_schedule_single_event( time() + 5, self::CRON_BATCH );
		}

		update_option( self::STATE, $state, false );

		return $state;
	}

	/** Duplicate titles and descriptions, and near-duplicate pages, across the site. */
	public static function compare_site() {
		$titles = $descs = $sigs = [];

		foreach ( self::ids_after( 0, 20000 ) as $id ) {
			$a = get_post_meta( $id, self::META, true );
			if ( ! is_array( $a ) ) {
				continue;
			}
			$t = FW_SEO_Analysis::norm( $a['title'] ?? '' );
			$d = FW_SEO_Analysis::norm( $a['description'] ?? '' );
			if ( '' !== $t ) {
				$titles[ $t ][] = $id;
			}
			if ( '' !== $d ) {
				$descs[ $d ][] = $id;
			}
			$sig = get_post_meta( $id, self::SIG_META, true );
			if ( is_array( $sig ) && $sig ) {
				$sigs[ get_post_type( $id ) ][ $id ] = $sig;
			}
		}

		$dups = static function ( $map ) {
			return array_values( array_filter( $map, static function ( $ids ) {
				return count( $ids ) > 1;
			} ) );
		};

		$near = [];
		foreach ( $sigs as $type => $set ) {
			// Pairwise comparison is quadratic; beyond this a site needs a better tool than a settings screen.
			foreach ( self::clusters( array_slice( $set, 0, 1500, true ) ) as $c ) {
				$near[] = $c + [ 'type' => $type ];
			}
		}

		update_option( self::SITE, [ 'titles' => $dups( $titles ), 'descriptions' => $dups( $descs ), 'near' => $near, 'time' => time() ], false );
	}

	/* ------------------------------------------------------------------ *
	 * Guardrail: thin pages out of the index
	 * ------------------------------------------------------------------ */

	/** @return string[] Post types whose thin pages are kept out of the index. */
	public static function guarded_types() {
		$out = [];
		foreach ( (array) FW_SEO_Settings::get( 'thin_noindex_types', [] ) as $k => $v ) {
			$type = is_int( $k ) ? (string) $v : ( $v ? (string) $k : '' );
			if ( '' !== $type ) {
				$out[] = $type;
			}
		}

		return $out;
	}

	/** @return bool Whether a post is held out of the index for being thin. */
	public static function held_back( $post_id ) {
		if ( ! in_array( get_post_type( $post_id ), self::guarded_types(), true ) || ! class_exists( 'FW_SEO_Freshness' ) ) {
			return false;
		}

		return FW_SEO_Freshness::words( $post_id ) < FW_SEO_Freshness::thin_words();
	}

	/** @internal */
	public static function _filter_robots( $directives, $ctx ) {
		if ( ! $ctx->is( FW_SEO_Context::SINGULAR ) || ! $ctx->post_id() || ! self::held_back( $ctx->post_id() ) ) {
			return $directives;
		}

		return array_map( static function ( $d ) {
			return 'index' === $d ? 'noindex' : $d;
		}, array_merge( array_diff( (array) $directives, [ 'index' ] ), [ 'noindex' ] ) );
	}

	/** @internal Thin pages held back are left out of the sitemap too. */
	public static function _filter_sitemap( $urls, $key ) {
		if ( 0 !== strpos( (string) $key, 'pt-' ) || ! in_array( substr( (string) $key, 3 ), self::guarded_types(), true ) ) {
			return $urls;
		}

		return array_values( array_filter( (array) $urls, static function ( $u ) {
			$id = url_to_postid( (string) ( $u['loc'] ?? '' ) );

			return ! $id || ! self::held_back( $id );
		} ) );
	}

	/* ------------------------------------------------------------------ *
	 * The report
	 * ------------------------------------------------------------------ */

	/** @return array<string, string> issue id => what it means and what to do. */
	public static function issue_labels() {
		return [
			'no_description'   => __( 'No description at all: search engines will pick a snippet of their own. Write one.', 'fw' ),
			'auto_description' => __( 'No description written for the page: an automatic one is used. Fine as a fallback; a written one earns more clicks.', 'fw' ),
			'long_title'       => __( 'Title over 60 characters: likely cut off in search results.', 'fw' ),
			'long_description' => __( 'Description over 160 characters: likely cut off.', 'fw' ),
			'headings'         => __( 'Heading structure: a level skipped, or an H1 in the content.', 'fw' ),
			'focus_missing'    => __( 'Focus keyphrase missing from the title, description, first paragraph or text.', 'fw' ),
			'no_focus'         => __( 'No focus keyphrase set. Optional; it is what the page checks are measured against.', 'fw' ),
			'thin'             => __( 'Thin: fewer words than the thin-page threshold (Going stale settings).', 'fw' ),
		];
	}

	/** @return array<string, int[]> issue id => post ids, from the stored results. */
	public static function by_issue() {
		$out = [];
		foreach ( self::ids_after( 0, 20000 ) as $id ) {
			$a = get_post_meta( $id, self::META, true );
			foreach ( array_keys( (array) ( $a['issues'] ?? [] ) ) as $issue ) {
				$out[ $issue ][] = $id;
			}
		}

		return $out;
	}

	/** @return array<string, array{indexed: int, total: int}> Per post type, from the index coverage checks. */
	public static function coverage_by_type() {
		global $wpdb;
		if ( ! class_exists( 'FW_SEO_Inspect' ) ) {
			return [];
		}

		$out  = [];
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.post_type, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_status = 'publish'",
			FW_SEO_Inspect::META_KEY
		) );
		foreach ( (array) $rows as $r ) {
			$row = maybe_unserialize( $r->meta_value );
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[ $r->post_type ]['total'] = ( $out[ $r->post_type ]['total'] ?? 0 ) + 1;
			$out[ $r->post_type ]['indexed'] = ( $out[ $r->post_type ]['indexed'] ?? 0 ) + ( 'indexed' === FW_SEO_Inspect::state( $row ) ? 1 : 0 );
		}

		return $out;
	}

	protected static function page_list( array $ids, $limit = 25 ) {
		$html = '<ul style="margin:6px 0 0 18px;list-style:disc">';
		foreach ( array_slice( $ids, 0, $limit ) as $id ) {
			$html .= '<li><a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ?: __( '(no title)', 'fw' ) ) . '</a></li>';
		}
		if ( count( $ids ) > $limit ) {
			$html .= '<li>' . esc_html( sprintf(
				/* translators: %d: number of further pages */
				__( 'and %d more', 'fw' ),
				count( $ids ) - $limit
			) ) . '</li>';
		}

		return $html . '</ul>';
	}

	/** @internal */
	public static function _action_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'fw' ), 403 );
		}
		check_admin_referer( 'fw_seo_audit_run' );
		self::start();
		self::run_batch();
		wp_safe_redirect( admin_url( 'admin.php?page=fw-seo-settings&view=audit_tab' ) );
		exit;
	}

	/** @return string SEO → Audit. */
	public static function report_html() {
		$state = self::state();
		$run   = wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_audit_run' ), 'fw_seo_audit_run' );

		if ( 'new' === $state['status'] ) {
			return '<p>' . esc_html__( 'Not run yet. It checks every published page in the background and takes a minute or two on most sites.', 'fw' ) . ' <a class="button button-primary" href="' . esc_url( $run ) . '">' . esc_html__( 'Run the audit', 'fw' ) . '</a></p>';
		}
		if ( 'running' === $state['status'] ) {
			return '<p>' . esc_html( sprintf(
				/* translators: %d: pages checked */
				__( 'Checking pages: %d done so far. It runs in the background; come back in a minute.', 'fw' ),
				$state['done']
			) ) . '</p>';
		}

		$issues = self::by_issue();
		$site   = (array) get_option( self::SITE, [] );
		$html   = '<p>' . esc_html( sprintf(
			/* translators: 1: pages, 2: time ago */
			__( '%1$d pages checked, %2$s ago. It runs again every day, and each page is re-checked when you save it.', 'fw' ),
			$state['done'],
			human_time_diff( $state['time'] )
		) ) . ' <a class="button" href="' . esc_url( $run ) . '">' . esc_html__( 'Check again now', 'fw' ) . '</a></p>';

		$section = static function ( $title, $note, array $ids ) {
			return '<details style="margin:0 0 10px"><summary style="cursor:pointer"><strong>' . esc_html( $title ) . '</strong> <span class="count">(' . count( $ids ) . ')</span></summary><p class="description" style="margin:6px 0 0">' . esc_html( $note ) . '</p>' . self::page_list( $ids ) . '</details>';
		};

		$any = false;
		foreach ( (array) ( $site['titles'] ?? [] ) as $ids ) {
			$any   = true;
			$html .= $section( sprintf(
				/* translators: %s: the shared title */
				__( 'Same title: "%s"', 'fw' ),
				wp_html_excerpt( (string) ( get_post_meta( $ids[0], self::META, true )['title'] ?? '' ), 70, '…' )
			), __( 'Pages with the same title compete with each other and look identical in search results. Give each its own.', 'fw' ), $ids );
		}
		foreach ( (array) ( $site['descriptions'] ?? [] ) as $ids ) {
			$any   = true;
			$html .= $section( __( 'Same description', 'fw' ), __( 'These pages share one description. Write one for each.', 'fw' ), $ids );
		}
		foreach ( (array) ( $site['near'] ?? [] ) as $c ) {
			$any   = true;
			$html .= $section( sprintf(
				/* translators: %d: percentage */
				__( 'Near-duplicates: %d%% the same text', 'fw' ),
				(int) round( $c['similarity'] * 100 )
			), __( 'Pages whose text is almost all the same. Search engines judge mass-produced pages as a whole: give each something of its own, or merge them.', 'fw' ), $c['ids'] );
		}
		foreach ( self::issue_labels() as $id => $label ) {
			if ( ! empty( $issues[ $id ] ) ) {
				$any   = true;
				$html .= $section( $label, '', $issues[ $id ] );
			}
		}

		if ( ! $any ) {
			$html .= '<p><strong>' . esc_html__( 'Nothing to fix.', 'fw' ) . '</strong></p>';
		}

		$cov = self::coverage_by_type();
		if ( $cov ) {
			$html .= '<h3>' . esc_html__( 'How many of each kind Google kept', 'fw' ) . '</h3><table class="widefat striped" style="max-width:640px"><tbody>';
			foreach ( $cov as $type => $c ) {
				$obj   = get_post_type_object( $type );
				$html .= '<tr><td>' . esc_html( $obj ? $obj->labels->name : $type ) . '</td><td>' . esc_html( sprintf(
					/* translators: 1: indexed, 2: checked */
					__( '%1$d of %2$d checked are indexed', 'fw' ),
					$c['indexed'] ?? 0,
					$c['total'] ?? 0
				) ) . '</td></tr>';
			}
			$html .= '</tbody></table><p class="description">' . esc_html__( 'From the index coverage checks (Search Console). For generated pages, this is the number that says whether the approach is working.', 'fw' ) . '</p>';
		}

		return $html;
	}
}
