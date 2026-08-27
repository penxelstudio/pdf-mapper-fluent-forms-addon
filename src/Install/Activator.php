<?php
/**
 * Activation and upgrade routines.
 *
 * @package FFPM
 */

namespace FFPM\Install;

use FFPM\Support\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Creates tables and storage on activation; runs upgrades on version bumps.
 */
final class Activator {

	private const OPTION_SCHEMA_VERSION = 'ffpm_schema_version';

	/**
	 * Activation hook callback.
	 */
	public static function activate(): void {
		self::install_schema();
		Storage::ensure_directories();
		update_option( self::OPTION_SCHEMA_VERSION, Schema::VERSION );
	}

	/**
	 * Run schema upgrades when the stored version is behind. Called on boot.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION_SCHEMA_VERSION ) === Schema::VERSION ) {
			return;
		}

		self::install_schema();
		Storage::ensure_directories();
		update_option( self::OPTION_SCHEMA_VERSION, Schema::VERSION );
	}

	/**
	 * Create or update plugin tables via dbDelta.
	 */
	private static function install_schema(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::statements() as $statement ) {
			dbDelta( $statement );
		}
	}
}
