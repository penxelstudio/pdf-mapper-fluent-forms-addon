<?php
/**
 * Uninstall routine — removes plugin data.
 *
 * Tables and generated documents contain applicant personal data (PDPA), so a
 * full uninstall must leave nothing behind. Deactivation alone keeps all data.
 *
 * @package FFPM
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Drop this site's tables, options and protected storage.
 *
 * Each site on a network has its own prefixed tables, its own storage key and
 * its own uploads directory, so this runs once per site.
 */
function ffpm_uninstall_site(): void {
	global $wpdb;

	foreach ( array( 'documents', 'feeds', 'templates' ) as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'ffpm_' . $table . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	$storage_key = get_option( 'ffpm_storage_key' );

	delete_option( 'ffpm_schema_version' );
	delete_option( 'ffpm_settings' );
	delete_option( 'ffpm_storage_key' );

	if ( ! is_string( $storage_key ) || '' === $storage_key ) {
		return;
	}

	// Remove protected storage (templates + generated documents).
	$uploads  = wp_upload_dir( null, false );
	$base_dir = $uploads['basedir'] . '/ffpm-' . $storage_key;

	if ( ! is_dir( $base_dir ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';

	if ( WP_Filesystem() ) {
		global $wp_filesystem;
		$wp_filesystem->delete( $base_dir, true );
	}
}

// On a network install every site holds its own tables and documents, so
// cleaning only the current site would leave personal data behind.
if ( is_multisite() ) {
	$ffpm_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $ffpm_site_ids as $ffpm_site_id ) {
		switch_to_blog( (int) $ffpm_site_id );
		ffpm_uninstall_site();
		restore_current_blog();
	}
} else {
	ffpm_uninstall_site();
}
