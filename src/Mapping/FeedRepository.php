<?php
/**
 * Feed persistence — a feed links a Fluent Form to a PDF template with a mapping.
 *
 * @package FFPM
 */

namespace FFPM\Mapping;

use FFPM\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for mapping feeds.
 */
final class FeedRepository {

	/**
	 * Default feed settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_settings(): array {
		return array(
			'attach_emails' => true,
			'filename'      => '{form}-{id}',
			'condition'     => array(
				'enabled' => false,
				'field'   => '',
				'value'   => '',
			),
		);
	}

	/**
	 * Insert a feed.
	 *
	 * @param int    $form_id     Fluent Forms form id.
	 * @param int    $template_id Template id.
	 * @param string $title       Feed title.
	 * @return int New feed id, or 0 on failure.
	 */
	public function insert( int $form_id, int $template_id, string $title ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Schema::table( 'feeds' ),
			array(
				'form_id'     => $form_id,
				'template_id' => $template_id,
				'title'       => $title,
				'status'      => 'active',
				'mapping'     => wp_json_encode(
					array(
					'version'  => 1,
					'fields'   => array(),
					'repeater' => null,
					)
				), // phpcs:ignore Universal.Arrays.MixedArrayKeyTypes -- plain data array.
				'settings'    => wp_json_encode( self::default_settings() ),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Fetch one feed.
	 *
	 * @param int $id Feed id.
	 */
	public function find( int $id ): ?object {
		global $wpdb;

		$table = Schema::table( 'feeds' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return is_object( $row ) ? $row : null;
	}

	/**
	 * All feeds, newest first.
	 *
	 * @return object[]
	 */
	public function all(): array {
		global $wpdb;

		$table = Schema::table( 'feeds' );

		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Active feeds for a form.
	 *
	 * @param int $form_id Fluent Forms form id.
	 * @return object[]
	 */
	public function active_for_form( int $form_id ): array {
		global $wpdb;

		$table = Schema::table( 'feeds' );

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table} WHERE form_id = %d AND status = 'active'", $form_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Persist mapping + settings JSON for a feed.
	 *
	 * @param int    $id       Feed id.
	 * @param string $mapping  Mapping JSON (already validated).
	 * @param string $settings Settings JSON (already validated).
	 * @param string $status   Feed status: active|paused.
	 */
	public function save_configuration( int $id, string $mapping, string $settings, string $status ): bool {
		global $wpdb;

		return false !== $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Schema::table( 'feeds' ),
			array(
				'mapping'    => $mapping,
				'settings'   => $settings,
				'status'     => 'paused' === $status ? 'paused' : 'active',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete a feed.
	 *
	 * @param int $id Feed id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( Schema::table( 'feeds' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}
}
