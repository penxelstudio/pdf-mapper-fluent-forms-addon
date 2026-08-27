<?php
/**
 * Template persistence.
 *
 * @package FFPM
 */

namespace FFPM\Templates;

use FFPM\Install\Schema;
use FFPM\Support\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for uploaded PDF templates.
 */
final class TemplateRepository {

	/**
	 * Insert a template row.
	 *
	 * @param array{title:string,file_name:string,original_name:string,file_hash:string,file_size:int,page_count:int,page_width:float,page_height:float} $data Template data.
	 * @return int New template id, or 0 on failure.
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Schema::table( 'templates' ),
			array(
				'title'         => $data['title'],
				'file_name'     => $data['file_name'],
				'original_name' => $data['original_name'],
				'file_hash'     => $data['file_hash'],
				'file_size'     => $data['file_size'],
				'page_count'    => $data['page_count'],
				'page_width'    => $data['page_width'],
				'page_height'   => $data['page_height'],
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%f', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Fetch one template.
	 *
	 * @param int $id Template id.
	 */
	public function find( int $id ): ?object {
		global $wpdb;

		$table = Schema::table( 'templates' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return is_object( $row ) ? $row : null;
	}

	/**
	 * All templates, newest first.
	 *
	 * @return object[]
	 */
	public function all(): array {
		global $wpdb;

		$table = Schema::table( 'templates' );

		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Number of feeds referencing a template.
	 *
	 * @param int $id Template id.
	 */
	public function feed_count( int $id ): int {
		global $wpdb;

		$feeds = Schema::table( 'feeds' );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT COUNT(*) FROM {$feeds} WHERE template_id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Delete a template row and its stored file.
	 *
	 * @param int $id Template id.
	 * @return bool True when the row was deleted.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$template = $this->find( $id );
		if ( null === $template ) {
			return false;
		}

		$deleted = (bool) $wpdb->delete( Schema::table( 'templates' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( $deleted ) {
			$path = Storage::path( 'templates', (string) $template->file_name );
			if ( null !== $path ) {
				wp_delete_file( $path );
			}
		}

		return $deleted;
	}
}
