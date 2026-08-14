<?php
/**
 * Minimal WP-CLI runtime stand-ins for the PHPUnit suite.
 *
 * Composer carries php-stubs/wp-cli-stubs for PHPStan, but that cannot be
 * executed, so these record calls instead of writing to STDOUT — which
 * beStrictAboutOutputDuringTests would fail on anyway.
 *
 * The WP_CLI constant is deliberately left undefined: wp_presence_is_admin_screen_save()
 * branches on it, so defining it would change behaviour for the whole suite.
 * Tests require the command class directly instead.
 *
 * @package Presence_API
 */

namespace {

	class WP_Presence_CLI_Halt extends Exception {}

	class WP_CLI_Command {}

	class WP_CLI {

		public static $calls = array();

		public static $formatted = array();

		public static $confirm_declines = false;

		public static function reset() {
			self::$calls            = array();
			self::$formatted        = array();
			self::$confirm_declines = false;
		}

		public static function success( $message ) {
			self::record( 'success', $message );
		}

		public static function log( $message ) {
			self::record( 'log', $message );
		}

		/**
		 * @throws WP_Presence_CLI_Halt Standing in for the process exit, when $confirm_declines is set.
		 */
		public static function confirm( $question ) {
			self::record( 'confirm', $question );

			if ( self::$confirm_declines ) {
				throw new WP_Presence_CLI_Halt( $question );
			}
		}

		/**
		 * @throws WP_Presence_CLI_Halt Standing in for the process exit.
		 */
		public static function error( $message ) {
			self::record( 'error', $message );
			throw new WP_Presence_CLI_Halt( $message );
		}

		public static function messages( $type ) {
			$messages = array();

			foreach ( self::$calls as $call ) {
				if ( $type === $call['type'] ) {
					$messages[] = $call['message'];
				}
			}

			return $messages;
		}

		private static function record( $type, $message ) {
			self::$calls[] = array(
				'type'    => $type,
				'message' => $message,
			);
		}
	}
}

namespace WP_CLI\Utils {

	function get_flag_value( $assoc_args, $flag, $default = null ) {
		return isset( $assoc_args[ $flag ] ) ? $assoc_args[ $flag ] : $default;
	}

	function format_items( $format, $items, $fields ) {
		\WP_CLI::$formatted[] = array(
			'format' => $format,
			'items'  => $items,
			'fields' => $fields,
		);
	}
}
