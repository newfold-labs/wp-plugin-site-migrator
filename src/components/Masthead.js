import { __ } from '@wordpress/i18n';

/**
 * The mark.
 *
 * Drawn rather than shipped as an image file, for the same reason the fonts are bundled: a plugin
 * on wp.org should not need a network request to draw its own admin screen, and an inline SVG is
 * also the only version of a logo that inherits `currentColor` and stays crisp at any size.
 *
 * The glyph is a package in motion: the carton the plugin spends its whole vocabulary on -- parts,
 * manifest, package -- with the lines behind it that say it is going somewhere. The first draft was
 * a container with an arrow leaving through its open side, which is the standard sign-out icon; a
 * migration tool is the last place to borrow that particular picture.
 *
 * @param {Object} props
 * @param {string} props.className Class to draw it under.
 * @return {Element} The mark.
 */
export const Mark = ( { className = 'nfd-sm-mark' } ) => (
	<svg
		className={ className }
		viewBox="0 0 32 32"
		xmlns="http://www.w3.org/2000/svg"
		focusable="false"
		aria-hidden="true"
	>
		<rect width="32" height="32" rx="9" fill="currentColor" />
		<g
			fill="none"
			stroke="#ffffff"
			strokeWidth="2.2"
			strokeLinecap="round"
			strokeLinejoin="round"
		>
			<rect x="15.8" y="10.5" width="10.4" height="11" rx="2.9" />
			<path d="M15.8 15.1h10.4" />
			<path d="M7.6 13.2h5M5.4 18.8h7" />
		</g>
	</svg>
);

/**
 * Who this is, above the panel.
 *
 * Every screen's own `<h1>` says what is happening on it -- "Move this site, or bring one here",
 * "Review what is about to be imported" -- which is the right heading for the step and tells you
 * nothing about what you are looking at. wp-admin gives a plugin no title of its own beyond a menu
 * entry in the sidebar, so this is the only place the product names itself.
 *
 * It is deliberately quieter than the heading below it: 15px against 31px, a `<p>` rather than a
 * second heading, and no link. A masthead that competes with the screen title moves the reader's
 * eye to the least useful thing on the page.
 *
 * @return {Element} The masthead.
 */
export const Masthead = () => {
	const version = window.nfdSiteMigrator?.version || '';

	return (
		<div className="nfd-sm-masthead">
			<Mark />

			<div className="nfd-sm-brand">
				<p className="nfd-sm-wordmark">
					{ __( 'Site Migrator', 'nfd-site-migrator' ) }
				</p>
				<p className="nfd-sm-tagline">
					{ __(
						'Move a WordPress site between hosts',
						'nfd-site-migrator'
					) }
				</p>
			</div>

			{ version && (
				<span className="nfd-sm-version">
					{ /* Not translated: a version number is the same in every language. */ }
					{ `v${ version }` }
				</span>
			) }
		</div>
	);
};
