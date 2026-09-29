const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...defaultConfig,
	testMatch: [
		'<rootDir>/plugins/presence-api/src/**/test/**/*.[jt]s?(x)',
		'<rootDir>/plugins/presence-api/src/**/*.test.[jt]s?(x)',
		'<rootDir>/plugins/presence-api/assets/js/test/**/*.[jt]s?(x)',
		'<rootDir>/plugins/presence-api/assets/js/**/*.test.[jt]s?(x)',
	],
};
