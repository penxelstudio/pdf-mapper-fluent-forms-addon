<?php
/**
 * Uploaded PDF validation.
 *
 * @package FFPM
 */

namespace FFPM\Templates;

use setasign\Fpdi\Fpdi;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Validates an uploaded PDF template before it is stored.
 *
 * Uploads are sanitised client-side first (pdf-lib strips OpenAction, the
 * document JavaScript name tree and /AA actions, and rewrites the file without
 * object streams so FPDI can parse it). This class is the server-side gate:
 * nothing that still carries active content or that FPDI cannot read is stored.
 */
final class PdfValidator {

	/**
	 * PDF name tokens that indicate active/embedded content. A legitimate,
	 * sanitised template contains none of these.
	 */
	private const FORBIDDEN_TOKENS = array(
		'JavaScript',
		'JS',
		'OpenAction',
		'AA',
		'Launch',
		'EmbeddedFile',
		'RichMedia',
		'XFA',
	);

	/**
	 * Validate a candidate template file.
	 *
	 * @param string $path Absolute path to the uploaded temp file.
	 * @return array{page_count:int,page_width:float,page_height:float}|WP_Error
	 */
	public function validate( string $path ) {
		$size = is_file( $path ) ? (int) filesize( $path ) : 0;

		if ( $size < 100 ) {
			return new WP_Error( 'ffpm_empty', __( 'The uploaded file is empty or unreadable.', 'pdf-mapper-for-fluent-forms' ) );
		}

		/**
		 * Filters the maximum allowed template size in bytes.
		 *
		 * @param int $max_bytes Default 20 MB.
		 */
		$max_bytes = (int) apply_filters( 'ffpm_max_template_size', 20 * MB_IN_BYTES );
		if ( $size > $max_bytes ) {
			return new WP_Error(
				'ffpm_too_large',
				sprintf(
					/* translators: %s: maximum file size, e.g. "20 MB" */
					__( 'The file exceeds the maximum template size of %s.', 'pdf-mapper-for-fluent-forms' ),
					size_format( $max_bytes )
				)
			);
		}

		$mime = function_exists( 'finfo_open' ) ? ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $path ) : null;
		if ( null !== $mime && 'application/pdf' !== $mime ) {
			return new WP_Error( 'ffpm_not_pdf', __( 'The file is not a PDF document.', 'pdf-mapper-for-fluent-forms' ) );
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents || ! str_starts_with( $contents, '%PDF-' ) ) {
			return new WP_Error( 'ffpm_not_pdf', __( 'The file is not a PDF document.', 'pdf-mapper-for-fluent-forms' ) );
		}

		$token = $this->find_forbidden_token( $contents );
		unset( $contents );
		if ( null !== $token ) {
			return new WP_Error(
				'ffpm_active_content',
				sprintf(
					/* translators: %s: PDF token name, e.g. "/JavaScript" */
					__( 'The PDF contains active content (%s) and was rejected. Upload it through this screen with JavaScript enabled so it can be sanitised automatically.', 'pdf-mapper-for-fluent-forms' ),
					'/' . $token
				)
			);
		}

		return $this->probe_with_fpdi( $path );
	}

	/**
	 * Scan PDF structure for forbidden name tokens.
	 *
	 * Active content — JavaScript, launch, and additional-action references —
	 * lives in the PDF's dictionary/object structure, never inside the binary
	 * payload of a font or image stream. Those binary blobs, however, routinely
	 * contain the byte sequence "/AA" (and others) by pure chance, which a naive
	 * whole-file scan flags as a false positive. So the stream payloads are
	 * blanked out first, leaving only the structural bytes to scan.
	 *
	 * @param string $contents Raw file contents.
	 * @return string|null Matched token, or null when clean.
	 */
	private function find_forbidden_token( string $contents ): ?string {
		$structural = $this->strip_stream_payloads( $contents );

		foreach ( self::FORBIDDEN_TOKENS as $token ) {
			// A PDF name is "/Token" followed by a delimiter — \b guards against
			// substring hits such as /JSFoo while keeping the scan cheap.
			if ( preg_match( '#/' . $token . '\b#', $structural ) ) {
				return $token;
			}
		}

		return null;
	}

	/**
	 * Replace the bytes between every `stream`/`endstream` pair with a marker,
	 * so only the PDF's structural (dictionary) bytes remain for scanning.
	 *
	 * @param string $contents Raw file contents.
	 */
	private function strip_stream_payloads( string $contents ): string {
		$stripped = preg_replace( '#stream\r?\n.*?endstream#s', 'stream endstream', $contents );

		// On the rare catastrophic-backtrack / PCRE-limit failure, fall back to
		// scanning the raw bytes rather than skipping the check entirely.
		return is_string( $stripped ) ? $stripped : $contents;
	}

	/**
	 * Confirm FPDI can import every page, and read the page geometry.
	 *
	 * @param string $path Absolute file path.
	 * @return array{page_count:int,page_width:float,page_height:float}|WP_Error
	 */
	private function probe_with_fpdi( string $path ) {
		try {
			$pdf        = new Fpdi();
			$page_count = $pdf->setSourceFile( $path );

			$first = $pdf->importPage( 1 );
			$dims  = $pdf->getTemplateSize( $first );

			for ( $page = 2; $page <= $page_count; $page++ ) {
				$pdf->importPage( $page );
			}

			return array(
				'page_count'  => $page_count,
				// FPDI reports millimetres by default; store PDF points (1 pt = 1/72 in).
				'page_width'  => round( $dims['width'] * 72 / 25.4, 2 ),
				'page_height' => round( $dims['height'] * 72 / 25.4, 2 ),
			);
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'ffpm_unparseable',
				__( 'This PDF could not be parsed for generation. Upload it through this screen with JavaScript enabled so it can be converted automatically.', 'pdf-mapper-for-fluent-forms' )
			);
		}
	}
}
