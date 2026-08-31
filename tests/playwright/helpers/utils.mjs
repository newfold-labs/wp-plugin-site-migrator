/**
 * General test utilities.
 */

/**
 * The admin page this plugin lives on.
 *
 * @type {string}
 */
const PLUGIN_PAGE = 'admin.php?page=nfd-site-migrator';

/**
 * Print a banner, so a long provisioning step does not look like a hang.
 *
 * @param {string} message What to say.
 * @param {string} colour  One of grey, green, red.
 */
function log( message, colour = 'grey' ) {
	const codes = { grey: 90, green: 32, red: 31 };
	const code = codes[ colour ] || codes.grey;

	// eslint-disable-next-line no-console
	console.log( `[${ code }m${ message }[0m` );
}

export default { PLUGIN_PAGE, log };
