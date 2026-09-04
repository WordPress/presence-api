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
		rules: {
			// Swapping these waits changes what the specs actually wait for,
			// so they are flagged to be revisited rather than failed on.
			'playwright/no-networkidle': 'warn',
		},
	},

	...scopeTo( wpPlugin.configs[ 'test-unit' ], [
		'**/test/**/*.js',
		'**/*.test.js',
	] ),
];
