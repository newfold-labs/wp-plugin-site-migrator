/**
 * The two journeys, named once.
 *
 * Both halves of the plugin had their position written into each screen's eyebrow as loose text
 * ("Step 4 · Source"), which is not something a user can navigate and drifts the moment a screen
 * is added. Declaring them here means the stepper, the numbering and the back-links all come
 * from the same list.
 */

/**
 * Sending a site: everything here is re-runnable, so every completed step can be returned to.
 *
 * The last step is *Deliver*, not *Download*, because there are two ways to get the package to
 * the other site and only one of them involves a download. `/send` hands it over directly and
 * `/download` is the fallback for a source the destination cannot reach; both are the same step
 * of the same journey, so they share a place in the stepper rather than competing for one.
 */
export const SOURCE_STEPS = [
	{ id: 'start', label: 'This site', path: '/start' },
	{ id: 'pair', label: 'Destination', path: '/pair' },
	{ id: 'compatibility', label: 'Compatibility', path: '/compatibility' },
	{ id: 'export', label: 'Package', path: '/export' },
	{ id: 'deliver', label: 'Deliver', path: '/send' },
];

/**
 * Receiving a site.
 *
 * It begins at *Connect*, which is `/receive` -- the screen that mints the pairing code. That
 * screen was in neither journey for a long time, which made it a dead end: the first thing a
 * destination does was not on the map the plugin draws of what it is doing, so after handing over
 * a code there was nothing saying what happens next or where to wait for it.
 *
 * `locked` marks the point past which going back is not a thing that exists. Up to the
 * confirmation everything is a decision that can be revisited; after it the site has been
 * replaced, and the way back is rollback — an action with consequences, offered on its own
 * screen, not a link in a breadcrumb.
 */
export const DESTINATION_STEPS = [
	{ id: 'connect', label: 'Connect', path: '/receive' },
	{ id: 'choose', label: 'Package', path: '/import' },
	{ id: 'review', label: 'Review', path: '/import/review' },
	{ id: 'run', label: 'Import', path: '/import/run', locked: true },
	{ id: 'done', label: 'Finish', path: '/import/done', locked: true },
];

/**
 * Where a step id sits in its list.
 *
 * @param {Array}  steps The list.
 * @param {string} id    Step id.
 * @return {number} Index, or 0.
 */
export function indexOf( steps, id ) {
	const found = steps.findIndex( ( s ) => s.id === id );

	return found < 0 ? 0 : found;
}
