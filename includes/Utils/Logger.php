<?php
/**
 * Logger class.
 *
 * The `Logger` class provides a simple logging mechanism for the distribution system.
 * It collects log entries in memory and optionally writes them to the WordPress debug
 * log. By default it uses the values of `WP_DEBUG_LOG` or `WP_DEBUG` to decide whether
 * to log instantly to the file system. The design keeps logging concerns separate from
 * the core distribution logic.
 *
 * A new `Logger` instance is created immediately when the file is loaded. When you
 * instantiate the class you can pass a boolean to override the instant logging behaviour.
 * Otherwise it checks the defined constants and falls back to `false`. Static methods
 * are used for most operations, so you typically call them without creating additional
 * instances.
 *
 * The `add` method records a log entry consisting of a timestamp, a message, a type
 * and an optional context. If instant logging is enabled, it writes the message and
 * context directly using `error_log` and `var_error_log`. Otherwise it appends the
 * entry to the internal `$logs` array. The `get_logs` and `get_logs_by_type` methods
 * return all logs or a filtered subset. You can retrieve the last log entry using
 * `get_last_log`.
 *
 * To clear the stored logs call the `clear` method. If you want to output the logs
 * in HTML format the `display` method prints each message and dumps the context in a
 * `<pre>` block. The `log` method writes each stored entry to the WordPress error log
 * and optionally clears them afterward. There is also a `clear_log_file` method that
 * empties the `debug.log` file in the content directory. Use these methods carefully
 * in production environments to avoid exposing sensitive information. Always check
 * whether logging is appropriate in your context.
 */
namespace Contentsync\Utils;

defined( 'ABSPATH' ) || exit;

new Logger();

class Logger {

	/**
	 * Holds the log entries.
	 *
	 * @var array
	 */
	protected static $logs = array();

	/**
	 * Whether to log instantly.
	 *
	 * @var bool
	 */
	protected static $instantly = false;

	/**
	 * Set the log mode on instantiation.
	 *
	 * @param bool|null $instantly Whether to log instantly. If null, the log mode is determined by WP_DEBUG_LOG or WP_DEBUG.
	 */
	public function __construct( $instantly = null ) {

		if ( $instantly !== null ) {
			self::$instantly = (bool) $instantly;
		} elseif ( isset( $_GET['debug'] ) ) {
			self::$instantly = true;
		} elseif ( defined( 'WP_DEBUG_LOG' ) ) {
			self::$instantly = WP_DEBUG_LOG;
		} elseif ( defined( 'WP_DEBUG' ) ) {
			self::$instantly = WP_DEBUG;
		} else {
			self::$instantly = false;
		}
	}

	/**
	 * Add a log entry.
	 *
	 * @param string $message  The log message.
	 * @param mixed  $context   Optional. Any additional information to log.
	 * @param string $type     Optional. The type of log message. Default 'info'.
	 */
	public static function add( $message, $context = 'do_not_log', $type = 'info' ) {

		if ( ! is_string( $message ) ) {
			error_log( 'Logger::log() $message must be a string' );
			return;
		}

		if ( self::$instantly ) {
			error_log( $message );
			if ( $context !== 'do_not_log' ) {
				var_error_log( $context );
			}
		}

		self::$logs[] = array(
			'time'    => current_time( 'mysql' ),
			'message' => $message,
			'type'    => $type,
			'context' => $context,
		);
	}

	/**
	 * Get all log entries.
	 *
	 * @return array
	 */
	public static function get_logs() {
		return self::$logs;
	}

	/**
	 * Get log entries by type.
	 *
	 * @param string $type
	 *
	 * @return array
	 */
	public static function get_logs_by_type( $type ) {
		return array_filter(
			self::$logs,
			function ( $log ) use ( $type ) {
				return $log['type'] === $type;
			}
		);
	}

	/**
	 * Get the last log entry.
	 *
	 * @return array
	 */
	public static function get_last_log() {
		return end( self::$logs );
	}

	/**
	 * Clear all log entries.
	 */
	public static function clear() {
		self::$logs = array();
	}

	/**
	 * Echo log entries.
	 */
	public static function display_html() {
		foreach ( self::$logs as $log ) {
			printf(
				'<p><strong>%s</strong> %s</p>',
				$log['message'],
				$log['type'],
			);
			if ( $log['context'] !== 'do_not_log' ) {
				echo '<pre>';
				var_dump( $log['context'] );
				echo '</pre>';
			}
		}
	}

	public static function echo_logs_to_console() {
		foreach ( self::$logs as $log ) {
			echo "\r\n" . $log['message'];
			if ( $log['context'] !== 'do_not_log' ) {
				echo "\r\n";
				print_r( $log['context'] );
				echo "\r\n";
			}
		}
	}

	/**
	 * Log entries using error_log.
	 */
	public static function log( $clear = true ) {
		foreach ( self::$logs as $log ) {
			error_log( $log['message'] );
			if ( $log['context'] !== 'do_not_log' ) {
				var_error_log( $log['context'] );
			}
		}
		if ( $clear ) {
			self::clear();
		}
	}

	/**
	 * Empty the WordPress debug log file.
	 *
	 * Clears the contents of `debug.log` located in the WordPress content
	 * directory. If the file does not exist, the method does nothing.
	 * Use this during development to reset the log. Be cautious not to
	 * remove logs unintentionally in production environments.
	 *
	 * @return void
	 */
	public static function clear_log_file() {
		$log_file = WP_CONTENT_DIR . '/debug.log';
		if ( file_exists( $log_file ) ) {
			file_put_contents( $log_file, '' );
		}
	}
}
