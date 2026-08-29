<?php
/**
 * Fluent Forms runtime integration.
 *
 * @package FFPM
 */

namespace FFPM\Integration;

use FFPM\Mapping\FeedRepository;
use FFPM\Pdf\Generator;
use FFPM\Submission\DocumentRepository;
use FFPM\Support\Storage;
use FFPM\Templates\TemplateRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Generates documents on submission and attaches them to notification emails.
 */
final class FluentForms {

	/**
	 * Feed repository.
	 */
	private FeedRepository $feeds;

	/**
	 * Template repository.
	 */
	private TemplateRepository $templates;

	/**
	 * Document repository.
	 */
	private DocumentRepository $documents;

	/**
	 * Value resolver.
	 */
	private ValueResolver $resolver;

	/**
	 * Reader for form meta.
	 */
	private FormFieldsReader $reader;

	/**
	 * File paths generated in this request, keyed by submission id. Used by the
	 * attachment filter, which fires later in the same request.
	 *
	 * @var array<int,string[]>
	 */
	private array $generated = array();

	/**
	 * Submission currently being processed. The attachment filter carries no
	 * submission id, so without this a request that processed more than one
	 * submission (bulk import, programmatic entries) could attach one
	 * applicant's PDF to another applicant's email.
	 *
	 * @var int
	 */
	private int $current_submission_id = 0;

	/**
	 * Set up dependencies.
	 */
	public function __construct() {
		$this->feeds     = new FeedRepository();
		$this->templates = new TemplateRepository();
		$this->documents = new DocumentRepository();
		$this->resolver  = new ValueResolver();
		$this->reader    = new FormFieldsReader();
	}

	/**
	 * Wire hooks. Generation runs at priority 5 so documents exist before
	 * Fluent Forms sends notification emails (its handlers run at 10).
	 */
	public function register(): void {
		add_action( 'fluentform/submission_inserted', array( $this, 'handle_submission' ), 5, 3 );
		add_filter( 'fluentform/filter_email_attachments', array( $this, 'attach_documents' ), 10, 4 );
	}

	/**
	 * Generate one document per active feed for this form.
	 *
	 * @param int|string          $submission_id Submission id.
	 * @param array<string,mixed> $form_data     Submitted values.
	 * @param object              $form          Form object.
	 */
	public function handle_submission( $submission_id, $form_data, $form ): void {
		$submission_id = (int) $submission_id;
		$form_id       = (int) ( $form->id ?? 0 );

		if ( 0 === $form_id || ! is_array( $form_data ) ) {
			return;
		}

		$this->current_submission_id = $submission_id;

		foreach ( $this->feeds->active_for_form( $form_id ) as $feed ) {
			$this->generate_for_feed( $feed, $submission_id, $form_data );
		}
	}

	/**
	 * Generate a document for one feed. Public so the admin "regenerate"
	 * action can reuse the exact submission-time pipeline.
	 *
	 * @param object              $feed          Feed row.
	 * @param int                 $submission_id Submission id.
	 * @param array<string,mixed> $form_data     Submitted values.
	 * @return int Document id (0 on failure).
	 */
	public function generate_for_feed( object $feed, int $submission_id, array $form_data ): int {
		$feed_id = (int) $feed->id;
		$form_id = (int) $feed->form_id;

		$mapping  = json_decode( (string) $feed->mapping, true );
		$settings = json_decode( (string) $feed->settings, true );
		if ( ! is_array( $mapping ) || ! is_array( $settings ) ) {
			return 0;
		}

		if ( ! $this->condition_passes( $settings, $form_data ) ) {
			return 0;
		}

		$template = $this->templates->find( (int) $feed->template_id );
		$path     = $template ? Storage::path( 'templates', (string) $template->file_name ) : null;

		if ( null === $path ) {
			$this->documents->insert( $feed_id, $form_id, $submission_id, '', 'failed', 'Template file missing.' );
			return 0;
		}

		Storage::ensure_directories();

		$file_name   = $this->build_file_name( (string) ( $settings['filename'] ?? '{form}-{id}' ), $form_id, $submission_id );
		$destination = Storage::dir( 'documents' ) . '/' . $file_name;

		$data   = $this->resolver->resolve( $mapping, $form_data );
		$result = ( new Generator() )->generate( $path, $mapping, $data, $destination );

		if ( true !== $result ) {
			$this->documents->insert( $feed_id, $form_id, $submission_id, '', 'failed', $result->get_error_message() );
			return 0;
		}

		$document_id = $this->documents->insert( $feed_id, $form_id, $submission_id, $file_name );

		if ( ! empty( $settings['attach_emails'] ) ) {
			$this->generated[ $submission_id ][] = $destination;
		}

		/**
		 * Fires after a document is generated for a submission.
		 *
		 * @param int    $document_id Document id.
		 * @param int    $feed_id     Feed id.
		 * @param int    $submission_id Submission id.
		 * @param string $destination Absolute file path.
		 */
		do_action( 'ffpm_document_generated', $document_id, $feed_id, $submission_id, $destination );

		return $document_id;
	}

