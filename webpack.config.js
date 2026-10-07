/**
 * Builds `src/` into the `wp-presence` script, exposed as `wp.presence`.
 *
 * @package Presence_API
 */

const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve( __dirname, 'plugins/presence-api/src/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'plugins/presence-api/build' ),
		// stale-screen.js also writes to wp.presence, so merge rather than replace.
		library: { name: [ 'wp', 'presence' ], type: 'assign-properties' },
	},
};
