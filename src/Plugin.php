<?php
/**
 * Main plugin orchestrator.
 *
 * @package FFPM
 */

namespace FFPM;

use FFPM\Admin\DocumentsPage;
use FFPM\Admin\FeedsPage;
use FFPM\Admin\Menu;
use FFPM\Admin\TemplatesPage;
use FFPM\Install\Activator;

defined( 'ABSPATH' ) || exit;

/**
 * Boots and wires all plugin services.
 */
final class Plugin {

	/**
	 * Capability required for all plugin admin screens and endpoints.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Retrieve the shared instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire hooks. Called once on plugins_loaded.
	 */
	public function boot(): void {
		Activator::maybe_upgrade();

		// Submissions arrive over AJAX (is_admin() is true there), so the
		// runtime integration registers unconditionally.
		( new Integration\FluentForms() )->register();

		if ( is_admin() ) {
			$templates_page = new TemplatesPage();
			$templates_page->register();

			$feeds_page = new FeedsPage();
			$feeds_page->register();

			$documents_page = new DocumentsPage();
			$documents_page->register();

			( new Menu( $templates_page, $feeds_page, $documents_page ) )->register();
		}
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {
	}
}
