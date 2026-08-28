<?php
/**
 * Plugin Name: PDF Mapper for Fluent Forms
 * Plugin URI: https://penxelstudio.com/plugins/pdf-mapper-for-fluent-forms
 * Description: Map Fluent Forms fields onto any uploaded PDF template and auto-generate filled PDFs on submission, attached to notification emails.
 * Version: 0.3.1
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: fluentform
 * Author: Penxel Studio
 * Author URI: https://penxelstudio.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pdf-mapper-for-fluent-forms
 * Domain Path: /languages
 *
 * @package FFPM
 */

defined( 'ABSPATH' ) || exit;

define( 'FFPM_VERSION', '0.3.1' );
define( 'FFPM_PLUGIN_FILE', __FILE__ );
define( 'FFPM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FFPM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Composer autoloader (bundled in distributable zip).
if ( is_readable( FFPM_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require FFPM_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Admin notice helper for unmet requirements.
 *
 * @param string $message Translated message to display.
 */
function ffpm_requirement_notice( string $message ): void {
	add_action(
		'admin_notices',
		static function () use ( $message ): void {
			printf(
				'<div class="notice notice-error"><p><strong>%s:</strong> %s</p></div>',
				esc_html__( 'PDF Mapper for Fluent Forms', 'pdf-mapper-for-fluent-forms' ),
				esc_html( $message )
			);
		}
	);
}

/**
 * Check runtime requirements before booting.
 */
function ffpm_requirements_met(): bool {
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		ffpm_requirement_notice(
			sprintf(
				/* translators: %s: current PHP version */
				__( 'PHP 8.1 or newer is required. Your server runs PHP %s.', 'pdf-mapper-for-fluent-forms' ),
				PHP_VERSION
			)
		);
		return false;
	}

	if ( ! class_exists( \FFPM\Plugin::class ) ) {
		ffpm_requirement_notice(
			__( 'Plugin autoloader is missing. Please reinstall the plugin from the official zip.', 'pdf-mapper-for-fluent-forms' )
		);
		return false;
	}

	if ( ! defined( 'FLUENTFORM' ) ) {
		ffpm_requirement_notice(
			__( 'Fluent Forms must be installed and active.', 'pdf-mapper-for-fluent-forms' )
		);
		return false;
	}

	return true;
}

/**
 * Boot the plugin once all plugins are loaded (Fluent Forms must load first).
 */
function ffpm_boot(): void {
	if ( ! ffpm_requirements_met() ) {
		return;
	}

	\FFPM\Plugin::instance()->boot();
}
add_action( 'plugins_loaded', 'ffpm_boot', 20 );

register_activation_hook( __FILE__, array( \FFPM\Install\Activator::class, 'activate' ) );
