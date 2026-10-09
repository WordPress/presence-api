<?php
/**
 * Tests that the API's declarations step aside when core already has them.
 *
 * @package Presence_API
 *
 * @group presence
 *
 * @coversNothing
 */
class WP_Test_Presence_Core_Guards extends WP_Presence_UnitTestCase {

	/**
	 * Files whose functions and classes core would ship under the same names.
	 *
	 * @return array[]
	 */
	public static function data_api_files() {
		return array(
			'presence'           => array( 'includes/presence.php' ),
			'rest controller'    => array( 'includes/rest-api/endpoints/class-wp-rest-presence-controller.php' ),
			'network controller' => array( 'includes/rest-api/endpoints/class-wp-rest-presence-network-controller.php' ),
			'cli command'        => array( 'includes/cli/class-wp-presence-cli-command.php' ),
		);
	}

	/**
	 * Redeclaring a name core already loaded is a fatal, so a function added
	 * without its guard breaks the plugin on the first release that adopts it.
	 *
	 * @dataProvider data_api_files
	 *
	 * @param string $file Path relative to the plugin directory.
	 */
	public function test_every_declaration_is_wrapped_in_a_guard_naming_it( $file ) {
		$tokens    = token_get_all( file_get_contents( WP_PRESENCE_PLUGIN_DIR . $file ) );
		$depth     = 0;
		$guard     = null;
		$unguarded = array();
		$mismatch  = array();
		$count     = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( '{' === $token || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				++$depth;
				continue;
			}
			if ( '}' === $token ) {
				--$depth;
				continue;
			}
			if ( ! is_array( $token ) ) {
				continue;
			}

			if ( 0 === $depth && T_STRING === $token[0] && in_array( $token[1], array( 'function_exists', 'class_exists' ), true ) ) {
				$guard = trim( $this->next_token( $tokens, $i, T_CONSTANT_ENCAPSED_STRING ), '\'"' );
				continue;
			}

			if ( $depth > 1 || ! in_array( $token[0], array( T_FUNCTION, T_CLASS ), true ) ) {
				continue;
			}

			$name = $this->next_token( $tokens, $i, T_STRING );

			if ( 0 === $depth ) {
				$unguarded[] = $name;
			} elseif ( $guard !== $name ) {
				$mismatch[] = $name;
			}
		}

		$this->assertSame( array(), $unguarded, 'Declared without a guard.' );
		$this->assertSame( array(), $mismatch, 'Guarded by a check naming something else.' );
	}

	/**
	 * Returns the text of the next token of a type after a position.
	 *
	 * @param array $tokens Tokens from token_get_all().
	 * @param int   $i      Position to search after.
	 * @param int   $type   Token type to find.
	 * @return string The token's text, or an empty string if none follows.
	 */
	private function next_token( $tokens, $i, $type ) {
		$count = count( $tokens );

		for ( ++$i; $i < $count; $i++ ) {
			if ( is_array( $tokens[ $i ] ) && $type === $tokens[ $i ][0] ) {
				return $tokens[ $i ][1];
			}
		}

		return '';
	}
}
