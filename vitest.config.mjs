import { defineConfig } from 'vitest/config';

export default defineConfig( {
	test: {
		include: [ 'plugins/presence-api/{src,assets/js}/**/*.test.js' ],
		environment: 'jsdom',
	},
} );
