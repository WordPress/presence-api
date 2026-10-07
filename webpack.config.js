/**
 * Builds `src/` into the `wp-presence` script, exposed as `wp.presence`, and the synced pattern notice that uses it.
 *
 * @package Presence_API
 */

const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		index: {
			import: path.resolve(
				__dirname,
				'plugins/presence-api/src/index.js'
			),
			// stale-screen.js also writes to wp.presence, so merge rather than replace.
			library: { name: [ 'wp', 'presence' ], type: 'assign-properties' },
		},
		'synced-patterns': path.resolve(
			__dirname,
			'plugins/presence-api/src/synced-patterns/index.js'
		),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'plugins/presence-api/build' ),
	},
};
