<?php
/**
 * Generated document persistence.
 *
 * @package FFPM
 */

namespace FFPM\Submission;

use FFPM\Install\Schema;
use FFPM\Support\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for generated documents.
 */
final class DocumentRepository {

	/**
	 * Record a generated document.
	 *
	 * @param int    $feed_id       Feed id.
	 * @param int    $form_id       Form id.
	 * @param int    $submission_id Fluent Forms submission id.
	 * @param string $file_name     Stored file name.
	 * @param string $status        generated|failed.
	 * @param string $error_message Error detail when failed.
	 * @return int Document id, or 0 on failure.
	 */
	public function insert( int $feed_id, int $form_id, int $submission_id, string $file_name, string $status = 'generated', string $error_message = '' ): int {
		global $wpdb;

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Schema::table( 'documents' ),
			array(
				'feed_id'       => $feed_id,
				'form_id'       => $form_id,
				'submission_id' => $submission_id,
				'file_name'     => $file_name,
				'status'        => $status,
				'error_message' => $error_message,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Fetch one document.
	 *
	 * @param int $id Document id.
	 */
	public function find( int $id ): ?object {
		global $wpdb;

		$table = Schema::table( 'documents' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return is_object( $row ) ? $row : null;
	}

	/**
	 * Paged document list, optionally filtered by form.
	 *
	 * @param int $form_id  0 for all forms.
	 * @param int $page     1-based page number.
	 * @param int $per_page Rows per page.
	 * @return array{rows:object[],total:int}
	 */
	public function paged( int $form_id, int $page, int $per_page = 20 ): array {
		global $wpdb;

		$table  = Schema::table( 'documents' );
		$offset = max( 0, ( $page - 1 ) * $per_page );

		if ( $form_id > 0 ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $form_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE form_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", $form_id, $per_page, $offset ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Documents generated for a submission.
	 *
	 * @param int $submission_id Submission id.
	 * @return object[]
	 */
	public function for_submission( int $submission_id ): array {
		global $wpdb;

		$table = Schema::table( 'documents' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table} WHERE submission_id = %d AND status = 'generated'", $submission_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return $rows ? $rows : array();
	}

	/**
	 * Delete a document row and its file.
	 *
	 * @param int $id Document id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$document = $this->find( $id );
		if ( null === $document ) {
			return false;
		}

		$deleted = (bool) $wpdb->delete( Schema::table( 'documents' ), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( $deleted ) {
			$path = Storage::path( 'documents', (string) $document->file_name );
			if ( null !== $path ) {
				wp_delete_file( $path );
			}
		}

		return $deleted;
	}
}
