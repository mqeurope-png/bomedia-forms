<?php
/**
 * Lightweight file logger.
 *
 * Writes one line per event to wp-content/uploads/bf-logs/{channel}.log.
 * The directory is created on demand and hardened with a .htaccess deny
 * and an empty index.php so logs are not web-accessible.
 *
 * Privacy: callers must pass only metadata. Never hand full submission /
 * contact bodies to this logger.
 *
 * @package BomediaForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BF_Logger
 */
class BF_Logger {

	/**
	 * Absolute path to the log directory (cached per request).
	 *
	 * @var string|null
	 */
	private static $dir = null;

	/**
	 * Resolve (and harden) the log directory.
	 *
	 * @return string|null Directory path, or null if it cannot be created.
	 */
	private static function dir() {
		if ( null !== self::$dir ) {
			return self::$dir ? self::$dir : null;
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			self::$dir = '';
			return null;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'bf-logs';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( is_dir( $dir ) ) {
			$ht = $dir . '/.htaccess';
			if ( ! file_exists( $ht ) ) {
				@file_put_contents( $ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore
			}
			$idx = $dir . '/index.php';
			if ( ! file_exists( $idx ) ) {
				@file_put_contents( $idx, "<?php // Silence is golden.\n" ); // phpcs:ignore
			}
			self::$dir = $dir;
			return $dir;
		}

		self::$dir = '';
		return null;
	}

	/**
	 * Append a log line.
	 *
	 * @param string $channel Channel / file name (agilecrm|email|spam|admin).
	 * @param string $message Single-line metadata message (no PII).
	 * @return void
	 */
	public static function log( $channel, $message ) {
		$dir = self::dir();
		if ( ! $dir ) {
			return;
		}

		$channel = preg_replace( '/[^a-z0-9_-]/', '', (string) $channel );
		if ( '' === $channel ) {
			$channel = 'general';
		}

		$line = sprintf(
			"[%s] %s\n",
			gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			str_replace( array( "\r", "\n" ), ' ', (string) $message )
		);

		@file_put_contents( $dir . '/' . $channel . '.log', $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore
	}
}
