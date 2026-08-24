import { __, sprintf } from '@wordpress/i18n';

/**
 * How long ago something happened, in words.
 *
 * Deliberately coarse. The exact minute is never the question — "is this reading from today or
 * from last week" is.
 *
 * @param {number} timestamp Unix timestamp in seconds.
 * @return {string} Phrase like "12 minutes ago", or '' if there is no timestamp.
 */
export function ago( timestamp ) {
	if ( ! timestamp ) {
		return '';
	}

	const seconds = Math.max( 0, Math.floor( Date.now() / 1000 ) - timestamp );

	if ( seconds < 90 ) {
		return __( 'just now', 'nfd-site-migrator' );
	}

	if ( seconds < 5400 ) {
		return sprintf(
			/* translators: %d: number of minutes. */
			__( '%d minutes ago', 'nfd-site-migrator' ),
			Math.round( seconds / 60 )
		);
	}

	if ( seconds < 172800 ) {
		return sprintf(
			/* translators: %d: number of hours. */
			__( '%d hours ago', 'nfd-site-migrator' ),
			Math.round( seconds / 3600 )
		);
	}

	return sprintf(
		/* translators: %d: number of days. */
		__( '%d days ago', 'nfd-site-migrator' ),
		Math.round( seconds / 86400 )
	);
}
