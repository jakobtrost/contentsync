<?php
/**
 * File Loader Logger class.
 *
 * Tracks and logs all PHP files that are loaded by the plugin, including:
 * - Files loaded via Composer autoloader (PSR-4)
 * - Files manually required/included
 * - Files loaded from the libraries directory
 *
 * Enable logging by defining CONTENTSYNC_LOG_FILE_LOADS as true in wp-config.php:
 * define( 'CONTENTSYNC_LOG_FILE_LOADS', true );
 *
 * Or use the filter: add_filter( 'contentsync_log_file_loads', '__return_true' );
 */
namespace Contentsync\Utils;

defined( 'ABSPATH' ) || exit;

class File_Loader_Logger {

	/**
	 * Whether file loading is being logged.
	 *
	 * @var bool
	 */
	protected static $enabled = false;

	/**
	 * Array of loaded files with metadata.
	 *
	 * @var array
	 */
	protected static $loaded_files = array();

	/**
	 * Files that were loaded before logger initialization.
	 *
	 * @var array
	 */
	protected static $initial_files = array();

	/**
	 * Initialize the file loader logger.
	 */
	public static function init() {
		// Store initial state of included files.
		self::$initial_files = get_included_files();

		// Check if logging is enabled via constant or filter.
		$enabled = defined( 'CONTENTSYNC_LOG_FILE_LOADS' ) && CONTENTSYNC_LOG_FILE_LOADS;
		$enabled = apply_filters( 'contentsync_log_file_loads', $enabled );

		if ( ! $enabled ) {
			return;
		}

		self::$enabled = true;

		// Track files loaded after initialization.
		add_action( 'plugins_loaded', array( __CLASS__, 'scan_loaded_files' ), 999 );
		add_action( 'init', array( __CLASS__, 'scan_loaded_files' ), 999 );
		add_action( 'wp_loaded', array( __CLASS__, 'scan_loaded_files' ), 999 );

		// Hook into autoloader to track class loads.
		spl_autoload_register( array( __CLASS__, 'autoload_logger' ), true, true );
	}

	/**
	 * Autoloader wrapper that logs file loads.
	 *
	 * @param string $class_name The class name being autoloaded.
	 */
	public static function autoload_logger( $class_name ) {
		// Only track classes from our namespace.
		if ( strpos( $class_name, 'Contentsync\\' ) !== 0 ) {
			return;
		}

		// Check if the class was loaded after autoloader runs.
		// We'll check this after a small delay to let autoloader complete.
		add_action(
			'plugins_loaded',
			function () use ( $class_name ) {
				if ( class_exists( $class_name, false ) || interface_exists( $class_name, false ) || trait_exists( $class_name, false ) ) {
					try {
						$reflection = new \ReflectionClass( $class_name );
						$file_path  = $reflection->getFileName();

						// Only log files from this plugin.
						if ( $file_path && strpos( $file_path, CONTENTSYNC_PLUGIN_PATH ) !== false ) {
							self::log_file_load( $file_path, 'autoload', $class_name );
						}
					} catch ( \Exception $e ) {
						// Reflection failed, skip.
					}
				}
			},
			1000
		);
	}

	/**
	 * Scan all loaded files and log plugin files.
	 */
	public static function scan_loaded_files() {
		$current_files = get_included_files();
		$new_files     = array_diff( $current_files, self::$initial_files );

		foreach ( $new_files as $file ) {
			// Only track files from this plugin.
			if ( strpos( $file, CONTENTSYNC_PLUGIN_PATH ) !== false ) {
				// Determine load type.
				$load_type  = 'manual';
				$identifier = basename( $file );

				// Check if it's a library file.
				if ( strpos( $file, CONTENTSYNC_PLUGIN_PATH . '/libraries/' ) !== false ) {
					$load_type = 'library';
				}
				// Check if it's from includes directory (likely autoloaded).
				elseif ( strpos( $file, CONTENTSYNC_PLUGIN_PATH . '/includes/' ) !== false ) {
					// Try to determine if it was autoloaded by checking if a class exists.
					$load_type = 'autoload';
					// Try to find the class name from the file path.
					$relative_path = str_replace( CONTENTSYNC_PLUGIN_PATH . '/includes/', '', $file );
					$relative_path = str_replace( '.php', '', $relative_path );
					$identifier    = 'Contentsync\\' . str_replace( '/', '\\', $relative_path );
				}

				self::log_file_load( $file, $load_type, $identifier );
			}
		}

		// Update initial files to avoid duplicates on subsequent scans.
		self::$initial_files = $current_files;
	}

