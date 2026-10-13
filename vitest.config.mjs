import { transformWithOxc } from 'vite';
import { defineConfig } from 'vitest/config';

export default defineConfig( {
	plugins: [
		{
			// The editor scripts in src/ are JSX in .js files, which Vite only parses as JSX when told to.
			name: 'presence-api-jsx-in-js',
			enforce: 'pre',
			transform( code, id ) {
				if ( ! /\/plugins\/presence-api\/src\/.*\.js$/.test( id ) ) {
					return null;
				}

				return transformWithOxc( code, id, {
					lang: 'jsx',
					jsx: { runtime: 'automatic' },
				} );
			},
		},
	],
	test: {
		include: [ 'plugins/presence-api/{src,assets/js}/**/*.test.js' ],
		environment: 'jsdom',
	},
} );
