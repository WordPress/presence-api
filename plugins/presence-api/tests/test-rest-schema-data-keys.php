<?php
/**
 * Tests that the REST item schema documents known Heartbeat data keys.
 *
 * @package Presence_API
 *
 * @group presence
 *
 * @covers WP_REST_Presence_Controller::get_item_schema
 */
class WP_Test_Presence_REST_Schema_Data_Keys extends WP_Presence_UnitTestCase {

	public function test_item_schema_documents_known_data_keys() {
		$controller = new WP_REST_Presence_Controller();
		$schema     = $controller->get_item_schema();
		$data       = $schema['properties']['data'];

		$this->assertTrue( $data['additionalProperties'] );
		foreach ( array( 'screen', 'post_id', 'post_status', 'title' ) as $key ) {
			$this->assertArrayHasKey( $key, $data['properties'], $key . ' should be documented on data.' );
		}
		$this->assertSame( 'integer', $data['properties']['post_id']['type'] );
	}
}
