<?php
/**
 * Generated documents admin screen.
 *
 * @package FFPM
 */

namespace FFPM\Admin;

use FFPM\Integration\FluentForms;
use FFPM\Integration\FormFieldsReader;
use FFPM\Mapping\FeedRepository;
use FFPM\Plugin;
use FFPM\Submission\DocumentRepository;
use FFPM\Support\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Lists generated documents with download / regenerate / resend / delete / CSV.
 */
final class DocumentsPage {

	private const NONCE_ACTION = 'ffpm_documents';
	private const PER_PAGE     = 20;

	/**
	 * Document repository.
	 */
	private DocumentRepository $documents;

	/**
	 * Feed repository.
	 */
	private FeedRepository $feeds;

	/**
	 * Form reader.
	 */
	private FormFieldsReader $reader;

	/**
	 * Set up dependencies.
	 */
	public function __construct() {
		$this->documents = new DocumentRepository();
		$this->feeds     = new FeedRepository();
		$this->reader    = new FormFieldsReader();
	}

	/**
	 * Wire hooks.
	 */
	public function register(): void {
		add_action( 'wp_ajax_ffpm_download_document', array( $this, 'handle_download' ) );
		add_action( 'wp_ajax_ffpm_delete_document', array( $this, 'handle_delete' ) );
		add_action( 'wp_ajax_ffpm_regenerate_document', array( $this, 'handle_regenerate' ) );
		add_action( 'wp_ajax_ffpm_resend_document', array( $this, 'handle_resend' ) );
		add_action( 'wp_ajax_ffpm_export_documents_csv', array( $this, 'handle_export_csv' ) );
	}

	/**
	 * Render the list.
	 */
	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = $this->documents->paged( $form_id, $page, self::PER_PAGE );
		$pages  = (int) ceil( $result['total'] / self::PER_PAGE );
		$nonce  = wp_create_nonce( self::NONCE_ACTION );

