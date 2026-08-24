import { __ } from '@wordpress/i18n';

/**
 * Something is happening that has not finished.
 *
 * This plugin does slow things on purpose — hashing a package, walking a filesystem, reading
 * another server — and several of them run before a screen has anything to show. A screen that
 * says nothing meanwhile is indistinguishable from one that has broken, which during a
 * migration is the worst thing an interface can be ambiguous about.
 *
 * @param {Object} props
 * @param {string} props.children What is being waited for.
 * @return {Element} The waiting line.
 */
export const Loading = ( { children } ) => (
	<p className="nfd-sm-loading" role="status" aria-live="polite">
		<span className="nfd-sm-spinner" aria-hidden="true" />
		<span>{ children || __( 'Working…', 'nfd-site-migrator' ) }</span>
	</p>
);
