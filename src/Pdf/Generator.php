<?php
/**
 * PDF generation: overlay mapped values onto a template.
 *
 * @package FFPM
 */

namespace FFPM\Pdf;

use setasign\Fpdi\Fpdi;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a filled, flattened PDF from a template + mapping + resolved values.
 *
 * Coordinates in the mapping are PDF points with a top-left origin, which is
 * exactly FPDF's coordinate system when constructed with unit "pt" — no
 * conversion needed. Output pages carry no AcroForm, annotations or scripts:
 * FPDI imports page content only, so the result is inherently flattened.
 */
final class Generator {

	/**
	 * Generate a filled PDF.
	 *
	 * @param string              $template_path Absolute path to the (sanitised) template.
	 * @param array<string,mixed> $mapping       Mapping structure (fields[] + repeater).
	 * @param array<string,mixed> $data          Resolved values:
	 *                                           - fields: map of source => scalar value
	 *                                           - rows: list of repeater rows, each map of sub => value.
	 * @param string              $output_path   Absolute destination path.
	 * @return true|WP_Error
	 */
	public function generate( string $template_path, array $mapping, array $data, string $output_path ) {
		$static_fields   = array();
		$repeater_fields = array();

		foreach ( (array) ( $mapping['fields'] ?? array() ) as $field ) {
			if ( ! empty( $field['repeater'] ) ) {
				$repeater_fields[] = $field;
			} elseif ( '' !== (string) ( $field['source'] ?? '' ) ) {
				$static_fields[] = $field;
			}
		}

		// Pagination is driven by the presence of repeater bindings, not by the
		// top-level repeater block — that block may be absent on a mapping that
		// still has repeater columns, and rows must render regardless.
		$repeater      = isset( $mapping['repeater'] ) && is_array( $mapping['repeater'] ) ? $mapping['repeater'] : array();
		$has_repeater  = array() !== $repeater_fields;
		$rows          = array_values( (array) ( $data['rows'] ?? array() ) );
		$values        = (array) ( $data['fields'] ?? array() );
		$rows_per_page = max( 1, (int) ( $repeater['rows_per_page'] ?? 6 ) );
		$row_height    = (float) ( $repeater['row_height'] ?? 0 );

		// Group repeater rows into page-sized chunks; at least one chunk so the
		// repeater page always renders even with no rows.
		$chunks = array( array() );
		if ( array() !== $rows && $has_repeater ) {
			$chunks = array_chunk( $rows, $rows_per_page );
		}

		try {
			$pdf = new Fpdi( 'P', 'pt' );
			$pdf->SetAutoPageBreak( false );
			$pdf->SetTextColor( 20, 20, 20 );

			$page_count = $pdf->setSourceFile( $template_path );

			for ( $page_no = 1; $page_no <= $page_count; $page_no++ ) {
				$is_repeater_page = $has_repeater
					&& $this->page_has_repeater_fields( $repeater_fields, $page_no );

				$instances = $is_repeater_page ? count( $chunks ) : 1;

				for ( $instance = 0; $instance < $instances; $instance++ ) {
					$tpl  = $pdf->importPage( $page_no );
					$size = $pdf->getTemplateSize( $tpl );

					$pdf->AddPage( $size['width'] > $size['height'] ? 'L' : 'P', array( $size['width'], $size['height'] ) );
					$pdf->useTemplate( $tpl );

					// Static bindings repeat on every clone of their page, so
					// declaration blocks stay present on overflow pages.
					foreach ( $static_fields as $field ) {
						if ( (int) $field['page'] === $page_no ) {
							$this->draw( $pdf, $field, $values[ $field['source'] ] ?? '' );
						}
					}

					if ( $is_repeater_page ) {
						$chunk = $chunks[ $instance ];
						foreach ( $chunk as $row_index => $row ) {
							$offset = $row_height * $row_index;
							foreach ( $repeater_fields as $field ) {
								if ( (int) $field['page'] !== $page_no ) {
									continue;
								}

								$absolute_row = $instance * $rows_per_page + $row_index;
								$value        = 'auto:row' === ( $field['source'] ?? '' )
									? (string) ( $absolute_row + 1 )
									: ( $row[ $field['sub'] ] ?? '' );

								$shifted      = $field;
								$shifted['y'] = (float) $field['y'] + $offset;
								$this->draw( $pdf, $shifted, $value );
							}
						}
					}
				}
			}

			$pdf->Output( 'F', $output_path );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'ffpm_generate_failed', $e->getMessage() );
		}

