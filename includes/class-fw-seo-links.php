<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Link report — every link on the site, indexed, so three questions have answers:
 * which pages nothing links to (orphans), which outside sites the site links to
 * most, and which links are affiliate links still passing authority (no
 * rel="sponsored").
 *
 * Links are read from the post content AND from the page builder's own tree,
 * so a link on a button, a card or an accordion is found as reliably as one in
 * a paragraph. Rendered HTML would mix content links with the header, footer
 * and menus, which link to everything and would hide every orphan.
 *
 * The index is a table, refreshed for a post whenever it is saved, and built in
 * the background in batches the first time (and on "Rebuild").
 *
 * Nothing reads settings during startup (the option-types trap).
 *
 * @since 2.0.42
 */
class FW_SEO_Links {

	const DB_VERSION        = 1;
	const DB_VERSION_OPTION = 'fw_seo_links_db';
	const STATE_OPTION      = 'fw_seo_links_state';
	const CRON_BATCH        = 'fw_seo_links_batch';
	const BATCH             = 50;
	const BUILDER_META      = 'fw:opt:ext:pb:page-builder:json';

	/** Swapped for report_html() when the settings page is drawn. */
	const PLACEHOLDER = '<!--fw-seo-links-report-->';

	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'fw_seo_links';
	}

	public static function init() {
		add_action( 'save_post', [ __CLASS__, '_on_save' ], 30, 2 );
		add_action( 'deleted_post', [ __CLASS__, '_forget' ] );
		add_action( self::CRON_BATCH, [ __CLASS__, 'run_batch' ] );
		add_action( 'admin_init', [ __CLASS__, '_maybe_start' ] );
		add_action( 'admin_post_fw_seo_links_rebuild', [ __CLASS__, '_action_rebuild' ] );
	}

	public static function install() {
		global $wpdb;

		if ( (int) get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_id bigint(20) unsigned NOT NULL,
			target_id bigint(20) unsigned NOT NULL DEFAULT 0,
			url text NOT NULL,
			host varchar(191) NOT NULL DEFAULT '',
			kind varchar(12) NOT NULL DEFAULT '',
			rel varchar(100) NOT NULL DEFAULT '',
			anchor varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY source_id (source_id),
			KEY target_id (target_id),
			KEY host (host),
			KEY kind (kind)
		) {$charset};" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/* ------------------------------------------------------------------ *
	 * Extraction — pure, so it can be tested without a database
	 * ------------------------------------------------------------------ */

	/**
	 * Links in HTML.
	 *
	 * @param string $html
	 *
	 * @return array<int, array{url: string, rel: string, anchor: string}>
	 */
	public static function from_html( $html ) {
		$out = [];

		if ( false === stripos( (string) $html, '<a' ) ) {
			return $out;
		}

		if ( ! preg_match_all( '#<a\b([^>]*)>(.*?)</a>#is', (string) $html, $m, PREG_SET_ORDER ) ) {
			return $out;
		}

		foreach ( $m as $a ) {
			if ( ! preg_match( '#\bhref\s*=\s*(["\'])(.*?)\1#is', $a[1], $h ) ) {
				continue;
			}
			$rel = preg_match( '#\brel\s*=\s*(["\'])(.*?)\1#is', $a[1], $r ) ? strtolower( trim( $r[2] ) ) : '';
			$out[] = [
				'url'    => html_entity_decode( trim( $h[2] ), ENT_QUOTES ),
				'rel'    => $rel,
				'anchor' => trim( preg_replace( '#\s+#', ' ', wp_strip_all_tags( $a[2] ) ) ),
			];
		}

		return $out;
	}

	/**
	 * Links in a page builder tree: values of link-like fields, plus links in
	 * any rich text. Image and background sources are not links.
	 *
	 * @param mixed  $node
	 * @param string $key
	 *
	 * @return array<int, array{url: string, rel: string, anchor: string}>
	 */
	public static function from_builder( $node, $key = '' ) {
		$out = [];

		if ( is_array( $node ) ) {
			// A sibling rel / nofollow field describes the link next to it.
			$rel = '';
			foreach ( $node as $k => $v ) {
				if ( is_string( $k ) && is_string( $v ) && preg_match( '#(^|_)rel$|nofollow|sponsored#i', $k ) ) {
					$rel .= ' ' . ( preg_match( '#^(yes|on|1|true)$#i', $v ) ? strtolower( preg_replace( '#^.*?(nofollow|sponsored).*$#i', '$1', $k ) ) : strtolower( $v ) );
				}
			}

			foreach ( $node as $k => $v ) {
				if ( is_string( $v ) && is_string( $k ) && self::is_link_field( $k ) && self::looks_like_link( $v ) ) {
					$out[] = [ 'url' => trim( $v ), 'rel' => trim( $rel ), 'anchor' => self::sibling_label( $node ) ];
					continue;
				}

				if ( in_array( $k, [ 'image', 'background', 'src', 'bleed_image', 'icon', 'video', 'poster' ], true ) ) {
					continue;
				}

				$out = array_merge( $out, self::from_builder( $v, (string) $k ) );
			}

			return $out;
		}

		return is_string( $node ) ? self::from_html( $node ) : [];
	}

	/** @return bool */
	protected static function is_link_field( $key ) {
		return (bool) preg_match( '#^(link|url|href|button_link|btn_link|link_url|cta_link)$|_(link|url|href)$#i', $key )
			&& ! preg_match( '#(image|img|bg|background|video|poster|icon|logo|src)#i', $key );
	}

	/** @return bool */
	public static function looks_like_link( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || '#' === $value[0] || preg_match( '#^(mailto|tel|javascript|data):#i', $value ) ) {
			return false;
		}

		if ( preg_match( '#\.(jpe?g|png|gif|webp|avif|svg|mp4|webm|riv|json|lottie)(\?.*)?$#i', $value ) ) {
			return false;
		}

		return (bool) preg_match( '#^(https?:)?//|^/[^/]|^/$#i', $value );
	}

	/** @return string */
	protected static function sibling_label( array $node ) {
		foreach ( [ 'label', 'text', 'title', 'button_text', 'btn_text' ] as $k ) {
			if ( isset( $node[ $k ] ) && is_string( $node[ $k ] ) && '' !== trim( $node[ $k ] ) ) {
				return trim( wp_strip_all_tags( $node[ $k ] ) );
			}
		}

		return '';
	}

	/**
	 * Is this an affiliate link?
	 *
	 * @param string   $url
	 * @param bool     $internal
	 * @param string[] $patterns Extra patterns from the settings: a prefix ("/go/") or a host.
	 *
	 * @return bool
	 */
	public static function is_affiliate( $url, $internal, array $patterns = [] ) {
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		foreach ( $patterns as $p ) {
			$p = trim( strtolower( $p ) );
			if ( '' === $p ) {
				continue;
			}
			if ( '/' === $p[0] ? 0 === strpos( strtolower( $path ), rtrim( $p, '/' ) . '/' ) : ( $host === $p || substr( $host, -strlen( '.' . $p ) ) === '.' . $p ) ) {
				return true;
			}
		}

		if ( $internal ) {
			// The conventional redirect folders for affiliate links on one's own domain.
			return (bool) preg_match( '#^/(go|out|recommends|recommend|refer|visit|aff|affiliate|link)/[^/]+#i', $path );
		}

		// Tracking parameters that only affiliate programmes use.
		return (bool) preg_match( '#(^|&)(aff|affid|aff_id|affiliate|affiliate_id|aff_sub|partner_id|ref_id|irclickid|clickref)=#i', $query );
	}

	/**
	 * Classify one link from a post.
	 *
	 * @param array    $link     {url, rel, anchor}
	 * @param string   $home     The site's home URL.
	 * @param string[] $patterns
	 *
	 * @return array|null The row, or null for a link that is not one (a fragment, the page itself).
	 */
	public static function classify( array $link, $home, array $patterns = [] ) {
		$url       = trim( (string) $link['url'] );
		$home_host = preg_replace( '#^www\.#', '', strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) ) );

		if ( '' === $url || '#' === $url[0] ) {
			return null;
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		} elseif ( '/' === $url[0] ) {
			$url = rtrim( (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', $home ), '/' ) . $url;
		} elseif ( ! preg_match( '#^https?://#i', $url ) ) {
			return null;
		}

		$host     = preg_replace( '#^www\.#', '', strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
		$internal = $host === $home_host;
		$rel      = (string) ( $link['rel'] ?? '' );

		return [
			'url'    => $url,
			'host'   => $host,
			'kind'   => self::is_affiliate( $url, $internal, $patterns ) ? 'affiliate' : ( $internal ? 'internal' : 'external' ),
			'rel'    => substr( $rel, 0, 100 ),
			'anchor' => function_exists( 'mb_substr' ) ? mb_substr( (string) ( $link['anchor'] ?? '' ), 0, 255 ) : substr( (string) ( $link['anchor'] ?? '' ), 0, 255 ),
		];
	}

	/* ------------------------------------------------------------------ *
	 * Indexing
	 * ------------------------------------------------------------------ */

	/** @return string[] */
	public static function patterns() {
		return array_filter( array_map( 'trim', preg_split( '#[\r\n,]+#', (string) FW_SEO_Settings::get( 'links_affiliate_patterns', '' ) ) ) );
	}

	/** @return bool */
	protected static function covered( $post ) {
		return $post instanceof WP_Post
			&& 'publish' === $post->post_status
			&& class_exists( 'FW_SEO_Locations' )
			&& isset( FW_SEO_Locations::post_types()[ $post->post_type ] );
	}

	/**
	 * Re-index one post's links.
	 *
	 * @param int $post_id
	 *
	 * @return int Links stored.
	 */
	public static function index_post( $post_id ) {
		global $wpdb;

		self::install();
		$post = get_post( $post_id );
		$wpdb->delete( self::table(), [ 'source_id' => (int) $post_id ] );

		if ( ! self::covered( $post ) ) {
			return 0;
		}

		$links   = self::from_html( (string) $post->post_content );
		$builder = get_post_meta( $post->ID, self::BUILDER_META, true );
		if ( is_string( $builder ) && '' !== $builder ) {
			$links = array_merge( $links, self::from_builder( json_decode( $builder, true ) ) );
		}

		$home     = home_url( '/' );
		$patterns = self::patterns();
		$self     = untrailingslashit( (string) get_permalink( $post ) );
		$stored   = 0;

		foreach ( $links as $link ) {
			$row = self::classify( $link, $home, $patterns );
			if ( null === $row || untrailingslashit( strtok( $row['url'], '#' ) ) === $self ) {
				continue;
			}

			$target = 0;
			if ( 'internal' === $row['kind'] ) {
				$target = (int) url_to_postid( $row['url'] );
				// An internal address that is no post may be one of the site's short
				// links, which almost always redirect to an affiliate.
				if ( ! $target && function_exists( 'fw_ext_short_links_get' ) ) {
					$slug = trim( (string) wp_parse_url( $row['url'], PHP_URL_PATH ), '/' );
					$slug = substr( $slug, (int) strrpos( '/' . $slug, '/' ) );
					if ( '' !== $slug && fw_ext_short_links_get( $slug ) ) {
						$row['kind'] = 'affiliate';
					}
				}
			}

			$wpdb->insert( self::table(), [
				'source_id' => (int) $post->ID,
				'target_id' => $target,
				'url'       => $row['url'],
				'host'      => substr( $row['host'], 0, 191 ),
				'kind'      => $row['kind'],
				'rel'       => $row['rel'],
				'anchor'    => $row['anchor'],
			] );
			$stored ++;
		}

		return $stored;
	}

	/** @internal */
	public static function _on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( (int) get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION && ! self::covered( $post ) ) {
			return;
		}

		self::index_post( $post_id );
	}

	/** @internal */
	public static function _forget( $post_id ) {
		global $wpdb;

		if ( (int) get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			$wpdb->delete( self::table(), [ 'source_id' => (int) $post_id ] );
		}
	}

	/** @return array{status: string, done: int, total: int, time: int} */
	public static function state() {
		$s = (array) get_option( self::STATE_OPTION, [] );

		return [
			'status' => (string) ( $s['status'] ?? 'new' ),
			'done'   => (int) ( $s['done'] ?? 0 ),
			'total'  => (int) ( $s['total'] ?? 0 ),
			'time'   => (int) ( $s['time'] ?? 0 ),
			'last'   => (int) ( $s['last'] ?? 0 ),
		];
	}

	/** Start a full (re)build in the background. */
	public static function start() {
		global $wpdb;

		self::install();
		$wpdb->query( 'TRUNCATE TABLE ' . self::table() ); // phpcs:ignore WordPress.DB

		$total = 0;
		foreach ( array_keys( FW_SEO_Locations::post_types() ) as $type ) {
			$total += (int) ( wp_count_posts( $type )->publish ?? 0 );
		}

		update_option( self::STATE_OPTION, [ 'status' => 'building', 'done' => 0, 'total' => $total, 'time' => time(), 'last' => 0 ], false );

		if ( ! wp_next_scheduled( self::CRON_BATCH ) ) {
			wp_schedule_single_event( time(), self::CRON_BATCH );
		}
	}

	/** @internal Start the first build when the report is new to this site. */
	public static function _maybe_start() {
		if ( 'new' === self::state()['status'] && current_user_can( 'manage_options' ) ) {
			self::start();
		}
	}

	/**
	 * Index the next batch; schedules itself until done.
	 *
	 * @return array The state after the batch.
	 */
	public static function run_batch() {
		global $wpdb;

		$state = self::state();
		if ( 'building' !== $state['status'] ) {
			return $state;
		}

		$types = array_keys( FW_SEO_Locations::post_types() );
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$ids   = $types ? $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in) AND ID > %d ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB
			array_merge( $types, [ $state['last'], self::BATCH ] )
		) ) : [];

		foreach ( $ids as $id ) {
			self::index_post( (int) $id );
			$state['last'] = (int) $id;
			$state['done'] ++;
		}

		if ( count( $ids ) < self::BATCH ) {
			$state['status'] = 'ready';
			$state['time']   = time();
		} else {
			wp_schedule_single_event( time() + 5, self::CRON_BATCH );
		}

		update_option( self::STATE_OPTION, $state, false );

		return $state;
	}

	/** @internal */
	public static function _action_rebuild() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'fw' ), 403 );
		}

		check_admin_referer( 'fw_seo_links_rebuild' );
		self::start();
		// Do the first batch now, so a small site is finished before the page reloads.
		self::run_batch();

		wp_safe_redirect( admin_url( 'admin.php?page=fw-seo-settings&view=links_tab' ) );
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Questions
	 * ------------------------------------------------------------------ */

	/**
	 * Published pages no other published page links to.
	 *
	 * The home page and the posts page are left out: everything reaches them
	 * through the menu, and nobody links to the home page from an article.
	 *
	 * @param int $limit
	 *
	 * @return array<int, array{id: int, title: string, type: string, date: string}>
	 */
	public static function orphans( $limit = 50 ) {
		global $wpdb;

		$types = array_keys( FW_SEO_Locations::post_types() );
		if ( ! $types ) {
			return [];
		}

		$skip = array_filter( [ (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ] );
		$in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$not  = $skip ? ' AND p.ID NOT IN (' . implode( ',', array_map( 'intval', $skip ) ) . ')' : '';
		$t    = self::table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_type, p.post_date FROM {$wpdb->posts} p
			 WHERE p.post_status = 'publish' AND p.post_type IN ($in) {$not}
			   AND NOT EXISTS ( SELECT 1 FROM {$t} l WHERE l.target_id = p.ID AND l.source_id <> p.ID )
			 ORDER BY p.post_date DESC LIMIT %d", // phpcs:ignore WordPress.DB
			array_merge( $types, [ max( 1, (int) $limit ) ] )
		) );

		return array_map( static function ( $r ) {
			return [ 'id' => (int) $r->ID, 'title' => (string) $r->post_title, 'type' => (string) $r->post_type, 'date' => (string) $r->post_date ];
		}, (array) $rows );
	}

	/** @return int */
	public static function orphan_count() {
		return count( self::orphans( 5000 ) );
	}

	/**
	 * Outside sites the site links to, most-linked first.
	 *
	 * @return array<int, array{host: string, links: int, pages: int}>
	 */
	public static function domains( $limit = 30 ) {
		global $wpdb;

		$t = self::table();

		return array_map( static function ( $r ) {
			return [ 'host' => (string) $r->host, 'links' => (int) $r->links, 'pages' => (int) $r->pages ];
		}, (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT host, COUNT(*) AS links, COUNT(DISTINCT source_id) AS pages FROM {$t} WHERE kind IN ('external','affiliate') AND host <> '' GROUP BY host ORDER BY links DESC LIMIT %d", // phpcs:ignore WordPress.DB
			max( 1, (int) $limit )
		) ) );
	}

	/**
	 * Affiliate links that still pass authority: no rel sponsored or nofollow.
	 *
	 * @return array<int, array{source_id: int, url: string, anchor: string, rel: string}>
	 */
	public static function unmarked_affiliates( $limit = 50 ) {
		global $wpdb;

		$t = self::table();

		return array_map( static function ( $r ) {
			return [ 'source_id' => (int) $r->source_id, 'url' => (string) $r->url, 'anchor' => (string) $r->anchor, 'rel' => (string) $r->rel ];
		}, (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT source_id, url, anchor, rel FROM {$t} WHERE kind = 'affiliate' AND rel NOT LIKE %s AND rel NOT LIKE %s ORDER BY source_id LIMIT %d", // phpcs:ignore WordPress.DB
			'%sponsored%',
			'%nofollow%',
			max( 1, (int) $limit )
		) ) );
	}

	/** @return array{internal: int, external: int, affiliate: int} */
	public static function totals() {
		global $wpdb;

		$out = [ 'internal' => 0, 'external' => 0, 'affiliate' => 0 ];
		foreach ( (array) $wpdb->get_results( 'SELECT kind, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY kind' ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ $r->kind ] = (int) $r->n;
		}

		return $out;
	}

	/**
	 * How many other published pages link to this one.
	 *
	 * @param int $post_id
	 *
	 * @return int
	 */
	public static function inbound( $post_id ) {
		global $wpdb;

		if ( (int) get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			return -1;
		}

		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(DISTINCT source_id) FROM ' . self::table() . ' WHERE target_id = %d AND source_id <> %d', // phpcs:ignore WordPress.DB
			(int) $post_id,
			(int) $post_id
		) );
	}

	/* ------------------------------------------------------------------ *
	 * Screens
	 * ------------------------------------------------------------------ */

	/**
	 * The line in a post's SEO box.
	 *
	 * @param int $post_id
	 *
	 * @return string
	 */
	public static function editor_html( $post_id ) {
		if ( 'ready' !== self::state()['status'] || 'publish' !== get_post_status( $post_id ) ) {
			return '';
		}

		$n = self::inbound( $post_id );

		if ( $n < 0 ) {
			return '';
		}

		return '<p class="fw-seo-links-line">' . ( 0 === $n
			? esc_html__( 'No other page on the site links here. Link to it from a related page, or search engines and visitors can only find it through the menu or the sitemap.', 'fw' )
			: esc_html( sprintf(
				/* translators: %d: number of pages */
				_n( 'Linked from %d other page on the site.', 'Linked from %d other pages on the site.', $n, 'fw' ),
				$n
			) ) ) . '</p>';
	}

	/**
	 * SEO → Links.
	 *
	 * @return string
	 */
	public static function report_html() {
		$state   = self::state();
		$rebuild = wp_nonce_url( admin_url( 'admin-post.php?action=fw_seo_links_rebuild' ), 'fw_seo_links_rebuild' );

		if ( 'ready' !== $state['status'] ) {
			return '<p>' . esc_html( sprintf(
				/* translators: 1: pages done, 2: pages in all */
				__( 'Reading the links on every page: %1$d of %2$d done. It runs in the background; come back in a minute.', 'fw' ),
				$state['done'],
				$state['total']
			) ) . ' <a class="button" href="' . esc_url( $rebuild ) . '">' . esc_html__( 'Start again', 'fw' ) . '</a></p>';
		}

		$totals  = self::totals();
		$orphans = self::orphans( 50 );
		$domains = self::domains( 20 );
		$unmarked = self::unmarked_affiliates( 50 );

		$html  = '<p>' . esc_html( sprintf(
			/* translators: 1: internal, 2: external, 3: affiliate links, 4: time ago */
			__( '%1$s links between your own pages, %2$s to other sites, %3$s affiliate. Kept up to date as you save; read in full %4$s ago.', 'fw' ),
			number_format_i18n( $totals['internal'] ),
			number_format_i18n( $totals['external'] ),
			number_format_i18n( $totals['affiliate'] ),
			human_time_diff( $state['time'] )
		) ) . ' <a class="button" href="' . esc_url( $rebuild ) . '">' . esc_html__( 'Rebuild', 'fw' ) . '</a></p>';

		// Orphans.
		$html .= '<h3>' . esc_html( sprintf(
			/* translators: %d: number of pages */
			_n( '%d page nothing links to', '%d pages nothing links to', count( $orphans ), 'fw' ),
			count( $orphans )
		) ) . '</h3>';
		$html .= '<p class="description">' . esc_html__( 'Reachable only from a menu or the sitemap. Search engines treat a page the site itself never links to as unimportant. Link to each from a related page.', 'fw' ) . '</p>';
		if ( $orphans ) {
			$html .= '<table class="widefat striped" style="max-width:960px"><thead><tr><th>' . esc_html__( 'Page', 'fw' ) . '</th><th>' . esc_html__( 'Type', 'fw' ) . '</th><th>' . esc_html__( 'Published', 'fw' ) . '</th></tr></thead><tbody>';
			foreach ( $orphans as $o ) {
				$type  = get_post_type_object( $o['type'] );
				$html .= '<tr><td><a href="' . esc_url( (string) get_edit_post_link( $o['id'] ) ) . '">' . esc_html( $o['title'] ?: __( '(no title)', 'fw' ) ) . '</a></td><td>' . esc_html( $type ? $type->labels->singular_name : $o['type'] ) . '</td><td>' . esc_html( mysql2date( get_option( 'date_format' ), $o['date'] ) ) . '</td></tr>';
			}
			$html .= '</tbody></table>';
		}

		// Affiliates without sponsored.
		$html .= '<h3>' . esc_html( sprintf(
			/* translators: %d: number of links */
			_n( '%d affiliate link passing authority', '%d affiliate links passing authority', count( $unmarked ), 'fw' ),
			count( $unmarked )
		) ) . '</h3>';
		$html .= '<p class="description">' . esc_html__( 'Search engines ask for paid and affiliate links to carry rel="sponsored" (or nofollow). Add it to each link below.', 'fw' ) . '</p>';
		if ( $unmarked ) {
			$html .= '<table class="widefat striped" style="max-width:960px"><thead><tr><th>' . esc_html__( 'On page', 'fw' ) . '</th><th>' . esc_html__( 'Link', 'fw' ) . '</th><th>' . esc_html__( 'Text', 'fw' ) . '</th></tr></thead><tbody>';
			foreach ( $unmarked as $u ) {
				$html .= '<tr><td><a href="' . esc_url( (string) get_edit_post_link( $u['source_id'] ) ) . '">' . esc_html( get_the_title( $u['source_id'] ) ) . '</a></td><td><code>' . esc_html( $u['url'] ) . '</code></td><td>' . esc_html( $u['anchor'] ) . '</td></tr>';
			}
			$html .= '</tbody></table>';
		}

		// Domains.
		$html .= '<h3>' . esc_html__( 'Where your links go', 'fw' ) . '</h3>';
		if ( $domains ) {
			$html .= '<table class="widefat striped" style="max-width:960px"><thead><tr><th>' . esc_html__( 'Site', 'fw' ) . '</th><th>' . esc_html__( 'Links', 'fw' ) . '</th><th>' . esc_html__( 'From pages', 'fw' ) . '</th></tr></thead><tbody>';
			foreach ( $domains as $d ) {
				$html .= '<tr><td>' . esc_html( $d['host'] ) . '</td><td>' . esc_html( number_format_i18n( $d['links'] ) ) . '</td><td>' . esc_html( number_format_i18n( $d['pages'] ) ) . '</td></tr>';
			}
			$html .= '</tbody></table>';
		} else {
			$html .= '<p>' . esc_html__( 'The site does not link to any other site.', 'fw' ) . '</p>';
		}

		return $html;
	}
}
