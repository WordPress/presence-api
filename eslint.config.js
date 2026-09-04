/**
 * ESLint flat config.
 *
 * @package Presence_API
 */

const globals = require( 'globals' );
const wpPlugin = require( '@wordpress/eslint-plugin' );

/**
 * Retargets a shipped config array at a set of files.
 *
 * @param {Array}    config Config objects from @wordpress/eslint-plugin.
 * @param {string[]} files  Glob patterns the config should apply to.
 * @return {Array} Retargeted config objects.
 */
const scopeTo = ( config, files ) =>
	config.map( ( entry ) => ( { ...entry, files } ) );

module.exports = [
	{
		ignores: [ '**/build/**', 'node_modules/**', 'vendor/**' ],
	},

	...wpPlugin.configs.recommended,

	{
		rules: {
			// File headers carry `@package Presence_API`, as the PHP does.
			'jsdoc/empty-tags': 'off',
		},
	},

	{
		// Admin scripts load as classic scripts and talk to jQuery and wp.
		files: [ 'assets/js/**/*.js' ],
		languageOptions: {
			sourceType: 'script',
			globals: {
				...globals.browser,
				jQuery: 'readonly',
				wp: 'readonly',
			},
		},
		rules: {
			// Focus retention reads the element the browser actually has.
			'@wordpress/no-global-active-element': 'off',
		},
	},

	{
		// Repository tooling runs under Node, not in a browser.
		files: [ '.github/scripts/**/*.js' ],
		languageOptions: {
			sourceType: 'commonjs',
			globals: globals.node,
		},
		rules: {
			// These scripts report to the workflow log.
			'no-console': 'off',
			// GitHub API payloads are snake_case.
			camelcase: 'off',
			// A leading underscore marks a parameter kept only for arity.
			'no-unused-vars': [ 'error', { argsIgnorePattern: '^_' } ],
		},
	},

	...scopeTo( wpPlugin.configs[ 'test-playwright' ], [
		'tests/e2e/**/*.js',
	] ),

	{
		// Bodies passed to page.evaluate() run in the browser.
		files: [ 'tests/e2e/**/*.js' ],
		languageOptions: {
			globals: {
				...globals.browser,
				jQuery: 'readonly',
				wp: 'readonly',
			},
		},
	},

	{
		// A screenshot-artifact generator, not a correctness suite: waits are
		// real time (idle/expiry has no DOM signal), the admin bar dropdown
		// check is screen-size dependent, and a captured screenshot is the
		// pass condition rather than an expect().
		files: [ 'tests/e2e/presence-screenshots.test.js' ],
		rules: {
			'playwright/expect-expect': 'off',
			'playwright/no-wait-for-timeout': 'off',
			'playwright/no-conditional-in-test': 'off',
		},
	},

	...scopeTo( wpPlugin.configs[ 'test-unit' ], [
		'**/test/**/*.js',
		'**/*.test.js',
	] ),
];
