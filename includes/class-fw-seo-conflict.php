<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * What happens when another SEO plugin is active alongside this one.
 *
 * The extension STANDS DOWN rather than competing. Two titles, two meta
 * descriptions and two canonicals on one page is not a merge — and two
 * canonicals is worse than either alone, because a search engine is entitled to
 * ignore both. Warning while continuing to emit would leave the site in that
 * state until somebody read the warning; standing down means it is never in it.
 *
 * What does NOT stop: the admin side. The metabox, the list columns and the
 * settings keep working, so everything already written stays stored and comes
 * straight back the moment the other plugin is switched off. An extension that
 * silently discarded your data would be a far worse neighbour than one that
 * stops printing tags.
 */
class FW_SEO_Conflict {

	const NOTICE_DISMISSED = 'fw_seo_conflict_notice_dismissed';

	/**
	 * The conflicting plugin's name, or '' when there is none.
	 *
	 * @return string
	 */
	public static function detected() {
		if ( ! function_exists( 'fw_upw_seo_plugin_active' ) ) {
			return '';
		}

		return fw_upw_seo_plugin_active();
	}

	/**
	 * Should this extension emit anything to the front end?
	 *
	 * @return bool
	 */
	public static function should_emit() {
		$emit = '' === self::detected();

		/**
		 * Filters whether the SEO extension emits its tags at all.
		 *
		 * Returning true while another SEO plugin is active deliberately
		 * re-enables the duplicates — only do it if you have switched the other
		 * plugin's output off yourself.
		 */
		return (bool) apply_filters( 'fw_seo_should_emit', $emit );
	}

	/**
	 * Wire the admin notice. Front-end suppression is handled where the tags
	 * are emitted, not here.
	 */
	public static function init() {
		add_action( 'admin_notices', [ __CLASS__, '_action_notice' ] );
		add_action( 'wp_ajax_fw_seo_dismiss_conflict', [ __CLASS__, '_action_dismiss' ] );
	}

	/**
	 * @internal
	 */
	public static function _action_notice() {
		$plugin = self::detected();

		if ( '' === $plugin || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_option( self::NOTICE_DISMISSED ) === $plugin ) {
			return;
		}

		$settings = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=fw-seo-settings' ) : '';
		?>
		<div class="notice notice-warning is-dismissible fw-seo-conflict-notice"
			 data-plugin="<?php echo esc_attr( $plugin ); ?>"
			 data-nonce="<?php echo esc_attr( wp_create_nonce( 'fw_seo_dismiss_conflict' ) ); ?>">
			<p>
				<strong><?php
					/* translators: %s: the other SEO plugin's name. */
					printf( esc_html__( '%s is active, so the Unyson+ SEO extension has stopped emitting tags.', 'fw' ), esc_html( $plugin ) );
				?></strong>
			</p>
			<p>
				<?php
				/* translators: %s: the other SEO plugin's name. */
				printf(
					esc_html__( 'Both would otherwise write a title, a meta description and a canonical URL into every page. Two canonicals is worse than one wrong one — a search engine may disregard both — so only %s is describing your pages right now.', 'fw' ),
					esc_html( $plugin )
				);
				?>
			</p>
			<p>
				<?php esc_html_e( 'Nothing has been deleted. Anything you had written here is still stored and comes back if you switch the other plugin off. Run one of them, not both:', 'fw' ); ?>
			</p>
			<ul style="list-style:disc;margin-left:1.6em">
				<li><?php
					/* translators: %s: the other SEO plugin's name. */
					printf( esc_html__( 'Keep %s — deactivate the Unyson+ SEO extension so it is not sitting there doing nothing.', 'fw' ), esc_html( $plugin ) );
				?></li>
				<li><?php
					/* translators: %1$s: the other plugin, %2$s: settings URL. */
					printf(
						wp_kses( __( 'Move here — the extension can <a href="%2$s">import your titles and descriptions from %1$s</a> first, then deactivate it.', 'fw' ), [ 'a' => [ 'href' => [] ] ] ),
						esc_html( $plugin ),
						esc_url( $settings . '#import_tab' )
					);
				?></li>
			</ul>
		</div>
		<script>
		( function () {
			var n = document.querySelector( '.fw-seo-conflict-notice' );
			if ( ! n ) { return; }
			n.addEventListener( 'click', function ( e ) {
				if ( ! e.target.classList.contains( 'notice-dismiss' ) ) { return; }
				var b = new FormData();
				b.append( 'action', 'fw_seo_dismiss_conflict' );
				b.append( 'nonce', n.getAttribute( 'data-nonce' ) );
				b.append( 'plugin', n.getAttribute( 'data-plugin' ) );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: b } );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Remember the dismissal against the plugin it was about, so installing a
	 * DIFFERENT SEO plugin later raises the notice again rather than inheriting
	 * a dismissal that was never about it.
	 *
	 * @internal
	 */
	public static function _action_dismiss() {
		check_ajax_referer( 'fw_seo_dismiss_conflict', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$plugin = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';

		update_option( self::NOTICE_DISMISSED, $plugin, false );

		wp_send_json_success();
	}
}
