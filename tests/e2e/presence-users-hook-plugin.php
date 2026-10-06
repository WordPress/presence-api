<?php
/**
 * Plugin Name: Presence Users Hook Consumer
 * Description: Depends on the `wp-presence` script the way another plugin would, for presence-users-hook.test.js.
 *
 * @package Presence_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'enqueue_block_editor_assets',
	function () {
		wp_register_script( 'presence-users-hook-consumer', false, array( 'wp-presence', 'wp-plugins', 'wp-editor' ), '1.0.0', true );
		wp_enqueue_script( 'presence-users-hook-consumer' );
		wp_add_inline_script(
			'presence-users-hook-consumer',
			<<<'JS'
wp.plugins.registerPlugin( 'presence-users-hook-consumer', {
	render() {
		const postId = wp.data.useSelect(
			( select ) => select( 'core/editor' ).getCurrentPostId(),
			[]
		);
		const { isLoading, users } = wp.presence.usePresenceUsers(
			postId ? 'postType/post:' + postId : null
		);

		return wp.element.createElement(
			'output',
			{ id: 'presence-users-hook-consumer', 'data-loading': String( isLoading ) },
			users.map( ( user ) => user.id ).join( ',' )
		);
	},
} );
JS
		);
	}
);
