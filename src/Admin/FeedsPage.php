<?php
/**
 * Feeds admin screen: list, create, delete, and the visual mapper.
 *
 * @package FFPM
 */

namespace FFPM\Admin;

use FFPM\Integration\FormFieldsReader;
use FFPM\Mapping\FeedRepository;
use FFPM\Plugin;
use FFPM\Support\Storage;
use FFPM\Templates\TemplateRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the Feeds screen, the mapper, and their AJAX endpoints.
 */
final class FeedsPage {

	private const NONCE_ACTION = 'ffpm_feeds';

	/**
	 * Feed repository.
	 */
	private FeedRepository $feeds;

	/**
	 * Template repository.
	 */
	private TemplateRepository $templates;

	/**
	 * Fluent Forms reader.
	 */
	private FormFieldsReader $reader;

	/**
	 * Set up dependencies.
	 */
	public function __construct() {
		$this->feeds     = new FeedRepository();
		$this->templates = new TemplateRepository();
		$this->reader    = new FormFieldsReader();
	}

	/**
	 * Wire hooks.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( $this, 'module_script_tag' ), 10, 3 );
		add_action( 'wp_ajax_ffpm_create_feed', array( $this, 'handle_create' ) );
		add_action( 'wp_ajax_ffpm_delete_feed', array( $this, 'handle_delete' ) );
		add_action( 'wp_ajax_ffpm_mapper_data', array( $this, 'handle_mapper_data' ) );
		add_action( 'wp_ajax_ffpm_template_file', array( $this, 'handle_template_file' ) );
		add_action( 'wp_ajax_ffpm_save_mapping', array( $this, 'handle_save_mapping' ) );
	}

	/**
	 * Enqueue mapper assets on the Feeds screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'pdf-mapper_page_' . Menu::SLUG . '-feeds' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'ffpm-mapper', FFPM_PLUGIN_URL . 'assets/admin/css/mapper.css', array(), FFPM_VERSION );

		// PDF.js 6 ships as an ES module, so the mapper is a module too and
		// imports the library itself — no separate script handle, and the
		// browser resolves the worker as a module worker from workerSrc.
		wp_enqueue_script( 'ffpm-mapper', FFPM_PLUGIN_URL . 'assets/admin/js/mapper.js', array(), FFPM_VERSION, true );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$feed_id = isset( $_GET['feed'] ) ? absint( $_GET['feed'] ) : 0;

		wp_localize_script(
			'ffpm-mapper',
			'ffpmMapper',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
				'feedId'    => $feed_id,
				// Both files hold ES module source but keep the .js extension:
				// module scripts and module workers are rejected unless the host
				// serves a JavaScript MIME type, and .mjs is still served as
				// application/octet-stream on plenty of shared hosting.
				'pdfjsSrc'  => FFPM_PLUGIN_URL . 'assets/vendor/pdfjs/pdf.min.js',
				'workerSrc' => FFPM_PLUGIN_URL . 'assets/vendor/pdfjs/pdf.worker.min.js',
				'i18n'      => array(
					'unmapped'       => __( '— not mapped —', 'pdf-mapper-for-fluent-forms' ),
					'saved'          => __( 'Mapping saved.', 'pdf-mapper-for-fluent-forms' ),
					'saveFailed'     => __( 'Save failed. Please try again.', 'pdf-mapper-for-fluent-forms' ),
					/* translators: %s: the form field the removed binding was mapped to */
					'bindingRemoved' => __( 'Removed binding: %s. Save the mapping to keep this change.', 'pdf-mapper-for-fluent-forms' ),
					'rowNumber'      => __( 'Row number (auto)', 'pdf-mapper-for-fluent-forms' ),
					'badImport'      => __( 'That file is not a valid mapping export.', 'pdf-mapper-for-fluent-forms' ),
					'rowHeightZero'  => __( 'Row height is 0, so all repeater rows will print on top of each other. Set a row height (the vertical distance between rows, in points), or click Save again to save anyway.', 'pdf-mapper-for-fluent-forms' ),
				),
			)
		);
	}

	/**
	 * Emit the mapper script as an ES module.
	 *
	 * WordPress has no core way to mark a classic handle as a module, and
	 * wp_enqueue_script_module() would not let wp_localize_script() attach the
	 * config object, so the tag is rewritten here instead.
	 *
	 * @param string $tag    Full script tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script source URL.
	 */
	public function module_script_tag( string $tag, string $handle, string $src ): string {
		if ( 'ffpm-mapper' !== $handle ) {
			return $tag;
		}

		// This IS the enqueued script; script_loader_tag exists to rewrite its tag.
		$template = '<script type="module" src="%s" id="ffpm-mapper-js"></script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript

		return sprintf( $template, esc_url( $src ) );
	}

	/**
	 * Render list or mapper depending on the feed query arg.
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$feed_id = isset( $_GET['feed'] ) ? absint( $_GET['feed'] ) : 0;

		if ( $feed_id > 0 ) {
			$this->render_mapper( $feed_id );
			return;
		}

		$this->render_list();
	}

	/**
	 * Render the feed list + create form.
	 */
	private function render_list(): void {
		$rows      = $this->feeds->all();
		$forms     = $this->reader->forms();
		$templates = $this->templates->all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PDF Feeds', 'pdf-mapper-for-fluent-forms' ); ?></h1>
			<p class="description"><?php esc_html_e( 'A feed connects a Fluent Form to a PDF template. Each submission generates one document per active feed.', 'pdf-mapper-for-fluent-forms' ); ?></p>

			<div class="card" style="max-width:640px;">
				<h2><?php esc_html_e( 'Create feed', 'pdf-mapper-for-fluent-forms' ); ?></h2>
				<form id="ffpm-create-feed">
					<p>
						<label for="ffpm-feed-title"><strong><?php esc_html_e( 'Feed title', 'pdf-mapper-for-fluent-forms' ); ?></strong></label><br />
						<input type="text" id="ffpm-feed-title" class="regular-text" required maxlength="190" />
					</p>
					<p>
						<label for="ffpm-feed-form"><strong><?php esc_html_e( 'Fluent Form', 'pdf-mapper-for-fluent-forms' ); ?></strong></label><br />
						<select id="ffpm-feed-form" required>
							<option value=""><?php esc_html_e( 'Select a form…', 'pdf-mapper-for-fluent-forms' ); ?></option>
							<?php foreach ( $forms as $form ) : ?>
								<option value="<?php echo esc_attr( (string) $form['id'] ); ?>">#<?php echo esc_html( (string) $form['id'] ); ?> — <?php echo esc_html( $form['title'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p>
						<label for="ffpm-feed-template"><strong><?php esc_html_e( 'PDF template', 'pdf-mapper-for-fluent-forms' ); ?></strong></label><br />
						<select id="ffpm-feed-template" required>
							<option value=""><?php esc_html_e( 'Select a template…', 'pdf-mapper-for-fluent-forms' ); ?></option>
							<?php foreach ( $templates as $template ) : ?>
								<option value="<?php echo esc_attr( (string) $template->id ); ?>"><?php echo esc_html( $template->title ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Create & open mapper', 'pdf-mapper-for-fluent-forms' ); ?></button></p>
				</form>
			</div>

			<h2><?php esc_html_e( 'Feeds', 'pdf-mapper-for-fluent-forms' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Feed', 'pdf-mapper-for-fluent-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Form', 'pdf-mapper-for-fluent-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Template', 'pdf-mapper-for-fluent-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'pdf-mapper-for-fluent-forms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'pdf-mapper-for-fluent-forms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No feeds yet.', 'pdf-mapper-for-fluent-forms' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$template   = $this->templates->find( (int) $row->template_id );
							$mapper_url = add_query_arg(
								array(
									'page' => Menu::SLUG . '-feeds',
									'feed' => (int) $row->id,
								),
								admin_url( 'admin.php' )
							);
							?>
							<tr>
								<td><strong><a href="<?php echo esc_url( $mapper_url ); ?>"><?php echo esc_html( $row->title ); ?></a></strong></td>
								<td>#<?php echo esc_html( (string) $row->form_id ); ?> — <?php echo esc_html( $this->reader->form_title( (int) $row->form_id ) ); ?></td>
								<td><?php echo esc_html( $template ? $template->title : __( '(deleted template)', 'pdf-mapper-for-fluent-forms' ) ); ?></td>
								<td><?php echo esc_html( $row->status ); ?></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( $mapper_url ); ?>"><?php esc_html_e( 'Open mapper', 'pdf-mapper-for-fluent-forms' ); ?></a>
									<button type="button" class="button-link-delete ffpm-delete-feed" data-id="<?php echo esc_attr( (string) $row->id ); ?>"><?php esc_html_e( 'Delete', 'pdf-mapper-for-fluent-forms' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<script>
		( function () {
			var createForm = document.getElementById( 'ffpm-create-feed' );
			if ( createForm ) {
				createForm.addEventListener( 'submit', function ( e ) {
					e.preventDefault();
					var data = new FormData();
					data.append( 'action', 'ffpm_create_feed' );
					data.append( '_ajax_nonce', <?php echo wp_json_encode( wp_create_nonce( self::NONCE_ACTION ) ); ?> );
					data.append( 'title', document.getElementById( 'ffpm-feed-title' ).value );
					data.append( 'form_id', document.getElementById( 'ffpm-feed-form' ).value );
					data.append( 'template_id', document.getElementById( 'ffpm-feed-template' ).value );
					fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( json ) {
							if ( json && json.success ) {
								window.location.search = '?page=<?php echo esc_js( Menu::SLUG ); ?>-feeds&feed=' + json.data.id;
							}
						} );
				} );
			}
			document.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.ffpm-delete-feed' );
				if ( ! btn || ! window.confirm( <?php echo wp_json_encode( __( 'Delete this feed and its mapping?', 'pdf-mapper-for-fluent-forms' ) ); ?> ) ) {
					return;
				}
				var data = new FormData();
				data.append( 'action', 'ffpm_delete_feed' );
				data.append( '_ajax_nonce', <?php echo wp_json_encode( wp_create_nonce( self::NONCE_ACTION ) ); ?> );
				data.append( 'id', btn.getAttribute( 'data-id' ) );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( json ) { if ( json && json.success ) { window.location.reload(); } } );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Render the mapper shell — the JS app fills it.
	 *
	 * @param int $feed_id Feed id.
	 */
	private function render_mapper( int $feed_id ): void {
		$feed = $this->feeds->find( $feed_id );
		if ( null === $feed ) {
			wp_die( esc_html__( 'Feed not found.', 'pdf-mapper-for-fluent-forms' ) );
		}

		$list_url = add_query_arg( array( 'page' => Menu::SLUG . '-feeds' ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap ffpm-mapper-wrap">
			<h1>
				<?php esc_html_e( 'Field Mapper', 'pdf-mapper-for-fluent-forms' ); ?>:
				<?php echo esc_html( $feed->title ); ?>
				<a class="page-title-action" href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'Back to feeds', 'pdf-mapper-for-fluent-forms' ); ?></a>
			</h1>

			<div id="ffpm-mapper-app" data-feed="<?php echo esc_attr( (string) $feed_id ); ?>">
				<div class="ffpm-toolbar">
					<button type="button" class="button" id="ffpm-prev-page">&larr;</button>
					<span id="ffpm-page-indicator">1 / 1</span>
					<button type="button" class="button" id="ffpm-next-page">&rarr;</button>
					<span class="ffpm-toolbar-sep"></span>
					<button type="button" class="button" id="ffpm-add-pin"><?php esc_html_e( '+ Place field', 'pdf-mapper-for-fluent-forms' ); ?></button>
					<span class="ffpm-toolbar-sep"></span>
					<button type="button" class="button" id="ffpm-export-json"><?php esc_html_e( 'Export JSON', 'pdf-mapper-for-fluent-forms' ); ?></button>
					<button type="button" class="button" id="ffpm-import-json"><?php esc_html_e( 'Import JSON', 'pdf-mapper-for-fluent-forms' ); ?></button>
					<input type="file" id="ffpm-import-file" accept="application/json,.json" hidden />
					<span class="ffpm-toolbar-sep"></span>
					<button type="button" class="button button-primary" id="ffpm-save"><?php esc_html_e( 'Save mapping', 'pdf-mapper-for-fluent-forms' ); ?></button>
					<span id="ffpm-save-status" role="status" aria-live="polite"></span>
				</div>

				<div class="ffpm-mapper-body">
					<div class="ffpm-canvas-pane">
						<div id="ffpm-canvas-holder">
							<canvas id="ffpm-canvas"></canvas>
							<div id="ffpm-overlay"></div>
						</div>
					</div>

					<div class="ffpm-side-pane">
						<div id="ffpm-binding-panel" class="ffpm-panel" hidden>
							<h3><?php esc_html_e( 'Selected binding', 'pdf-mapper-for-fluent-forms' ); ?></h3>
							<p>
								<label><?php esc_html_e( 'Form field', 'pdf-mapper-for-fluent-forms' ); ?></label>
								<select id="ffpm-bind-source"></select>
							</p>
							<p>
								<label><?php esc_html_e( 'Type', 'pdf-mapper-for-fluent-forms' ); ?></label>
								<select id="ffpm-bind-type">
									<option value="text"><?php esc_html_e( 'Text', 'pdf-mapper-for-fluent-forms' ); ?></option>
									<option value="checkbox"><?php esc_html_e( 'Checkbox (X)', 'pdf-mapper-for-fluent-forms' ); ?></option>
									<option value="image"><?php esc_html_e( 'Image (signature / stamp)', 'pdf-mapper-for-fluent-forms' ); ?></option>
								</select>
							</p>
							<p class="ffpm-if-checkbox" hidden>
								<label><?php esc_html_e( 'Tick when value equals', 'pdf-mapper-for-fluent-forms' ); ?></label>
								<input type="text" id="ffpm-bind-checked-value" />
							</p>
							<p class="ffpm-grid-2">
								<span>
									<label><?php esc_html_e( 'Font size', 'pdf-mapper-for-fluent-forms' ); ?></label>
									<input type="number" id="ffpm-bind-size" min="5" max="24" step="0.5" value="9" />
								</span>
								<span>
									<label><?php esc_html_e( 'Align', 'pdf-mapper-for-fluent-forms' ); ?></label>
									<select id="ffpm-bind-align">
										<option value="L"><?php esc_html_e( 'Left', 'pdf-mapper-for-fluent-forms' ); ?></option>
										<option value="C"><?php esc_html_e( 'Center', 'pdf-mapper-for-fluent-forms' ); ?></option>
										<option value="R"><?php esc_html_e( 'Right', 'pdf-mapper-for-fluent-forms' ); ?></option>
									</select>
								</span>
							</p>
							<p>
								<label><input type="checkbox" id="ffpm-bind-multiline" /> <?php esc_html_e( 'Multiline (wrap text)', 'pdf-mapper-for-fluent-forms' ); ?></label>
							</p>
							<p>
								<label><input type="checkbox" id="ffpm-bind-repeater" /> <?php esc_html_e( 'Repeater column (first row)', 'pdf-mapper-for-fluent-forms' ); ?></label>
							</p>
							<p class="ffpm-grid-2">
								<span><label>X</label><input type="number" id="ffpm-bind-x" step="0.1" /></span>
								<span><label>Y</label><input type="number" id="ffpm-bind-y" step="0.1" /></span>
							</p>
							<p class="ffpm-grid-2">
								<span><label><?php esc_html_e( 'Width', 'pdf-mapper-for-fluent-forms' ); ?></label><input type="number" id="ffpm-bind-w" step="0.1" /></span>
								<span><label><?php esc_html_e( 'Height', 'pdf-mapper-for-fluent-forms' ); ?></label><input type="number" id="ffpm-bind-h" step="0.1" /></span>
							</p>
							<p><button type="button" class="button-link-delete" id="ffpm-bind-delete"><?php esc_html_e( 'Remove binding', 'pdf-mapper-for-fluent-forms' ); ?></button></p>
						</div>

						<div class="ffpm-panel">
							<h3><?php esc_html_e( 'Repeater', 'pdf-mapper-for-fluent-forms' ); ?></h3>
							<p>
								<label><?php esc_html_e( 'Repeater field', 'pdf-mapper-for-fluent-forms' ); ?></label>
								<select id="ffpm-repeater-source"><option value=""><?php esc_html_e( '(none)', 'pdf-mapper-for-fluent-forms' ); ?></option></select>
							</p>
							<p class="ffpm-grid-2">
								<span><label><?php esc_html_e( 'Rows / page', 'pdf-mapper-for-fluent-forms' ); ?></label><input type="number" id="ffpm-repeater-rows" min="1" value="6" /></span>
								<span><label><?php esc_html_e( 'Row height (pt)', 'pdf-mapper-for-fluent-forms' ); ?></label><input type="number" id="ffpm-repeater-height" step="0.1" value="0" /></span>
							</p>
							<div id="ffpm-repeater-columns" hidden>
								<label><?php esc_html_e( 'Fields inside this repeater', 'pdf-mapper-for-fluent-forms' ); ?></label>
								<ol id="ffpm-repeater-columns-list" class="ffpm-col-list"></ol>
								<p class="description"><?php esc_html_e( 'These are the fields detected inside the repeater, in order. Place a field box on each PDF column, tick “Repeater column”, then choose which of these fields fills it.', 'pdf-mapper-for-fluent-forms' ); ?></p>
							</div>
							<p class="description"><?php esc_html_e( 'Mark bindings as “repeater column” and position them on the first row. Extra rows repeat downward; overflow clones the page.', 'pdf-mapper-for-fluent-forms' ); ?></p>
						</div>

						<div class="ffpm-panel">
							<h3><?php esc_html_e( 'Feed settings', 'pdf-mapper-for-fluent-forms' ); ?></h3>
							<p><label><input type="checkbox" id="ffpm-set-enabled" checked /> <?php esc_html_e( 'Feed active', 'pdf-mapper-for-fluent-forms' ); ?></label></p>
							<p><label><input type="checkbox" id="ffpm-set-attach" checked /> <?php esc_html_e( 'Attach PDF to form notification emails', 'pdf-mapper-for-fluent-forms' ); ?></label></p>
							<p>
								<label><?php esc_html_e( 'File name pattern', 'pdf-mapper-for-fluent-forms' ); ?></label>
								<input type="text" id="ffpm-set-filename" value="{form}-{id}" />
								<span class="description">{form} {id} {date}</span>
							</p>
							<p>
								<label><input type="checkbox" id="ffpm-cond-enabled" /> <?php esc_html_e( 'Only generate when…', 'pdf-mapper-for-fluent-forms' ); ?></label>
							</p>
							<p class="ffpm-grid-2 ffpm-cond-row" hidden>
								<span><label><?php esc_html_e( 'Field', 'pdf-mapper-for-fluent-forms' ); ?></label><select id="ffpm-cond-field"></select></span>
								<span><label><?php esc_html_e( 'Is not empty / equals', 'pdf-mapper-for-fluent-forms' ); ?></label><input type="text" id="ffpm-cond-value" placeholder="<?php esc_attr_e( '(leave empty = not empty)', 'pdf-mapper-for-fluent-forms' ); ?>" /></span>
							</p>
						</div>

						<div class="ffpm-panel">
							<h3><?php esc_html_e( 'Bindings', 'pdf-mapper-for-fluent-forms' ); ?></h3>
							<ul id="ffpm-binding-list"></ul>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: create a feed.
	 */
	public function handle_create(): void {
		check_ajax_referer( self::NONCE_ACTION );
		$this->assert_capability();

		$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$form_id     = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;

		if ( '' === $title || 0 === $form_id || 0 === $template_id || null === $this->templates->find( $template_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid feed data.', 'pdf-mapper-for-fluent-forms' ) ), 400 );
		}

		$id = $this->feeds->insert( $form_id, $template_id, $title );
		if ( 0 === $id ) {
			wp_send_json_error( array( 'message' => __( 'Database error.', 'pdf-mapper-for-fluent-forms' ) ), 500 );
		}

		wp_send_json_success( array( 'id' => $id ) );
	}

	/**
	 * AJAX: delete a feed.
	 */
	public function handle_delete(): void {
		check_ajax_referer( self::NONCE_ACTION );
		$this->assert_capability();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( 0 === $id || ! $this->feeds->delete( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Feed not found.', 'pdf-mapper-for-fluent-forms' ) ), 404 );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: everything the mapper needs to boot.
	 */
	public function handle_mapper_data(): void {
		check_ajax_referer( self::NONCE_ACTION );
		$this->assert_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		$feed_id = isset( $_GET['feed'] ) ? absint( $_GET['feed'] ) : 0;
		$feed    = $this->feeds->find( $feed_id );
		if ( null === $feed ) {
			wp_send_json_error( array( 'message' => __( 'Feed not found.', 'pdf-mapper-for-fluent-forms' ) ), 404 );
		}

		$template = $this->templates->find( (int) $feed->template_id );
		if ( null === $template ) {
			wp_send_json_error( array( 'message' => __( 'The template for this feed was deleted.', 'pdf-mapper-for-fluent-forms' ) ), 410 );
		}

		wp_send_json_success(
			array(
				'feed'     => array(
					'id'       => (int) $feed->id,
					'title'    => (string) $feed->title,
					'status'   => (string) $feed->status,
					'mapping'  => json_decode( (string) $feed->mapping, true ),
					'settings' => json_decode( (string) $feed->settings, true ),
				),
				'template' => array(
					'id'          => (int) $template->id,
					'page_count'  => (int) $template->page_count,
					'page_width'  => (float) $template->page_width,
					'page_height' => (float) $template->page_height,
					'fileUrl'     => add_query_arg(
						array(
							'action'      => 'ffpm_template_file',
							'id'          => (int) $template->id,
							'_ajax_nonce' => wp_create_nonce( self::NONCE_ACTION ),
						),
						admin_url( 'admin-ajax.php' )
					),
				),
				'fields'   => $this->reader->fields( (int) $feed->form_id ),
			)
		);
	}

	/**
	 * AJAX: stream the template PDF for PDF.js rendering.
	 */
	public function handle_template_file(): void {
		check_ajax_referer( self::NONCE_ACTION );
		$this->assert_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$template = $this->templates->find( $id );
		$path     = $template ? Storage::path( 'templates', (string) $template->file_name ) : null;

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
		header( 'Content-Disposition: inline; filename="template.pdf"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * AJAX: persist mapping + settings.
	 */
	public function handle_save_mapping(): void {
		check_ajax_referer( self::NONCE_ACTION );
		$this->assert_capability();

		$feed_id = isset( $_POST['feed'] ) ? absint( $_POST['feed'] ) : 0;
		if ( null === $this->feeds->find( $feed_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Feed not found.', 'pdf-mapper-for-fluent-forms' ) ), 404 );
		}

		$raw_mapping  = isset( $_POST['mapping'] ) ? wp_unslash( $_POST['mapping'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as JSON below.
		$raw_settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as JSON below.
		$status       = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'active';

		$mapping  = $this->sanitize_mapping( is_string( $raw_mapping ) ? $raw_mapping : '' );
		$settings = $this->sanitize_settings( is_string( $raw_settings ) ? $raw_settings : '' );

		if ( null === $mapping || null === $settings ) {
			wp_send_json_error( array( 'message' => __( 'Invalid mapping payload.', 'pdf-mapper-for-fluent-forms' ) ), 400 );
		}

		$saved = $this->feeds->save_configuration( $feed_id, (string) wp_json_encode( $mapping ), (string) wp_json_encode( $settings ), $status );

		if ( ! $saved ) {
			wp_send_json_error( array( 'message' => __( 'Database error.', 'pdf-mapper-for-fluent-forms' ) ), 500 );
		}

		wp_send_json_success();
	}

	/**
	 * Validate and normalise a mapping JSON payload.
	 *
	 * @param string $json Raw JSON.
	 * @return array<string,mixed>|null Clean structure, or null when invalid.
	 */
	private function sanitize_mapping( string $json ): ?array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['fields'] ) || ! is_array( $data['fields'] ) ) {
			return null;
		}

		$clean = array(
			'version'  => 1,
			'fields'   => array(),
			'repeater' => null,
		);

		foreach ( $data['fields'] as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$clean['fields'][] = array(
				'page'          => max( 1, absint( $field['page'] ?? 1 ) ),
				'x'             => round( (float) ( $field['x'] ?? 0 ), 2 ),
				'y'             => round( (float) ( $field['y'] ?? 0 ), 2 ),
				'w'             => round( (float) ( $field['w'] ?? 100 ), 2 ),
				'h'             => round( (float) ( $field['h'] ?? 14 ), 2 ),
				'type'          => in_array( $field['type'] ?? '', array( 'checkbox', 'image' ), true ) ? $field['type'] : 'text',
				'source'        => sanitize_text_field( (string) ( $field['source'] ?? '' ) ),
				'size'          => min( 24, max( 5, (float) ( $field['size'] ?? 9 ) ) ),
				'align'         => in_array( $field['align'] ?? 'L', array( 'L', 'C', 'R' ), true ) ? $field['align'] : 'L',
				'multiline'     => ! empty( $field['multiline'] ),
				'repeater'      => ! empty( $field['repeater'] ),
				'sub'           => sanitize_text_field( (string) ( $field['sub'] ?? '' ) ),
				'checked_value' => sanitize_text_field( (string) ( $field['checked_value'] ?? '' ) ),
			);
		}

		$repeater_source = '';
		if ( isset( $data['repeater'] ) && is_array( $data['repeater'] ) ) {
			$repeater_source = sanitize_text_field( (string) ( $data['repeater']['source'] ?? '' ) );
		}

		// Fall back to the source carried on the repeater bindings so the
		// repeater config survives even if the client omitted it.
		if ( '' === $repeater_source ) {
			foreach ( $clean['fields'] as $binding ) {
				if ( ! empty( $binding['repeater'] ) && '' !== $binding['source'] && 'auto:row' !== $binding['source'] ) {
					$repeater_source = $binding['source'];
					break;
				}
			}
		}

		if ( '' !== $repeater_source ) {
			$config            = is_array( $data['repeater'] ?? null ) ? $data['repeater'] : array();
			$clean['repeater'] = array(
				'source'        => $repeater_source,
				'rows_per_page' => max( 1, absint( $config['rows_per_page'] ?? 6 ) ),
				'row_height'    => round( (float) ( $config['row_height'] ?? 0 ), 2 ),
			);
		}

		return $clean;
	}

	/**
	 * Validate and normalise a settings JSON payload.
	 *
	 * @param string $json Raw JSON.
	 * @return array<string,mixed>|null Clean structure, or null when invalid.
	 */
	private function sanitize_settings( string $json ): ?array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$filename = sanitize_text_field( (string) ( $data['filename'] ?? '{form}-{id}' ) );

		return array(
			'attach_emails' => ! empty( $data['attach_emails'] ),
			'filename'      => '' === $filename ? '{form}-{id}' : $filename,
			'condition'     => array(
				'enabled' => ! empty( $data['condition']['enabled'] ),
				'field'   => sanitize_text_field( (string) ( $data['condition']['field'] ?? '' ) ),
				'value'   => sanitize_text_field( (string) ( $data['condition']['value'] ?? '' ) ),
			),
		);
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