		$csv_url = add_query_arg(
			array(
				'action'      => 'ffpm_export_documents_csv',
				'form_id'     => $form_id,
				'_ajax_nonce' => $nonce,
			),
			admin_url( 'admin-ajax.php' )
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Generated Documents', 'pdf-mapper-for-fluent-forms' ); ?></h1>

			<form method="get" style="margin:12px 0;">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::SLUG . '-documents' ); ?>" />
				<label for="ffpm-filter-form"><?php esc_html_e( 'Form:', 'pdf-mapper-for-fluent-forms' ); ?></label>
				<select name="form_id" id="ffpm-filter-form">
					<option value="0"><?php esc_html_e( 'All forms', 'pdf-mapper-for-fluent-forms' ); ?></option>
					<?php foreach ( $this->reader->forms() as $form ) : ?>
						<option value="<?php echo esc_attr( (string) $form['id'] ); ?>" <?php selected( $form_id, $form['id'] ); ?>>#<?php echo esc_html( (string) $form['id'] ); ?> — <?php echo esc_html( $form['title'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button"><?php esc_html_e( 'Filter', 'pdf-mapper-for-fluent-forms' ); ?></button>
				<a class="button" href="<?php echo esc_url( $csv_url ); ?>"><?php esc_html_e( 'Export CSV', 'pdf-mapper-for-fluent-forms' ); ?></a>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" style="width:60px;">ID</th>
						<th scope="col"><?php esc_html_e( 'File', 'pdf-mapper-for-fluent-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Form / Submission', 'pdf-mapper-for-fluent-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Feed', 'pdf-mapper-for-fluent-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'pdf-mapper-for-fluent-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Date', 'pdf-mapper-for-fluent-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'pdf-mapper-for-fluent-forms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $result['rows'] ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No documents yet.', 'pdf-mapper-for-fluent-forms' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $result['rows'] as $row ) : ?>
							<?php
							$feed         = $this->feeds->find( (int) $row->feed_id );
							$download_url = add_query_arg(
								array(
									'action'      => 'ffpm_download_document',
									'id'          => (int) $row->id,
									'_ajax_nonce' => $nonce,
								),
								admin_url( 'admin-ajax.php' )
							);
							$entry_url    = admin_url( 'admin.php?page=fluent_forms&route=entries&form_id=' . (int) $row->form_id . '#/entries/' . (int) $row->submission_id );
							?>
							<tr>
								<td><?php echo esc_html( (string) $row->id ); ?></td>
								<td>
									<?php if ( 'generated' === $row->status ) : ?>
										<a href="<?php echo esc_url( $download_url ); ?>"><code><?php echo esc_html( $row->file_name ); ?></code></a>
									<?php else : ?>
										<em><?php echo esc_html( $row->error_message ); ?></em>
									<?php endif; ?>
								</td>
								<td>#<?php echo esc_html( (string) $row->form_id ); ?> / <a href="<?php echo esc_url( $entry_url ); ?>">#<?php echo esc_html( (string) $row->submission_id ); ?></a></td>
								<td><?php echo esc_html( $feed ? $feed->title : '—' ); ?></td>
								<td><?php echo esc_html( $row->status ); ?></td>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $row->created_at ) ); ?></td>
								<td>
									<button type="button" class="button button-small ffpm-doc-action" data-action="ffpm_regenerate_document" data-id="<?php echo esc_attr( (string) $row->id ); ?>"><?php esc_html_e( 'Regenerate', 'pdf-mapper-for-fluent-forms' ); ?></button>
									<?php if ( 'generated' === $row->status ) : ?>
										<button type="button" class="button button-small ffpm-doc-resend" data-id="<?php echo esc_attr( (string) $row->id ); ?>"><?php esc_html_e( 'Resend', 'pdf-mapper-for-fluent-forms' ); ?></button>
									<?php endif; ?>
									<button type="button" class="button-link-delete ffpm-doc-action" data-action="ffpm_delete_document" data-confirm="1" data-id="<?php echo esc_attr( (string) $row->id ); ?>"><?php esc_html_e( 'Delete', 'pdf-mapper-for-fluent-forms' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						(string) paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $page,
								'total'   => $pages,
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<script>
		( function () {
			document.addEventListener( 'click', function ( e ) {
				var resend = e.target.closest( '.ffpm-doc-resend' );
				var action = e.target.closest( '.ffpm-doc-action' );
				var nonce = <?php echo wp_json_encode( $nonce ); ?>;

				function post( params ) {
					var data = new FormData();
					Object.keys( params ).forEach( function ( k ) { data.append( k, params[ k ] ); } );
					data.append( '_ajax_nonce', nonce );
					return fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
						.then( function ( r ) { return r.json(); } );
				}

				if ( resend ) {
					var email = window.prompt( <?php echo wp_json_encode( __( 'Send this PDF to email address:', 'pdf-mapper-for-fluent-forms' ) ); ?> );
					if ( ! email ) { return; }
					post( { action: 'ffpm_resend_document', id: resend.getAttribute( 'data-id' ), email: email } )
						.then( function ( json ) {
							window.alert( json && json.success
								? <?php echo wp_json_encode( __( 'Email sent.', 'pdf-mapper-for-fluent-forms' ) ); ?>
								: ( json && json.data && json.data.message ) || 'Error' );
						} );
					return;
				}

				if ( action ) {
					if ( action.getAttribute( 'data-confirm' ) && ! window.confirm( <?php echo wp_json_encode( __( 'Delete this document permanently?', 'pdf-mapper-for-fluent-forms' ) ); ?> ) ) {
						return;
					}
					post( { action: action.getAttribute( 'data-action' ), id: action.getAttribute( 'data-id' ) } )
						.then( function ( json ) {
							if ( json && json.success ) { window.location.reload(); } else { window.alert( ( json && json.data && json.data.message ) || 'Error' ); }
						} );
				}
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * AJAX: stream a document to the admin.
	 */
	public function handle_download(): void {
		check_ajax_referer( self::NONCE_ACTION );
		$this->assert_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$document = $this->documents->find( $id );
		$path     = $document ? Storage::path( 'documents', (string) $document->file_name ) : null;

		if ( null === $path ) {
			wp_die( esc_html__( 'File not found.', 'pdf-mapper-for-fluent-forms' ), '', array( 'response' => 404 ) );
		}

		// Discard any buffer another plugin left open, or its filters would
		// rewrite the PDF bytes and contradict the Content-Length below.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( (string) $document->file_name ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * AJAX: delete a document.
	 */
	public function handle_delete(): void {
		check_ajax_referer( self::NONCE_ACTION );
		$this->assert_capability();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( 0 === $id || ! $this->documents->delete( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Document not found.', 'pdf-mapper-for-fluent-forms' ) ), 404 );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: regenerate a document from its original submission data.
	 */
	public function handle_regenerate(): void {
		check_ajax_referer( self::NONCE_ACTION );
		$this->assert_capability();

		$id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$document = $this->documents->find( $id );
		if ( null === $document ) {
			wp_send_json_error( array( 'message' => __( 'Document not found.', 'pdf-mapper-for-fluent-forms' ) ), 404 );
		}

		$feed = $this->feeds->find( (int) $document->feed_id );
		if ( null === $feed ) {
			wp_send_json_error( array( 'message' => __( 'The feed for this document was deleted.', 'pdf-mapper-for-fluent-forms' ) ), 410 );
		}

		$form_data = $this->submission_data( (int) $document->submission_id );
		if ( null === $form_data ) {
			wp_send_json_error( array( 'message' => __( 'Submission data not found.', 'pdf-mapper-for-fluent-forms' ) ), 404 );
		}

		$new_id = ( new FluentForms() )->generate_for_feed( $feed, (int) $document->submission_id, $form_data );
		if ( 0 === $new_id ) {
			wp_send_json_error( array( 'message' => __( 'Generation failed — check the new row for the error.', 'pdf-mapper-for-fluent-forms' ) ), 500 );
		}

		wp_send_json_success( array( 'id' => $new_id ) );
	}

	/**
	 * AJAX: email a document to a given address.
	 */
	public function handle_resend(): void {
		check_ajax_referer( self::NONCE_ACTION );
		$this->assert_capability();

		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid email address.', 'pdf-mapper-for-fluent-forms' ) ), 400 );
		}

		$document = $this->documents->find( $id );
		$path     = $document ? Storage::path( 'documents', (string) $document->file_name ) : null;
		if ( null === $path ) {
			wp_send_json_error( array( 'message' => __( 'File not found.', 'pdf-mapper-for-fluent-forms' ) ), 404 );
		}

		$sent = wp_mail(
			$email,
			sprintf(
				/* translators: %s: site name */
				__( 'Document from %s', 'pdf-mapper-for-fluent-forms' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			__( 'Please find the requested document attached.', 'pdf-mapper-for-fluent-forms' ),
			array(),
			array( $path )
		);

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'Email could not be sent — check your SMTP configuration.', 'pdf-mapper-for-fluent-forms' ) ), 500 );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: CSV export of the document log.
	 */
	public function handle_export_csv(): void {
		check_ajax_referer( self::NONCE_ACTION );
		$this->assert_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;
		$result  = $this->documents->paged( $form_id, 1, 10000 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ffpm-documents-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'id', 'form_id', 'submission_id', 'feed_id', 'file_name', 'status', 'error', 'created_at' ) );
		foreach ( $result['rows'] as $row ) {
			fputcsv(
				$out,
				array_map(
					array( $this, 'csv_cell' ),
					array( $row->id, $row->form_id, $row->submission_id, $row->feed_id, $row->file_name, $row->status, $row->error_message, $row->created_at )
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Neutralise a CSV cell that a spreadsheet would evaluate as a formula.
	 *
	 * Excel and Sheets treat a leading =, +, -, @, tab or CR as the start of a
	 * formula, so a leading apostrophe is prepended to force a literal string.
	 *
	 * @param mixed $value Raw cell value.
	 * @return mixed Safe cell value.
	 */
	private function csv_cell( $value ) {
		if ( is_string( $value ) && 1 === preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Fetch decoded submission data from Fluent Forms.
	 *
	 * @param int $submission_id Submission id.
	 * @return array<string,mixed>|null
	 */
	private function submission_data( int $submission_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentform_submissions';

		$json = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT response FROM {$table} WHERE id = %d", $submission_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( ! is_string( $json ) ) {
			return null;
		}

		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Die unless the current user may manage the plugin.
	 */
	private function assert_capability(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'pdf-mapper-for-fluent-forms' ) ), 403 );
		}
	}
}
