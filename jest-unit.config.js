const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...defaultConfig,
	testMatch: [
		'<rootDir>/src/**/test/**/*.[jt]s?(x)',
		'<rootDir>/src/**/*.test.[jt]s?(x)',
		'<rootDir>/assets/js/test/**/*.[jt]s?(x)',
		'<rootDir>/assets/js/**/*.test.[jt]s?(x)',
	],
};
