<?php
/**
 * Admin menu registration.
 *
 * @package FFPM
 */

namespace FFPM\Admin;

use FFPM\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top-level admin menu and subpages.
 */
final class Menu {

	public const SLUG = 'ffpm';

	/**
	 * Templates screen controller.
	 */
	private TemplatesPage $templates_page;

	/**
	 * Feeds screen controller.
	 */
	private FeedsPage $feeds_page;

	/**
	 * Documents screen controller.
	 */
	private DocumentsPage $documents_page;

	/**
	 * Set up dependencies.
	 *
	 * @param TemplatesPage $templates_page Templates screen controller.
	 * @param FeedsPage     $feeds_page     Feeds screen controller.
	 * @param DocumentsPage $documents_page Documents screen controller.
	 */
	public function __construct( TemplatesPage $templates_page, FeedsPage $feeds_page, DocumentsPage $documents_page ) {
		$this->templates_page = $templates_page;
		$this->feeds_page     = $feeds_page;
		$this->documents_page = $documents_page;
	}

	/**
	 * Hook menu registration.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
	}

	/**
	 * Register menu pages.
	 */
	public function add_pages(): void {
		add_menu_page(
			__( 'PDF Mapper', 'pdf-mapper-for-fluent-forms' ),
			__( 'PDF Mapper', 'pdf-mapper-for-fluent-forms' ),
			Plugin::CAPABILITY,
			self::SLUG,
			array( $this, 'render_templates_page' ),
			'dashicons-media-document',
			26
		);

		add_submenu_page(
			self::SLUG,
			__( 'PDF Templates', 'pdf-mapper-for-fluent-forms' ),
			__( 'Templates', 'pdf-mapper-for-fluent-forms' ),
			Plugin::CAPABILITY,
			self::SLUG,
			array( $this, 'render_templates_page' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'PDF Feeds', 'pdf-mapper-for-fluent-forms' ),
			__( 'Feeds', 'pdf-mapper-for-fluent-forms' ),
			Plugin::CAPABILITY,
			self::SLUG . '-feeds',
			array( $this, 'render_feeds_page' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Generated Documents', 'pdf-mapper-for-fluent-forms' ),
			__( 'Documents', 'pdf-mapper-for-fluent-forms' ),
			Plugin::CAPABILITY,
			self::SLUG . '-documents',
			array( $this, 'render_documents_page' )
		);
	}

	/**
	 * Templates screen.
	 */
	public function render_templates_page(): void {
		$this->templates_page->render();
	}

	/**
	 * Feeds / mapper screen.
	 */
	public function render_feeds_page(): void {
		$this->feeds_page->render();
	}

	/**
	 * Documents screen.
	 */
	public function render_documents_page(): void {
		$this->documents_page->render();
	}
}
