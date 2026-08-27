<?php
/**
 * Reads Fluent Forms form structure.
 *
 * @package FFPM
 */

namespace FFPM\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts a flat field list from a Fluent Forms form definition.
 */
final class FormFieldsReader {

	/**
	 * All Fluent Forms forms (id + title) for feed creation.
	 *
	 * @return array<int,array{id:int,title:string}>
	 */
	public function forms(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentform_forms';

		$rows = $wpdb->get_results( "SELECT id, title FROM {$table} ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map(
			static fn( object $row ): array => array(
				'id'    => (int) $row->id,
				'title' => (string) $row->title,
			),
			$rows ? $rows : array()
		);
	}

	/**
	 * Form title by id.
	 *
	 * @param int $form_id Form id.
	 */
	public function form_title( int $form_id ): string {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentform_forms';

		return (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT title FROM {$table} WHERE id = %d", $form_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Flat list of mappable fields for a form.
	 *
	 * Regular fields: {name, label, element}. Repeater fields additionally
	 * carry columns: [{index, name, label}].
	 *
	 * @param int $form_id Form id.
	 * @return array<int,array<string,mixed>>
	 */
	public function fields( int $form_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'fluentform_forms';

		$json = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT form_fields FROM {$table} WHERE id = %d", $form_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}

		$definition = json_decode( $json, true );
		if ( ! is_array( $definition ) || empty( $definition['fields'] ) ) {
			return array();
		}

		$out = array();
		$this->walk( $definition['fields'], $out );

		return $out;
	}

	/**
	 * Recursively collect fields, descending into container columns.
	 *
	 * @param array<int,mixed>               $elements Form elements.
	 * @param array<int,array<string,mixed>> $out      Collected fields (by reference).
	 */
	private function walk( array $elements, array &$out ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$type = (string) ( $element['element'] ?? '' );

			// Fluent Forms has three repeating elements that all submit the same
			// way — a row-major list of rows keyed by child position:
			//   - input_repeat / repeater_field: children under `fields`
			//   - repeater_container: children under `columns[].fields`
			$is_repeater = in_array( $type, array( 'input_repeat', 'repeater_field', 'repeater_container' ), true );

			// A plain layout container (not repeating) nests real fields inside
			// its columns — flatten them as regular fields.
			if ( ! $is_repeater && 'container' === $type && ! empty( $element['columns'] ) && is_array( $element['columns'] ) ) {
				foreach ( $element['columns'] as $column ) {
					if ( is_array( $column ) && ! empty( $column['fields'] ) && is_array( $column['fields'] ) ) {
						$this->walk( $column['fields'], $out );
					}
				}
				continue;
			}

			$name = (string) ( $element['attributes']['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}

			$field = array(
				'name'    => $name,
				'label'   => (string) ( $element['settings']['label'] ?? $name ),
				// Normalise so the mapper only ever sees one repeater type.
				'element' => $is_repeater ? 'repeater_field' : $type,
			);

			if ( $is_repeater ) {
				$field['columns'] = $this->repeater_columns( $element, $type );
			}

			$out[] = $field;
		}
	}

	/**
	 * Flatten a repeating element's child fields into ordered columns.
	 *
	 * `repeater_container` nests children in `columns[].fields[]`; the classic
	 * repeaters keep them in `fields[]`. Either way the flattened order is the
	 * submission's row index order, so column index === sub value.
	 *
	 * @param array<string,mixed> $element Repeating element definition.
	 * @param string              $type    Element type.
	 * @return array<int,array{index:int,name:string,label:string}>
	 */
	private function repeater_columns( array $element, string $type ): array {
		$sub_fields = array();

		if ( 'repeater_container' === $type ) {
			foreach ( (array) ( $element['columns'] ?? array() ) as $column ) {
				if ( ! is_array( $column ) || empty( $column['fields'] ) || ! is_array( $column['fields'] ) ) {
					continue;
				}
				foreach ( $column['fields'] as $sub ) {
					if ( is_array( $sub ) ) {
						$sub_fields[] = $sub;
					}
				}
			}
		} else {
			foreach ( (array) ( $element['fields'] ?? array() ) as $sub ) {
				if ( is_array( $sub ) ) {
					$sub_fields[] = $sub;
				}
			}
		}

		$columns = array();
		foreach ( array_values( $sub_fields ) as $index => $sub ) {
			$columns[] = array(
				'index' => $index,
				'name'  => (string) ( $sub['attributes']['name'] ?? (string) $index ),
				'label' => (string) ( $sub['settings']['label'] ?? (string) $index ),
			);
		}

		return $columns;
	}
}
