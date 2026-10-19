/**
 * Presence API — markup validation for the markup validity specs.
 *
 * Checks the plugin's fragments only, since whole pages carry core's errors too.
 *
 * @package WordPress
 */
import { HtmlValidate, Parser } from 'html-validate';

// Plus the Nu Html Checker errors the standards preset leaves out.
const htmlValidate = new HtmlValidate( {
	extends: [ 'html-validate:standard' ],
	rules: {
		'aria-label-misuse': 'error',
		'wcag/h37': 'error',
	},
} );

/**
 * Validates every element matching a selector, as the server sent it.
 *
 * @param {import('@playwright/test').Page} page     Page to read.
 * @param {string}                          selector Plugin-owned fragment.
 * @return {Promise<string[]>} One line per problem, empty when the markup is valid.
 */
export async function markupErrors( page, selector ) {
	const html = await ( await page.request.get( page.url() ) ).text();
	const config = await htmlValidate.getConfigFor( 'inline' );
	const roots = new Parser( config )
		.parseHtml( html )
		.querySelectorAll( selector )
		.map( ( el ) => el.generateSelector() );

	// Markup that JavaScript adds is not in the response, so only the browser's copy can be checked.
	if ( ! roots.length ) {
		return outerHtmlErrors( page, selector );
	}

	const report = await htmlValidate.validateString( html );

	return report.results
		.flatMap( ( result ) => result.messages )
		.filter( ( { selector: at } ) =>
			roots.some(
				( root ) => at === root || at?.startsWith( `${ root } ` )
			)
		)
		.map(
			( message ) =>
				`${ selector }: ${ message.ruleId }: ${ message.message } at ${ message.selector } (line ${ message.line })`
		);
}

/**
 * Validates the browser's copy of every element matching a selector on its own.
 *
 * @param {import('@playwright/test').Page} page     Page to read.
 * @param {string}                          selector Plugin-owned fragment.
 * @return {Promise<string[]>} One line per problem, empty when the markup is valid.
 */
async function outerHtmlErrors( page, selector ) {
	const fragments = await page
		.locator( selector )
		.evaluateAll( ( elements ) => elements.map( ( el ) => el.outerHTML ) );

	if ( ! fragments.length ) {
		return [ `${ selector } is not on the page` ];
	}

	const errors = [];
	for ( const fragment of fragments ) {
		const report = await htmlValidate.validateString( fragment );
		for ( const result of report.results ) {
			for ( const message of result.messages ) {
				errors.push(
					`${ selector }: ${ message.ruleId }: ${ message.message } in ${ fragment }`
				);
			}
		}
	}

	return errors;
}
