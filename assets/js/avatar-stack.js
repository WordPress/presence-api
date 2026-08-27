/**
 * Overlapping avatar stack, client side.
 *
 * The counterpart to wp_presence_render_avatar_stack(). Both "Who's Online"
 * widgets paint the stack server-side and then repaint it from a Heartbeat
 * tick, so the two renderers have to emit the same markup.
 *
 * @package Presence_API
 */
( function () {
	'use strict';

	function esc( str ) {
		var el = document.createElement( 'span' );
		el.textContent = str;
		return el.innerHTML;
	}

	/**
	 * Builds an avatar stack.
	 *
	 * On `window` only because the two widgets' inline scripts are enqueued
	 * separately and have no other way to share it. Nothing outside the plugin
	 * is meant to call it.
	 *
	 * @private
	 * @param {Array}  users Users, each with an avatar_url and a display_name.
	 * @param {number} max   Maximum number of avatars to show.
	 * @return {string} HTML markup.
	 */
	window.wpPresenceBuildAvatarStack = function ( users, max ) {
		// get_avatar_url() returns false with the Show Avatars setting off.
		var shown = users.slice( 0, max ).filter( function ( user ) {
			return !! user.avatar_url;
		} );

		var html = '<span class="presence-avatar-stack">';

		// The avatars overlap, so the first one has to paint on top.
		shown.forEach( function ( user, index ) {
			html += '<img src="' + esc( user.avatar_url ) + '" width="20" height="20" style="z-index:' + ( shown.length - index ) + '" alt="' + esc( user.display_name ) + '" />';
		} );

		return html + '</span>';
	};
}() );
