/**
 * Presence API — helpers shared by the Network Admin specs.
 *
 * The other specs each carry their own copy of `wpCli`. These do not, because
 * every command here has to reach the multisite wp-env instance rather than
 * the default one, and that instance is selected by the working directory the
 * command runs from — a detail worth stating once.
 *
 * The fixture network itself (the sub-site and the users named below) is
 * created by scripts/start-multisite-env.sh, not by the specs.
 *
 * @package WordPress
 */
import { execSync } from 'node:child_process';
import path from 'node:path';

/**
 * Holds the .wp-env.json for the multisite instance.
 *
 * wp-env picks an instance by the directory it runs in, so every wp-env
 * command below runs from here.
 */
const MULTISITE_ENV_DIR = path.resolve( __dirname, 'multisite' );

const BASE_URL = (
	process.env.WP_MULTISITE_BASE_URL || 'http://localhost:8890'
).replace( /\/$/, '' );

/**
 * The sub-site seeded by scripts/start-multisite-env.sh.
 *
 * @type {string}
 */
export const SITE_SLUG = 'team';

/**
 * The users seeded by scripts/start-multisite-env.sh, members of both sites.
 *
 * Logins carry no underscores because multisite rejects anything but lowercase
 * letters and numbers.
 */
export const NETWORK_USERS = {
	a: { login: 'presencenetusera', displayName: 'Network UserA' },
	b: { login: 'presencenetuserb', displayName: 'Network UserB' },
};

/**
 * Returns the admin URL of a site on the fixture network.
 *
 * @param {string} [slug] Sub-site slug, omitted for the main site.
 * @returns {string} Site URL, with a trailing slash.
 */
export function siteUrl( slug = '' ) {
	return slug ? `${ BASE_URL }/${ slug }/` : `${ BASE_URL }/`;
}

/**
 * Returns the label the Network Users "Online" column prints for a site.
 *
 * The column names sites as domain plus path, which on a subdirectory network
 * is the host and port the instance runs on.
 *
 * @param {string} [slug] Sub-site slug, omitted for the main site.
 * @returns {string} Site label, e.g. `localhost:8890/team/`.
 */
export function siteLabel( slug = '' ) {
	return new URL( siteUrl( slug ) ).host + ( slug ? `/${ slug }/` : '/' );
}

/**
 * Runs a WP-CLI command against the multisite instance.
 *
 * @param {string} command WP-CLI command, without the leading `wp`.
 * @param {Object} [options]
 * @param {string} [options.url] Site to run against, for a per-site command.
 * @returns {string} The command's last line of output, trimmed.
 */
export function wpCli( command, { url } = {} ) {
	const scope = url ? ` --url=${ url }` : '';

	const raw = execSync( `npx wp-env run cli wp ${ command }${ scope }`, {
		cwd: MULTISITE_ENV_DIR,
		encoding: 'utf8',
		stdio: 'pipe',
		timeout: 60_000,
	} );

	return raw.trim().split( '\n' ).pop().trim();
}

/**
 * Marks a seeded user as online on one site of the fixture network.
 *
 * @param {Object} options
 * @param {string} options.login    Seeded user's login.
 * @param {string} [options.slug]   Sub-site slug, omitted for the main site.
 * @param {string} [options.client] Client ID, for seeding one user twice.
 */
export function setNetworkPresence( { login, slug = '', client = 'e2e' } ) {
	wpCli(
		`eval 'wp_set_presence( wp_presence_admin_room(), "${ client }", array( "screen" => "dashboard" ), get_user_by( "login", "${ login }" )->ID );'`,
		{ url: siteUrl( slug ) }
	);
}

/**
 * Empties every site's presence table and rewrites the network summary.
 *
 * The summary table is a materialized push rather than a view, so deleting the
 * rows a site pushed from is not enough on its own: without the push the site
 * stays in the summary until its row ages out, and the screen under test keeps
 * drawing people who are no longer there.
 */
export function clearNetworkPresence() {
	wpCli(
		`eval '
			global $wpdb;
			foreach ( get_sites( array( "fields" => "ids" ) ) as $blog_id ) {
				switch_to_blog( $blog_id );
				$wpdb->query( "DELETE FROM {$wpdb->presence}" );
				wp_presence_push_network_summary();
				restore_current_blog();
			}
		'`
	);
}

/**
 * Forces a Heartbeat tick and resolves once the response has been handled.
 *
 * Every admin screen enqueues the ping, network admin included, so a tick is
 * available on all three screens under test.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<void>}
 */
export async function forceHeartbeatTick( page ) {
	await page.waitForFunction(
		() => typeof wp !== 'undefined' && wp.heartbeat && wp.heartbeat.connectNow
	);

	await page.evaluate(
		() =>
			new Promise( ( resolve ) => {
				jQuery( document ).one( 'heartbeat-tick', () => resolve() );
				wp.heartbeat.connectNow();
			} )
	);
}

/**
 * Returns the ID of a seeded user.
 *
 * @param {string} login Seeded user's login.
 * @returns {number} User ID.
 */
export function networkUserId( login ) {
	return parseInt( wpCli( `user get ${ login } --field=ID` ), 10 );
}

/**
 * Returns the blog ID of a site on the fixture network.
 *
 * @param {string} slug Sub-site slug.
 * @returns {number} Blog ID.
 */
export function networkSiteId( slug ) {
	return parseInt(
		wpCli( `eval 'echo get_id_from_blogname( "${ slug }" );'` ),
		10
	);
}
