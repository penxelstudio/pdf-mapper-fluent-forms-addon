<?php
/**
 * Templates admin screen: upload, list, delete.
 *
 * @package FFPM
 */

namespace FFPM\Admin;

use FFPM\Plugin;
use FFPM\Support\Storage;
use FFPM\Templates\PdfValidator;
use FFPM\Templates\TemplateRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the Templates screen and its AJAX endpoints.
 */
final class TemplatesPage {

	private const NONCE_ACTION = 'ffpm_templates';

	/**
	 * Repository instance.
	 */
	private TemplateRepository $templates;

	/**
	 * Set up dependencies.
	 */
	public function __construct() {
		$this->templates = new TemplateRepository();
	}

	/**
	 * Wire hooks.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_ffpm_upload_template', array( $this, 'handle_upload' ) );
		add_action( 'wp_ajax_ffpm_delete_template', array( $this, 'handle_delete' ) );
	}

	/**
	 * Enqueue assets on our screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'toplevel_page_' . Menu::SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'ffpm-pdf-lib',
			FFPM_PLUGIN_URL . 'assets/vendor/pdf-lib/pdf-lib.min.js',
			array(),
			'1.17.1',
			true
		);

		wp_enqueue_script(
			'ffpm-templates',
			FFPM_PLUGIN_URL . 'assets/admin/js/templates-upload.js',
			array( 'ffpm-pdf-lib' ),
			FFPM_VERSION,
			true
		);

		wp_localize_script(
			'ffpm-templates',
			'ffpmTemplates',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'sanitising'   => __( 'Sanitising and converting PDF…', 'pdf-mapper-for-fluent-forms' ),
					'uploading'    => __( 'Uploading…', 'pdf-mapper-for-fluent-forms' ),
					'encrypted'    => __( 'This PDF is password-protected. Remove the password and try again.', 'pdf-mapper-for-fluent-forms' ),
					'invalid'      => __( 'This file could not be read as a PDF.', 'pdf-mapper-for-fluent-forms' ),
					'uploadFailed' => __( 'Upload failed. Please try again.', 'pdf-mapper-for-fluent-forms' ),
					'confirmDel'   => __( 'Delete this template? Feeds using it will stop generating documents.', 'pdf-mapper-for-fluent-forms' ),
				),
			)
		);
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		$rows = $this->templates->all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PDF Templates', 'pdf-mapper-for-fluent-forms' ); ?></h1>

			<div class="card" style="max-width:640px;">
				<h2><?php esc_html_e( 'Upload a new template', 'pdf-mapper-for-fluent-forms' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'The PDF is sanitised in your browser before upload: interactive scripts are removed and the file is converted for generation.', 'pdf-mapper-for-fluent-forms' ); ?>
				</p>
				<form id="ffpm-upload-form">
					<p>
						<label for="ffpm-template-title"><strong><?php esc_html_e( 'Template title', 'pdf-mapper-for-fluent-forms' ); ?></strong></label><br />
						<input type="text" id="ffpm-template-title" name="title" class="regular-text" required maxlength="190" />
					</p>
					<p>
						<label for="ffpm-template-file"><strong><?php esc_html_e( 'PDF file', 'pdf-mapper-for-fluent-forms' ); ?></strong></label><br />
						<input type="file" id="ffpm-template-file" name="file" accept="application/pdf,.pdf" required />
					</p>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Upload template', 'pdf-mapper-for-fluent-forms' ); ?></button>
						<span id="ffpm-upload-status" role="status" aria-live="polite"></span>
					</p>
				</form>
			</div>

			<h2><?php esc_html_e( 'Templates', 'pdf-mapper-for-fluent-forms' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Title', 'pdf-mapper-for-fluent-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Original file', 'pdf-mapper-for-fluent-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Pages', 'pdf-mapper-for-fluent-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Size', 'pdf-mapper-for-fluent-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Uploaded', 'pdf-mapper-for-fluent-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'pdf-mapper-for-fluent-forms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No templates yet. Upload your first PDF above.', 'pdf-mapper-for-fluent-forms' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $row->title ); ?></strong></td>
								<td><?php echo esc_html( $row->original_name ); ?></td>
								<td><?php echo esc_html( (string) $row->page_count ); ?></td>
								<td><?php echo esc_html( size_format( (int) $row->file_size ) ); ?></td>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $row->created_at ) ); ?></td>
								<td>
									<button type="button" class="button-link-delete ffpm-delete-template" data-id="<?php echo esc_attr( (string) $row->id ); ?>">
										<?php esc_html_e( 'Delete', 'pdf-mapper-for-fluent-forms' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * AJAX: store an uploaded (client-sanitised) template.
	 */
	public function handle_upload(): void {
		check_ajax_referer( self::NONCE_ACTION );

		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'pdf-mapper-for-fluent-forms' ) ), 403 );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( '' === $title ) {
			wp_send_json_error( array( 'message' => __( 'Please provide a template title.', 'pdf-mapper-for-fluent-forms' ) ), 400 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES tmp_name is used via is_uploaded_file/move.
		$file = isset( $_FILES['file'] ) ? array_map( 'wp_unslash', $_FILES['file'] ) : null;
		if ( null === $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file received.', 'pdf-mapper-for-fluent-forms' ) ), 400 );
		}

		$result = ( new PdfValidator() )->validate( $file['tmp_name'] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 422 );
		}

		Storage::ensure_directories();

		$stored_name = Storage::random_pdf_name();
		$destination = Storage::dir( 'templates' ) . '/' . $stored_name;

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure handled below.
		if ( ! @move_uploaded_file( $file['tmp_name'], $destination ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not store the file. Check uploads directory permissions.', 'pdf-mapper-for-fluent-forms' ) ), 500 );
		}

		$id = $this->templates->insert(
			array(
				'title'         => $title,
				'file_name'     => $stored_name,
				'original_name' => sanitize_file_name( (string) $file['name'] ),
				'file_hash'     => hash_file( 'sha256', $destination ),
				'file_size'     => (int) filesize( $destination ),
				'page_count'    => $result['page_count'],
				'page_width'    => $result['page_width'],
				'page_height'   => $result['page_height'],
			)
		);

		if ( 0 === $id ) {
			wp_delete_file( $destination );
			wp_send_json_error( array( 'message' => __( 'Database error while saving the template.', 'pdf-mapper-for-fluent-forms' ) ), 500 );
		}

		wp_send_json_success( array( 'id' => $id ) );
	}

	/**
	 * AJAX: delete a template.
	 */
	public function handle_delete(): void {
		check_ajax_referer( self::NONCE_ACTION );

		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'pdf-mapper-for-fluent-forms' ) ), 403 );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( 0 === $id || ! $this->templates->delete( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Template not found.', 'pdf-mapper-for-fluent-forms' ) ), 404 );
		}

		wp_send_json_success();
	}
}
