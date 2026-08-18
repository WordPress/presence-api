/**
 * Heartbeat event utilities.
 *
 * WordPress core's heartbeat.js currently triggers events via jQuery.
 * These utilities provide a forward-compatible abstraction that works
 * with or without jQuery.
 *
 * @package Presence_API
 */

/**
 * Subscribes to heartbeat tick events.
 *
 * WordPress core triggers 'heartbeat-tick' via jQuery's event system.
 * This function bridges to that event while avoiding a hard jQuery dependency.
 *
 * @param {Function} callback Function to call on each heartbeat tick.
 * @return {Function} Cleanup function to remove the listener.
 */
export function onHeartbeatTick( callback ) {
	const wrappedCallback = ( event, data ) => {
		callback( data );
	};

	if ( typeof window.jQuery !== 'undefined' ) {
		window.jQuery( document ).on( 'heartbeat-tick.presenceApi', wrappedCallback );

		return () => {
			window.jQuery( document ).off( 'heartbeat-tick.presenceApi', wrappedCallback );
		};
	}

	// Fallback: if jQuery isn't available, listen for native CustomEvent.
	// This path is used in test environments and future jQuery-free WordPress.
	const nativeCallback = ( event ) => {
		callback( event.detail );
	};

	document.addEventListener( 'heartbeat-tick', nativeCallback );

	return () => {
		document.removeEventListener( 'heartbeat-tick', nativeCallback );
	};
}

/**
 * Checks if the WordPress Heartbeat API is available.
 *
 * @return {boolean} True if heartbeat is available.
 */
export function isHeartbeatAvailable() {
	return typeof window.wp?.heartbeat !== 'undefined';
}
