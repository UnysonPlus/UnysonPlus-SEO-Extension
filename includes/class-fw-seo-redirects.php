<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Automatic redirects when a URL changes.
 *
 * Renaming a page, moving it under another parent, or renaming a category
 * changes its address, and every link to the old one — from other sites, from
 * search results, from bookmarks — starts landing on a 404. Search traffic to
 * that page quietly stops.
 *
 * WordPress core covers one case: the slug of a published, NON-hierarchical
 * post (`_wp_old_slug`). It does nothing for pages, nothing when a parent
 * changes (which moves every child too), and nothing for terms. This fills
 * those gaps and leaves core's own case to core.
 *
 * Design:
 *  - The map stores old path => object id, never old path => new URL. A page
 *    renamed twice therefore redirects from both old addresses straight to the
 *    current one, with no chains, and the target is always the live permalink.
 *  - It only acts on a 404. If anything now lives at an old address — a new
 *    page took the slug — that content wins and the redirect is dropped.
 *  - Only published content is a target. A trashed or drafted page is gone;
 *    redirecting to it would just move the 404.
 *
 * @since 2.0.36
 */
class FW_SEO_Redirects {

	const OPTION = 'fw_seo_redirects';

	/** Stands in for the report in the settings schema; see settings-options.php. */
	const PLACEHOLDER = '<!--fw-seo-redirects-report-->';

	/** Old term links captured before an edit, keyed by term id. */
	protected static $term_before = [];

	public static function init() {
		// The on/off switch is NOT read here. Reading extension settings while
		// extensions are still starting forces the framework to initialise its
		// option types before this extension has registered `seo-template`, so
		// every SEO title and description saved from the editor parses as empty.
		// Hooks are always added; each checks enabled() when it actually runs.
		add_action( 'post_updated', [ __CLASS__, '_on_post_updated' ], 20, 3 );
		add_action( 'edit_terms', [ __CLASS__, '_before_term_edit' ], 10, 2 );
		add_action( 'edited_term', [ __CLASS__, '_after_term_edit' ], 10, 3 );
		add_action( 'template_redirect', [ __CLASS__, '_maybe_redirect' ], 20 );
		add_action( 'deleted_post', [ __CLASS__, '_forget_post' ] );
		add_action( 'delete_term', [ __CLASS__, '_forget_term' ], 10, 3 );

		if ( is_admin() ) {
			add_action( 'admin_post_fw_seo_redirect_delete', [ __CLASS__, '_action_delete' ] );
		}
	}

	/**
	 * @return bool
	 */
	public static function enabled() {
		$value = class_exists( 'FW_SEO_Settings' ) ? FW_SEO_Settings::get( 'redirects_auto', true ) : true;

		// A switch stores a boolean, but older saves and imports can carry the
		// string forms; only an explicit "off" turns this off.
		return ! in_array( $value, [ false, 0, '0', 'no', 'false', '' ], true );
	}

	/* ---------------------------------------------------------------------- *
	 * Storage
	 * ---------------------------------------------------------------------- */

	/**
	 * @return array path => {type, id, created}
	 */
	public static function all() {
		$map = get_option( self::OPTION, [] );

		return is_array( $map ) ? $map : [];
	}

	protected static function save( array $map ) {
		// Not autoloaded: read on a 404 and on one admin screen, never on the
		// ordinary page view that autoload exists to speed up.
		update_option( self::OPTION, $map, false );
	}

	/**
	 * The comparable form of a URL or path: its path only, no trailing slash,
	 * lower-case (WordPress slugs are lower-case; people's links are not).
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	public static function path( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$path = '/' . trim( rawurldecode( $path ), '/' );

		return strtolower( $path );
	}

	/**
	 * Record that $old_url now belongs to an object.
	 *
	 * @param string $old_url
	 * @param string $new_url
	 * @param string $type post|term
	 * @param int    $id
	 *
	 * @return bool Whether anything was recorded.
	 */
	public static function record( $old_url, $new_url, $type, $id ) {
		$old = self::path( $old_url );
		$new = self::path( $new_url );

		if ( '/' === $old || $old === $new ) {
			return false;
		}

		$map = self::all();

		// The new address is live again: whatever pointed it somewhere else is over.
		unset( $map[ $new ] );

		$map[ $old ] = [ 'type' => $type, 'id' => (int) $id, 'created' => time() ];
		self::save( $map );

		return true;
	}

