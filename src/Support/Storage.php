<?php
/**
 * Protected file storage for templates and generated documents.
 *
 * @package FFPM
 */

namespace FFPM\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the protected uploads directory.
 *
 * Layout: wp-content/uploads/ffpm-{random}/{templates,documents}/
 * Direct HTTP access is denied via .htaccess (Apache/LiteSpeed); on servers
 * that ignore .htaccess (nginx) the random directory segment plus random file
 * names keep paths unguessable. Files are served only through
 * capability-checked download endpoints.
 */
final class Storage {

	private const OPTION_KEY = 'ffpm_storage_key';

	/**
	 * Random per-site directory segment, created once on activation.
	 */
	public static function storage_key(): string {
		$key = get_option( self::OPTION_KEY );

		if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z0-9]{16}$/', $key ) ) {
			$key = wp_generate_password( 16, false, false );
			update_option( self::OPTION_KEY, $key, false );
		}

		return $key;
	}

	/**
	 * Absolute path to a plugin storage subdirectory (no trailing slash).
	 *
	 * @param string $subdir Subdirectory: templates|documents.
	 */
	public static function dir( string $subdir ): string {
		$uploads = wp_upload_dir( null, false );

		return $uploads['basedir'] . '/ffpm-' . self::storage_key() . '/' . $subdir;
	}

	/**
	 * Create storage directories with deny-all protection files.
	 */
	public static function ensure_directories(): void {
		foreach ( array( 'templates', 'documents' ) as $subdir ) {
			$dir = self::dir( $subdir );

			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			$htaccess = $dir . '/.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				// Apache 2.4 (Require) with 2.2 fallback (Order/Deny); LiteSpeed honours both.
				$rules = "Require all denied\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
				file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}

			$index = $dir . '/index.php';
			if ( ! file_exists( $index ) ) {
				file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/**
	 * Generate an unguessable stored file name preserving the .pdf extension.
	 */
	public static function random_pdf_name(): string {
		return wp_generate_password( 32, false, false ) . '.pdf';
	}

	/**
	 * Resolve a stored file name to an absolute path, rejecting traversal.
	 *
	 * Template names are strictly the 32-char random form; document names are
	 * pattern-based but limited to a safe basename charset with a random
	 * suffix. Anything else — separators, dots-only runs, unicode — is hostile.
	 *
	 * @param string $subdir    Subdirectory: templates|documents.
	 * @param string $file_name Stored file name (as saved in DB).
	 * @return string|null Absolute path, or null when the name is invalid or missing.
	 */
	public static function path( string $subdir, string $file_name ): ?string {
		$pattern = 'templates' === $subdir
			? '/^[A-Za-z0-9]{32}\.pdf$/'
			: '/^[A-Za-z0-9][A-Za-z0-9._-]{0,148}\.pdf$/';

		if ( ! preg_match( $pattern, $file_name ) || str_contains( $file_name, '..' ) ) {
			return null;
		}

		$path = self::dir( $subdir ) . '/' . $file_name;

		return is_file( $path ) ? $path : null;
	}
}
