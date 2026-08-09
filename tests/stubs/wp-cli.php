<?php
/**
 * Minimal WP-CLI runtime stand-ins for the PHPUnit suite.
 *
 * No WP-CLI runtime is available under PHPUnit. Composer carries
 * php-stubs/wp-cli-stubs, but that is a static analysis artefact for PHPStan
 * and cannot be executed. These stand-ins record what the command asked WP-CLI
 * to do so tests can assert on it, rather than writing to STDOUT — which
 * phpunit.xml.dist would fail on anyway via beStrictAboutOutputDuringTests.
 *
 * The WP_CLI constant is deliberately not defined here.
 * wp_presence_is_admin_screen_save() in includes/screen-revisions.php branches
 * on it, so defining it would change behaviour for every test in the suite.
 * Tests require includes/cli/class-wp-presence-cli-command.php directly
 * instead of going through the guard in presence-api.php.
 *
 * @package Presence_API
 */

namespace {

	/**
	 * Stands in for the process exit inside WP_CLI::error().
	 *
	 * Real WP_CLI::error() halts the command. Tests assert on that halt, so the
	 * stub throws and the caller catches, keeping control flow faithful.
	 */
	class WP_Presence_CLI_Halt extends Exception {}

	/**
	 * Base class for WP-CLI commands.
	 */
	class WP_CLI_Command {}

	/**
	 * Records the command's output calls instead of printing them.
	 */
	class WP_CLI {

		/**
		 * Output calls, in order, each with a 'type' and a 'message'.
		 *
		 * @var array[]
		 */
		public static $calls = array();

		/**
		 * Payloads passed to WP_CLI\Utils\format_items().
		 *
		 * @var array[]
		 */
		public static $formatted = array();

		/**
		 * Clears recorded state between tests.
		 */
		public static function reset() {
			self::$calls     = array();
			self::$formatted = array();
		}

		/**
		 * Records a success message.
		 *
		 * @param string $message The message.
		 */
		public static function success( $message ) {
			self::record( 'success', $message );
		}

		/**
		 * Records a log line.
		 *
		 * @param string $message The message.
		 */
		public static function log( $message ) {
			self::record( 'log', $message );
		}

		/**
		 * Records a warning.
		 *
		 * @param string $message The message.
		 */
		public static function warning( $message ) {
			self::record( 'warning', $message );
		}

		/**
		 * Records a confirmation prompt and proceeds, as answering yes would.
		 *
		 * @param string $question The question.
		 */
		public static function confirm( $question ) {
			self::record( 'confirm', $question );
		}

		/**
		 * Records an error and halts, as the real implementation does.
		 *
		 * @param string $message The message.
		 * @throws WP_Presence_CLI_Halt Always.
		 */
		public static function error( $message ) {
			self::record( 'error', $message );
			throw new WP_Presence_CLI_Halt( $message );
		}

		/**
		 * Records a command registration.
		 *
		 * @param string $name     The command name.
		 * @param mixed  $callable The command handler.
		 */
		public static function add_command( $name, $callable ) {
			self::record( 'add_command', $name );
		}

		/**
		 * Returns the recorded messages of one type, in order.
		 *
		 * @param string $type The call type, e.g. 'log' or 'success'.
		 * @return string[] The messages.
		 */
		public static function messages( $type ) {
			$messages = array();

			foreach ( self::$calls as $call ) {
				if ( $type === $call['type'] ) {
					$messages[] = $call['message'];
				}
			}

			return $messages;
		}

		/**
		 * Records one call.
		 *
		 * @param string $type    The call type.
		 * @param string $message The message.
		 */
		private static function record( $type, $message ) {
			self::$calls[] = array(
				'type'    => $type,
				'message' => $message,
			);
		}
	}
}

namespace WP_CLI\Utils {

	/**
	 * Reads an associative argument, falling back to a default.
	 *
	 * @param array  $assoc_args The associative arguments.
	 * @param string $flag       The flag name.
	 * @param mixed  $default    Optional. The fallback value. Default null.
	 * @return mixed The flag value, or the default.
	 */
	function get_flag_value( $assoc_args, $flag, $default = null ) {
		return isset( $assoc_args[ $flag ] ) ? $assoc_args[ $flag ] : $default;
	}

	/**
	 * Records a formatted item set instead of rendering it.
	 *
	 * @param string $format The output format.
	 * @param array  $items  The rows.
	 * @param array  $fields The columns.
	 */
	function format_items( $format, $items, $fields ) {
		\WP_CLI::$formatted[] = array(
			'format' => $format,
			'items'  => $items,
			'fields' => $fields,
		);
	}
}
