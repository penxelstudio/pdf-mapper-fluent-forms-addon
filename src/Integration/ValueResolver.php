<?php
/**
 * Resolves mapping sources against Fluent Forms submission data.
 *
 * @package FFPM
 */

namespace FFPM\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a mapping + raw submission form data into the value structure the
 * Generator consumes.
 */
final class ValueResolver {

	/**
	 * Resolve all sources used by a mapping.
	 *
	 * @param array<string,mixed> $mapping   Mapping structure.
	 * @param array<string,mixed> $form_data Fluent Forms submission data (field name => value).
	 * @return array{fields:array<string,mixed>,rows:array<int,array<string,mixed>>}
	 */
	public function resolve( array $mapping, array $form_data ): array {
		$fields = array();

		foreach ( (array) ( $mapping['fields'] ?? array() ) as $binding ) {
			if ( ! empty( $binding['repeater'] ) ) {
				continue;
			}
			$source = (string) ( $binding['source'] ?? '' );
			if ( '' === $source || isset( $fields[ $source ] ) ) {
				continue;
			}

			$resolved = $this->resolve_source( $source, $form_data );

			// Image bindings resolve to a safe local file path for the
			// generator; everything else stays a scalar/array value.
			if ( 'image' === ( $binding['type'] ?? '' ) ) {
				$resolved = $this->resolve_upload_path( $resolved );
			}

			$fields[ $source ] = $resolved;
		}

		$rows     = array();
		$repeater = isset( $mapping['repeater'] ) && is_array( $mapping['repeater'] ) ? $mapping['repeater'] : null;

		// The repeater source is also carried on every repeater binding, so
		// derive it from the bindings when the top-level config is missing or
		// blank. Without this, a mapping whose `repeater` block was never saved
		// resolves zero rows even though repeater columns are mapped.
		$source = null !== $repeater ? (string) ( $repeater['source'] ?? '' ) : '';
		if ( '' === $source ) {
			$source = $this->derive_repeater_source( $mapping );
		}

		if ( '' !== $source ) {
			$raw = $this->resolve_source( $source, $form_data );
			if ( is_array( $raw ) ) {
				foreach ( array_values( $raw ) as $raw_row ) {
					if ( is_array( $raw_row ) ) {
						// Keys may be column names or numeric indexes depending on
						// the Fluent Forms version — expose both to bindings.
						$row = array();
						$i   = 0;
						foreach ( $raw_row as $key => $value ) {
							$row[ (string) $key ] = $value;
							$row[ (string) $i ]   = $value;
							++$i;
						}
						$rows[] = $row;
					}
				}
			}
		}

		return array(
			'fields' => $fields,
			'rows'   => $rows,
		);
	}

	/**
	 * Derive the repeater source field from the repeater bindings.
	 *
	 * Every repeater column binding stores the repeater field as its source, so
	 * the first non-auto one identifies the source even when the mapping's
	 * top-level repeater config is absent.
	 *
	 * @param array<string,mixed> $mapping Mapping structure.
	 */
	private function derive_repeater_source( array $mapping ): string {
		foreach ( (array) ( $mapping['fields'] ?? array() ) as $binding ) {
			if ( empty( $binding['repeater'] ) ) {
				continue;
			}
			$source = (string) ( $binding['source'] ?? '' );
			if ( '' !== $source && 'auto:row' !== $source ) {
				return $source;
			}
		}

		return '';
	}

	/**
	 * Resolve a single source expression.
	 *
	 * Supported: "ff:field_name" (with dot notation for nested values, e.g.
	 * "ff:names.first_name") and "auto:date".
	 *
	 * @param string              $source    Source expression.
	 * @param array<string,mixed> $form_data Submission data.
	 * @return mixed
	 */
	public function resolve_source( string $source, array $form_data ) {
		if ( 'auto:date' === $source ) {
			return date_i18n( get_option( 'date_format', 'd/m/Y' ) );
		}

		if ( ! str_starts_with( $source, 'ff:' ) ) {
			return '';
		}

		$path  = explode( '.', substr( $source, 3 ) );
		$value = $form_data;

		foreach ( $path as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return '';
			}
			$value = $value[ $segment ];
		}

		// Compound fields (name, address) resolve to their joined parts when
		// bound without dot notation.
		if ( is_array( $value ) && ! $this->is_list_of_arrays( $value ) ) {
			$flat = array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), 'strlen' );
			if ( count( $flat ) === count( $value ) ) {
				return implode( ' ', $flat );
			}
		}

		return $value;
	}

	/**
	 * Resolve a Fluent Forms file-upload value to a safe local image path.
	 *
	 * Fluent Forms stores uploads as public URLs under wp-content/uploads. This
	 * maps the first URL back to its on-disk path and enforces two boundaries:
	 *
	 *  - Only URLs under this site's uploads directory are accepted, so a
	 *    submitted value can never point the generator at an arbitrary file or
	 *    a remote host (no SSRF, no local file disclosure).
	 *  - The resolved real path must sit inside the uploads directory, so
	 *    "../" traversal in a crafted value is rejected.
	 *
	 * @param mixed $value Raw upload value (URL string, or array of URLs/rows).
	 * @return string Absolute local path, or '' when nothing safe resolves.
	 */
	private function resolve_upload_path( $value ): string {
		// Unwrap the common shapes: array of URLs, or array of {url:...} rows.
		while ( is_array( $value ) ) {
			$first = reset( $value );
			if ( is_array( $first ) && isset( $first['url'] ) ) {
				$value = $first['url'];
				break;
			}
			$value = $first;
		}

		$url = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $url ) {
			return '';
		}

		$uploads = wp_upload_dir( null, false );
		$baseurl = (string) ( $uploads['baseurl'] ?? '' );
		$basedir = (string) ( $uploads['basedir'] ?? '' );

		if ( '' === $baseurl || '' === $basedir ) {
			return '';
		}

		// Normalise scheme so http/https mismatches still resolve.
		$url_normalised     = preg_replace( '#^https?:#', '', $url );
		$baseurl_normalised = preg_replace( '#^https?:#', '', $baseurl );

		if ( ! str_starts_with( (string) $url_normalised, (string) $baseurl_normalised ) ) {
			return '';
		}

		$relative = substr( (string) $url_normalised, strlen( (string) $baseurl_normalised ) );
		$relative = wp_parse_url( $relative, PHP_URL_PATH ); // Drop any query string.
		if ( ! is_string( $relative ) || '' === $relative ) {
			return '';
		}

		$candidate = $basedir . '/' . ltrim( rawurldecode( $relative ), '/' );

		$real_path = realpath( $candidate );
		$real_base = realpath( $basedir );
		// The separator matters: a bare prefix test would also accept a sibling
		// directory whose name merely starts with the uploads path.
		if ( false === $real_path || false === $real_base || ! str_starts_with( $real_path, $real_base . DIRECTORY_SEPARATOR ) ) {
			return '';
		}

		return $real_path;
	}

	/**
	 * Whether the array is a list of arrays (repeater-style data).
	 *
	 * @param array<mixed> $value Value to inspect.
	 */
	private function is_list_of_arrays( array $value ): bool {
		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				return true;
			}
		}

		return false;
	}
}