	/**
	 * Append generated documents to Fluent Forms notification emails.
	 *
	 * @param array<int,string>   $attachments  Existing attachment paths.
	 * @param array<string,mixed> $notification Notification settings.
	 * @param object              $form         Form object.
	 * @param array<string,mixed> $form_data    Submitted values.
	 * @return array<int,string>
	 */
	public function attach_documents( $attachments, $notification, $form, $form_data ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- filter signature fixed by Fluent Forms.
		$attachments = is_array( $attachments ) ? $attachments : array();

		// Only the submission being processed right now — never every document
		// generated during this request.
		$paths = $this->generated[ $this->current_submission_id ] ?? array();

		foreach ( $paths as $path ) {
			if ( ! in_array( $path, $attachments, true ) ) {
				$attachments[] = $path;
			}
		}

		return $attachments;
	}

	/**
	 * Evaluate the feed condition against submitted data.
	 *
	 * Empty comparison value means "field is not empty"; otherwise a
	 * case-insensitive equality (arrays match when any element equals).
	 *
	 * @param array<string,mixed> $settings  Feed settings.
	 * @param array<string,mixed> $form_data Submitted values.
	 */
	private function condition_passes( array $settings, array $form_data ): bool {
		$condition = isset( $settings['condition'] ) && is_array( $settings['condition'] ) ? $settings['condition'] : array();

		if ( empty( $condition['enabled'] ) || '' === (string) ( $condition['field'] ?? '' ) ) {
			return true;
		}

		$actual = $this->resolver->resolve_source( 'ff:' . (string) $condition['field'], $form_data );
		$target = trim( (string) ( $condition['value'] ?? '' ) );

		if ( '' === $target ) {
			if ( is_array( $actual ) ) {
				return array() !== array_filter(
					$actual,
					static fn( $item ): bool => is_array( $item ) ? array() !== array_filter( $item ) : '' !== trim( (string) $item )
				);
			}
			return '' !== trim( (string) $actual );
		}

		if ( is_array( $actual ) ) {
			foreach ( $actual as $item ) {
				if ( 0 === strcasecmp( trim( (string) $item ), $target ) ) {
					return true;
				}
			}
			return false;
		}

		return 0 === strcasecmp( trim( (string) $actual ), $target );
	}

	/**
	 * Render the file name pattern into a safe, unguessable file name.
	 *
	 * @param string $pattern       Pattern with {form} {id} {date} tokens.
	 * @param int    $form_id       Form id.
	 * @param int    $submission_id Submission id.
	 */
	private function build_file_name( string $pattern, int $form_id, int $submission_id ): string {
		$rendered = str_replace(
			array( '{form}', '{id}', '{date}' ),
			array( sanitize_title( $this->reader->form_title( $form_id ) ), (string) $submission_id, gmdate( 'Ymd' ) ),
			$pattern
		);

		$rendered = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $rendered );

		// Dots survive the charset filter, so a pattern like "report..v2" would
		// produce a name containing "..". Storage::path() rejects those outright,
		// which would leave the generated file undownloadable and undeletable.
		$rendered = preg_replace( '/\.{2,}/', '.', (string) $rendered );

		$rendered = trim( substr( (string) $rendered, 0, 100 ), '-.' );
		$rendered = '' === $rendered ? 'document' : $rendered;

		// The deny rules only bind on Apache/LiteSpeed. On nginx the file is
		// reachable by URL, so the name is the last line of defence — and the
		// readable part of it is predictable (an admin-set pattern plus a
		// sequential submission id). Match the 32 characters templates already
		// use rather than leaving documents, which hold the personal data, on a
		// shorter suffix than the blank forms.
		return $rendered . '-' . wp_generate_password( 32, false, false ) . '.pdf';
	}
}