	/**
	 * Where a stored entry points now, or '' when it no longer points anywhere.
	 *
	 * @param array $entry
	 *
	 * @return string
	 */
	public static function target( array $entry ) {
		$id = (int) ( $entry['id'] ?? 0 );

		if ( 'term' === ( $entry['type'] ?? '' ) ) {
			$link = get_term_link( $id );

			return is_wp_error( $link ) ? '' : (string) $link;
		}

		if ( 'publish' !== get_post_status( $id ) ) {
			return '';
		}

		return (string) get_permalink( $id );
	}

	/* ---------------------------------------------------------------------- *
	 * Capturing changes
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 *
	 * @param int     $post_id
	 * @param WP_Post $after
	 * @param WP_Post $before
	 */
	public static function _on_post_updated( $post_id, $after, $before ) {
		if ( ! self::enabled() ) {
			return;
		}

		if ( 'publish' !== $before->post_status || 'publish' !== $after->post_status ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Only slug and parent changes move a URL here; core covers the
		// non-hierarchical slug case, and doing it twice would be harmless but
		// pointless, so hierarchical types and parent moves are ours.
		$moved = $before->post_name !== $after->post_name || (int) $before->post_parent !== (int) $after->post_parent;

		if ( ! $moved ) {
			return;
		}

		$old = self::permalink_as( $before );
		$new = (string) get_permalink( $after );

		if ( ! self::record( $old, $new, 'post', $post_id ) ) {
			return;
		}

		// A moved page takes its children with it: every descendant's address
		// changed too, and each gets its own entry.
		if ( is_post_type_hierarchical( $after->post_type ) ) {
			$old_prefix = self::path( $old );
			$new_prefix = self::path( $new );

			foreach ( get_pages( [ 'child_of' => $post_id, 'post_type' => $after->post_type, 'post_status' => 'publish' ] ) as $child ) {
				$child_new = (string) get_permalink( $child );
				$child_path = self::path( $child_new );

				if ( 0 === strpos( $child_path, $new_prefix . '/' ) ) {
					self::record( $old_prefix . substr( $child_path, strlen( $new_prefix ) ), $child_new, 'post', $child->ID );
				}
			}
		}
	}

	/**
	 * The permalink a post HAD, from its pre-update copy.
	 *
	 * get_permalink( $before ) would read the parent chain from the database,
	 * which already holds the new parent, so the old path is rebuilt from the
	 * old slug and the old parent's (unchanged) permalink.
	 *
	 * @param WP_Post $before
	 *
	 * @return string
	 */
	public static function permalink_as( WP_Post $before ) {
		if ( ! is_post_type_hierarchical( $before->post_type ) || ! $before->post_parent ) {
			return (string) get_permalink( $before );
		}

		$parent = get_permalink( (int) $before->post_parent );

		if ( ! $parent ) {
			return (string) get_permalink( $before );
		}

		return trailingslashit( $parent ) . $before->post_name . '/';
	}

	/** @internal */
	public static function _before_term_edit( $term_id, $taxonomy ) {
		if ( ! self::enabled() ) {
			return;
		}

		$link = get_term_link( (int) $term_id, $taxonomy );

		if ( ! is_wp_error( $link ) ) {
			self::$term_before[ (int) $term_id ] = (string) $link;
		}
	}

	/** @internal */
	public static function _after_term_edit( $term_id, $tt_id, $taxonomy ) {
		if ( ! self::enabled() ) {
			return;
		}

		$old = self::$term_before[ (int) $term_id ] ?? '';

		if ( '' === $old ) {
			return;
		}

		$new = get_term_link( (int) $term_id, $taxonomy );

		if ( ! is_wp_error( $new ) ) {
			self::record( $old, (string) $new, 'term', $term_id );
		}
	}

	/** @internal Content that is gone cannot be a target. */
	public static function _forget_post( $post_id ) {
		self::forget( 'post', (int) $post_id );
	}

	/** @internal */
	public static function _forget_term( $term_id, $tt_id = 0, $taxonomy = '' ) {
		self::forget( 'term', (int) $term_id );
	}

	protected static function forget( $type, $id ) {
		$map = self::all();
		$n   = count( $map );

		$map = array_filter( $map, static function ( $e ) use ( $type, $id ) {
			return ! ( ( $e['type'] ?? '' ) === $type && (int) ( $e['id'] ?? 0 ) === $id );
		} );

		if ( count( $map ) !== $n ) {
			self::save( $map );
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Serving
	 * ---------------------------------------------------------------------- */

	/**
	 * Where a request path should go, or '' for nowhere.
	 *
	 * @param string $request_path
	 *
	 * @return string
	 */
	public static function lookup( $request_path ) {
		$map  = self::all();
		$path = self::path( $request_path );

		if ( ! isset( $map[ $path ] ) ) {
			return '';
		}

		$target = self::target( $map[ $path ] );

		// Never redirect to the address that was asked for.
		return ( '' !== $target && self::path( $target ) !== $path ) ? $target : '';
	}

	/** @internal */
	public static function _maybe_redirect() {
		if ( ! self::enabled() ) {
			return;
		}

		if ( ! is_404() ) {
			return;
		}

		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$target = self::lookup( $uri );

		if ( '' === $target ) {
			return;
		}

		// Keep the query string: a tracked link should still be tracked.
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );

		if ( '' !== $query ) {
			$target .= ( false === strpos( $target, '?' ) ? '?' : '&' ) . $query;
		}

		wp_safe_redirect( $target, 301, 'Unyson+ SEO' );
		exit;
	}

	/* ---------------------------------------------------------------------- *
	 * Admin
	 * ---------------------------------------------------------------------- */

	/** @internal */
	public static function _action_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'fw' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'fw_seo_redirect_delete' );

		$path = isset( $_GET['path'] ) ? self::path( wp_unslash( $_GET['path'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$map  = self::all();

		unset( $map[ $path ] );
		self::save( $map );

		wp_safe_redirect( FW_SEO_Settings_Page::url( 'redirects_tab' ) );
		exit;
	}

	/**
	 * @return string
	 */
	public static function report_html() {
		$map = self::all();

		uasort( $map, static function ( $a, $b ) {
			return (int) $b['created'] <=> (int) $a['created'];
		} );

		ob_start();
		?>
		<div class="fw-seo-redirects">
			<?php if ( ! $map ) : ?>
				<p class="description">
					<?php esc_html_e( 'No addresses have changed yet. When you rename a page, move it under another parent, or rename a category or tag, its old address is added here and sends visitors to the new one.', 'fw' ); ?>
				</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Old address', 'fw' ); ?></th>
							<th><?php esc_html_e( 'Now goes to', 'fw' ); ?></th>
							<th style="width:9em"><?php esc_html_e( 'Since', 'fw' ); ?></th>
							<th style="width:6em"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $map as $path => $entry ) :
							$target = self::target( $entry );
							?>
							<tr>
								<td><code><?php echo esc_html( $path ); ?></code></td>
								<td>
									<?php if ( '' === $target ) : ?>
										<em><?php esc_html_e( 'Nowhere — the content was unpublished, so this address shows a 404.', 'fw' ); ?></em>
									<?php else : ?>
										<a href="<?php echo esc_url( $target ); ?>"><code><?php echo esc_html( self::path( $target ) ); ?></code></a>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $entry['created'] ) ); ?></td>
								<td>
									<a class="button button-small" href="<?php
										echo esc_url( wp_nonce_url(
											add_query_arg( 'path', rawurlencode( $path ), admin_url( 'admin-post.php?action=fw_seo_redirect_delete' ) ),
											'fw_seo_redirect_delete'
										) );
									?>"><?php esc_html_e( 'Remove', 'fw' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p class="description" style="margin-top:1em">
				<?php esc_html_e( 'Ordinary posts renamed by their slug are also redirected by WordPress itself; this covers what it does not — pages, moved parents (every child page moves too), and categories and tags. A redirect only ever fires on an address that would otherwise be a 404, so a new page using an old address always wins.', 'fw' ); ?>
			</p>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
