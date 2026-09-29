<?php
/**
 * Tests for scenes.
 *
 * @package Presence_Scenes
 *
 * @group presence
 */

class WP_Test_Presence_Scenes extends WP_Presence_UnitTestCase {

	/**
	 * @covers ::wp_presence_get_scenes
	 */
	public function test_loads_every_bundled_scene() {
		$this->assertCount( count( glob( dirname( __DIR__ ) . '/library/*.json' ) ), wp_presence_get_scenes() );
	}

	public function data_bundled_files() {
		$files = array();
		foreach ( glob( dirname( __DIR__ ) . '/library/*.json' ) as $file ) {
			$files[ basename( $file, '.json' ) ] = array( $file );
		}
		return $files;
	}

	/**
	 * @dataProvider data_bundled_files
	 *
	 * @covers ::wp_presence_scene_places
	 * @covers ::wp_presence_scene_actions
	 * @covers ::wp_presence_scene_prepare
	 * @covers ::wp_presence_scene_text
	 * @covers ::wp_presence_scene_actor_name
	 */
	public function test_bundled_scene_is_valid( $file ) {
		$scene = wp_presence_scene_prepare( wp_json_file_decode( $file, array( 'associative' => true ) ) );

		$this->assertNotWPError( $scene );
		$this->assertSame( basename( $file, '.json' ), $scene['name'] );
	}

	public function data_invalid_steps() {
		$limits = wp_presence_scene_limits();

		return array(
			'unknown key'         => array( array( 'author' => 'admin' ), array() ),
			'no apiVersion'       => array( array( 'apiVersion' => null ), array() ),
			'uppercase name'      => array( array( 'name' => 'Invalid' ), array() ),
			'no title'            => array( array( 'title' => null ), array() ),
			'no steps'            => array( array( 'steps' => array() ), array() ),
			'unknown field'       => array( array(), array( 'post' => 'Draft' ) ),
			'too late'            => array( array(), array( 'at' => $limits['at'] + 1 ) ),
			'actor not cast'      => array( array(), array( 'actor' => 2 ) ),
			'unknown place'       => array( array(), array( 'place' => 'plugins' ) ),
			'unknown step'        => array( array(), array( 'step' => 'deleteUser' ) ),
			'post never written'  => array( array(), array( 'step' => 'open', 'post' => 'Draft' ) ),
			'markup in the title' => array( array(), array( 'step' => 'write', 'title' => '<b>Hi</b>' ) ),
			'administrator cast'  => array( array( 'cast' => array( 'administrator' ) ), array() ),
			'repeated title'      => array( array( 'steps' => array_fill( 0, 2, array( 'at' => 0, 'actor' => 1, 'step' => 'write', 'title' => 'Draft' ) ) ), array() ),
			'too many roles'      => array( array( 'cast' => array_fill( 0, $limits['cast'] + 1, 'author' ) ), array() ),
			'back in time'        => array( array( 'steps' => array( array( 'at' => 1, 'actor' => 1, 'step' => 'drop' ), array( 'at' => 0, 'actor' => 1, 'step' => 'drop' ) ) ), array() ),
			'whole cast writing'  => array( array(), array( 'actor' => 'cast', 'step' => 'write', 'title' => 'Draft' ) ),
			'long title'          => array( array(), array( 'step' => 'write', 'title' => str_repeat( 'a', $limits['text'] + 1 ) ) ),
		);
	}

	/**
	 * @dataProvider data_invalid_steps
	 *
	 * @covers ::wp_presence_scene_places
	 * @covers ::wp_presence_scene_actions
	 * @covers ::wp_presence_scene_prepare
	 * @covers ::wp_presence_scene_text
	 */
	public function test_refuses_an_invalid_scene( $scene, $step ) {
		$scene = array_filter(
			$scene + array(
				'apiVersion' => 1,
				'name'       => 'invalid',
				'title'      => 'Invalid',
				'cast'       => array( 'author' ),
				'steps'      => array(
					$step + array(
						'at'    => 0,
						'actor' => 1,
						'step'  => 'visit',
						'place' => 'posts',
					),
				),
			),
			function ( $value ) {
				return null !== $value;
			}
		);

		if ( isset( $step['step'] ) && 'visit' !== $step['step'] ) {
			unset( $scene['steps'][0]['place'] );
		}

		$this->assertWPError( wp_presence_scene_prepare( $scene ) );
	}
}
