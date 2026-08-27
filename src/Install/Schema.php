<?php
/**
 * Database schema definitions.
 *
 * @package FFPM
 */

namespace FFPM\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Central definition of plugin tables for dbDelta.
 */
final class Schema {

	/**
	 * Schema version — bump when any table definition changes.
	 */
	public const VERSION = '1';

	/**
	 * Full table name with WordPress prefix.
	 *
	 * @param string $table Short table key: templates|feeds|documents.
	 */
	public static function table( string $table ): string {
		global $wpdb;

		return $wpdb->prefix . 'ffpm_' . $table;
	}

	/**
	 * dbDelta-compatible CREATE TABLE statements.
	 *
	 * @return string[]
	 */
	public static function statements(): array {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$templates       = self::table( 'templates' );
		$feeds           = self::table( 'feeds' );
		$documents       = self::table( 'documents' );

		return array(
			"CREATE TABLE {$templates} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				title varchar(190) NOT NULL,
				file_name varchar(190) NOT NULL,
				original_name varchar(190) NOT NULL,
				file_hash char(64) NOT NULL,
				file_size bigint(20) unsigned NOT NULL DEFAULT 0,
				page_count smallint(5) unsigned NOT NULL DEFAULT 0,
				page_width decimal(8,2) NOT NULL DEFAULT 0,
				page_height decimal(8,2) NOT NULL DEFAULT 0,
				detected_fields longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY file_hash (file_hash)
			) {$charset_collate}",
			"CREATE TABLE {$feeds} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				template_id bigint(20) unsigned NOT NULL,
				title varchar(190) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				mapping longtext NULL,
				settings longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY form_id (form_id),
				KEY template_id (template_id)
			) {$charset_collate}",
			"CREATE TABLE {$documents} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				feed_id bigint(20) unsigned NOT NULL,
				form_id bigint(20) unsigned NOT NULL,
				submission_id bigint(20) unsigned NOT NULL,
				file_name varchar(190) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'generated',
				error_message text NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY submission_id (submission_id),
				KEY form_id (form_id),
				KEY feed_id (feed_id)
			) {$charset_collate}",
		);
	}
}