	/**
	 * Log a file load event.
	 *
	 * @param string $file_path  The full path to the loaded file.
	 * @param string $load_type  Type of load: 'autoload', 'manual', or 'library'.
	 * @param string $identifier Class name or identifier for the file.
	 */
	public static function log_file_load( $file_path, $load_type = 'manual', $identifier = '' ) {
		// Check if logging is enabled (even if init hasn't run yet).
		$enabled = defined( 'CONTENTSYNC_LOG_FILE_LOADS' ) && CONTENTSYNC_LOG_FILE_LOADS;
		$enabled = apply_filters( 'contentsync_log_file_loads', $enabled );

		if ( ! $enabled && ! self::$enabled ) {
			return;
		}

		// Enable if not already enabled (for early calls).
		if ( ! self::$enabled && $enabled ) {
			self::$enabled = true;
		}

		// Normalize the path.
		$normalized_path = str_replace( CONTENTSYNC_PLUGIN_PATH . '/', '', $file_path );

		// Avoid duplicate entries.
		$file_key = md5( $file_path );
		if ( isset( self::$loaded_files[ $file_key ] ) ) {
			return;
		}

		$entry = array(
			'path'       => $normalized_path,
			'full_path'  => $file_path,
			'type'       => $load_type,
			'identifier' => $identifier,
			'time'       => microtime( true ),
			'timestamp'  => current_time( 'mysql' ),
		);

		self::$loaded_files[ $file_key ] = $entry;

		// Log to Logger if available.
		if ( class_exists( __NAMESPACE__ . '\Logger' ) ) {
			$message = sprintf(
				'[File Load] %s: %s (%s)',
				$load_type,
				$normalized_path,
				$identifier ? $identifier : 'N/A'
			);
			Logger::add( $message );
			// Logger::add( $message, $entry, 'file_load' );
		}
	}

	/**
	 * Get all loaded files.
	 *
	 * @return array
	 */
	public static function get_loaded_files() {
		return self::$loaded_files;
	}

	/**
	 * Get loaded files by type.
	 *
	 * @param string $type The load type: 'autoload', 'manual', or 'library'.
	 * @return array
	 */
	public static function get_loaded_files_by_type( $type ) {
		return array_filter(
			self::$loaded_files,
			function ( $file ) use ( $type ) {
				return $file['type'] === $type;
			}
		);
	}

	/**
	 * Get a summary of loaded files.
	 *
	 * @return array
	 */
	public static function get_summary() {
		$summary = array(
			'total'     => count( self::$loaded_files ),
			'autoload'  => count( self::get_loaded_files_by_type( 'autoload' ) ),
			'manual'    => count( self::get_loaded_files_by_type( 'manual' ) ),
			'libraries' => count( self::get_loaded_files_by_type( 'library' ) ),
			'files'     => array(),
		);

		foreach ( self::$loaded_files as $file ) {
			$summary['files'][] = $file['path'];
		}

		return $summary;
	}

	/**
	 * Clear all logged files.
	 */
	public static function clear() {
		self::$loaded_files = array();
	}

	/**
	 * Display loaded files in HTML format.
	 */
	public static function display_html() {
		if ( ! self::$enabled ) {
			echo '<p>File loading logger is not enabled. Define <code>CONTENTSYNC_LOG_FILE_LOADS</code> as true to enable.</p>';
			return;
		}

		$summary = self::get_summary();
		?>
		<div style="margin: 20px 0;">
			<h3>File Load Summary</h3>
			<ul>
				<li><strong>Total files loaded:</strong> <?php echo esc_html( $summary['total'] ); ?></li>
				<li><strong>Autoloaded:</strong> <?php echo esc_html( $summary['autoload'] ); ?></li>
				<li><strong>Manually loaded:</strong> <?php echo esc_html( $summary['manual'] ); ?></li>
				<li><strong>Libraries:</strong> <?php echo esc_html( $summary['libraries'] ); ?></li>
			</ul>

			<h4>Loaded Files:</h4>
			<ol style="max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px;">
				<?php foreach ( self::$loaded_files as $file ) : ?>
					<li>
						<strong><?php echo esc_html( $file['type'] ); ?></strong>:
						<?php echo esc_html( $file['path'] ); ?>
						<?php if ( $file['identifier'] ) : ?>
							<em>(<?php echo esc_html( $file['identifier'] ); ?>)</em>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
		<?php
	}
}