		return true;
	}

	/**
	 * Whether any repeater binding sits on the given template page.
	 *
	 * @param array<int,array<string,mixed>> $repeater_fields Repeater bindings.
	 * @param int                            $page_no         Template page number.
	 */
	private function page_has_repeater_fields( array $repeater_fields, int $page_no ): bool {
		foreach ( $repeater_fields as $field ) {
			if ( (int) $field['page'] === $page_no ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Draw one binding.
	 *
	 * @param Fpdi                $pdf   PDF being built (current page active).
	 * @param array<string,mixed> $field Binding definition.
	 * @param mixed               $value Resolved value.
	 */
	private function draw( Fpdi $pdf, array $field, $value ): void {
		$x = (float) $field['x'];
		$y = (float) $field['y'];
		$w = max( 4.0, (float) $field['w'] );
		$h = max( 4.0, (float) $field['h'] );

		if ( 'image' === ( $field['type'] ?? 'text' ) ) {
			$this->draw_image( $pdf, $field, $value, $x, $y, $w, $h );
			return;
		}

		if ( 'checkbox' === ( $field['type'] ?? 'text' ) ) {
			$checked_value = (string) ( $field['checked_value'] ?? '' );
			$is_checked    = '' === $checked_value
				? $this->truthy( $value )
				: $this->value_matches( $value, $checked_value );

			if ( $is_checked ) {
				$size = min( $h, 14.0 );
				$pdf->SetFont( 'Helvetica', 'B', $size );
				$pdf->SetXY( $x, $y + ( $h - $size ) / 2 );
				$pdf->Cell( $w, $size, 'X', 0, 0, 'C' );
			}
			return;
		}

		$text = $this->to_text( $value );
		if ( '' === $text ) {
			return;
		}

		$size      = (float) ( $field['size'] ?? 9 );
		$align     = in_array( $field['align'] ?? 'L', array( 'L', 'C', 'R' ), true ) ? (string) $field['align'] : 'L';
		$multiline = ! empty( $field['multiline'] );

		if ( $multiline ) {
			// Shrink until the wrapped text fits the box height.
			while ( $size > 5 ) {
				$pdf->SetFont( 'Helvetica', '', $size );
				$line_height = $size * 1.15;
				$lines       = $this->count_wrapped_lines( $pdf, $text, $w );
				if ( $lines * $line_height <= $h ) {
					break;
				}
				$size -= 0.5;
			}
			$pdf->SetFont( 'Helvetica', '', $size );
			$pdf->SetXY( $x, $y + 1 );
			$pdf->MultiCell( $w, $size * 1.15, $text, 0, $align );
			return;
		}

		// Single line: shrink until it fits the width.
		while ( $size > 5 ) {
			$pdf->SetFont( 'Helvetica', '', $size );
			if ( $pdf->GetStringWidth( $text ) <= $w - 2 ) {
				break;
			}
			$size -= 0.5;
		}
		$pdf->SetFont( 'Helvetica', '', $size );
		$pdf->SetXY( $x, $y );
		$pdf->Cell( $w, $h, $text, 0, 0, $align );
	}

	/**
	 * Draw an uploaded image (signature, stamp) fitted inside the box.
	 *
	 * The value is a local file path already resolved and containment-checked
	 * by the resolver. It is re-validated here as a real PNG/JPEG/GIF before
	 * being handed to FPDF, and any failure is swallowed so one bad image can
	 * never abort the whole document.
	 *
	 * @param Fpdi                $pdf   PDF being built (current page active).
	 * @param array<string,mixed> $field Binding definition.
	 * @param mixed               $value Resolved local image path.
	 * @param float               $x     Box left (pt).
	 * @param float               $y     Box top (pt).
	 * @param float               $w     Box width (pt).
	 * @param float               $h     Box height (pt).
	 */
	private function draw_image( Fpdi $pdf, array $field, $value, float $x, float $y, float $w, float $h ): void {
		$path = is_string( $value ) ? $value : '';
		if ( '' === $path || ! is_file( $path ) ) {
			return;
		}

		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid images handled via the false return.
		if ( false === $info ) {
			return;
		}

		$allowed = array(
			IMAGETYPE_PNG  => 'PNG',
			IMAGETYPE_JPEG => 'JPG',
			IMAGETYPE_GIF  => 'GIF',
		);
		if ( ! isset( $allowed[ $info[2] ] ) ) {
			return;
		}

		$px_w = (int) $info[0];
		$px_h = (int) $info[1];
		if ( $px_w < 1 || $px_h < 1 ) {
			return;
		}

		// Fit inside the box preserving aspect ratio.
		$ratio  = $px_w / $px_h;
		$draw_w = $w;
		$draw_h = $draw_w / $ratio;
		if ( $draw_h > $h ) {
			$draw_h = $h;
			$draw_w = $draw_h * $ratio;
		}

		// Horizontal alignment within the box; vertically top-aligned.
		$align  = in_array( $field['align'] ?? 'L', array( 'L', 'C', 'R' ), true ) ? (string) $field['align'] : 'L';
		$offset = 'C' === $align ? ( $w - $draw_w ) / 2 : ( 'R' === $align ? $w - $draw_w : 0.0 );

		try {
			$pdf->Image( $path, $x + $offset, $y, $draw_w, $draw_h, $allowed[ $info[2] ] );
		} catch ( \Throwable $e ) {
			// A malformed image must not abort generation of the rest of the PDF.
			return;
		}
	}

	/**
	 * Number of lines FPDF will wrap the text into for a given width.
	 *
	 * @param Fpdi   $pdf  PDF (font already set).
	 * @param string $text Text to measure.
	 * @param float  $w    Available width in pt.
	 */
	private function count_wrapped_lines( Fpdi $pdf, string $text, float $w ): int {
		$lines = 0;

		foreach ( explode( "\n", $text ) as $paragraph ) {
			$lines += max( 1, (int) ceil( $pdf->GetStringWidth( $paragraph ) / max( 1.0, $w - 2 ) ) );
		}

		return $lines;
	}

	/**
	 * Convert a resolved value to overlay text (FPDF core fonts are cp1252).
	 *
	 * @param mixed $value Resolved value.
	 */
	private function to_text( $value ): string {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', $value ) ) );
		}

		$text = trim( (string) $value );
		if ( '' === $text ) {
			return '';
		}

		$converted = function_exists( 'iconv' ) ? @iconv( 'UTF-8', 'CP1252//TRANSLIT//IGNORE', $text ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- iconv notice on exotic input; fallback below.

		return false !== $converted ? $converted : preg_replace( '/[^\x20-\x7E\n]/', '?', $text );
	}

	/**
	 * Loose truthiness for checkbox bindings without an explicit checked value.
	 *
	 * @param mixed $value Resolved value.
	 */
	private function truthy( $value ): bool {
		if ( is_array( $value ) ) {
			return array() !== array_filter( $value );
		}

		$text = strtolower( trim( (string) $value ) );

		return ! in_array( $text, array( '', '0', 'no', 'off', 'false' ), true );
	}

	/**
	 * Whether the value (or any element of an array value) equals the target.
	 *
	 * @param mixed  $value  Resolved value.
	 * @param string $target Comparison target.
	 */
	private function value_matches( $value, string $target ): bool {
		$target = strtolower( trim( $target ) );

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( strtolower( trim( (string) $item ) ) === $target ) {
					return true;
				}
			}
			return false;
		}

		return strtolower( trim( (string) $value ) ) === $target;
	}
}
