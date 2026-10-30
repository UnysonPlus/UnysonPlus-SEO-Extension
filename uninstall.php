<?php
/**
 * Runs when the SEO extension is deleted from the Extensions screen, just
 * before its folder goes (see FW_Extensions_Manager::run_extension_uninstall_file).
 *
 * It deletes nothing unless the site owner switched on "Delete all SEO data
 * when the extension is deleted" (General settings). Off by default: SEO titles
 * and descriptions are hard to rebuild, and people delete an extension to
 * reinstall it more often than to leave for good.
 *
 * Self-contained on purpose: the extension may be inactive, so none of its
 * classes are loaded here.
 */

if ( ! defined( 'FW_EXTENSION_UNINSTALL' ) ) {
	return;
}

if ( ! function_exists( 'fw_seo_uninstall_site' ) ) {
	/**
	 * Remove every trace of the extension from the current site.
	 *
	 * @param bool $only_if_asked Check the site's own setting first.
	 */
	function fw_seo_uninstall_site( $only_if_asked = true ) {
		global $wpdb;

		$settings = get_option( 'fw_ext_settings_options:seo', [] );

		if ( $only_if_asked && empty( $settings['delete_on_uninstall'] ) ) {
			return false;
		}

		$like = static function ( $prefix ) use ( $wpdb ) {
			return $wpdb->esc_like( $prefix ) . '%';
		};

		delete_option( 'fw_ext_settings_options:seo' );

		// Options, plus transients (stored as options) and their timeouts.
		foreach ( [ 'fw_seo_', '_transient_fw_seo_', '_transient_timeout_fw_seo_', '_site_transient_fw_seo_', '_site_transient_timeout_fw_seo_' ] as $prefix ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like( $prefix ) ) );
		}

		// Per-post and per-term values: titles, descriptions, index status, social overrides…
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $like( '_fw_seo_' ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $like( '_fw_seo_' ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $like( 'fw_seo_' ) ) );

		// The site-search log.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fw_seo_search_terms" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fw_seo_links" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

		foreach ( [ 'fw_seo_inspect_sweep', 'fw_seo_search_log_prune', 'fw_seo_health_check', 'fw_seo_alerts_collect', 'fw_seo_alerts_email', 'fw_seo_links_batch' ] as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		// Generated sharing-card images.
		$cards = function_exists( 'fw_upw_uploads_dir' )
			? fw_upw_uploads_dir( 'social-cards' )['path']
			: trailingslashit( wp_upload_dir()['basedir'] ) . 'unysonplus/social-cards';

		if ( is_dir( $cards ) ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $cards, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
				$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			@rmdir( $cards ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		// Its rewrite rules (sitemap.xml and friends) go with it.
		delete_option( 'rewrite_rules' );

		return true;
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $fw_seo_blog ) {
		switch_to_blog( $fw_seo_blog );
		fw_seo_uninstall_site();
		restore_current_blog();
	}
} else {
	fw_seo_uninstall_site();
}
