import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { useNavigate } from 'react-router-dom';
import { api } from '../utils/api';

/**
 * When a creation time was, in this browser's own words.
 *
 * @param {string} iso ISO 8601 time.
 * @return {string} The time, or '' when there is none to show.
 */
const when = ( iso ) => {
	const date = iso ? new Date( iso ) : null;

	return date && ! isNaN( date.getTime() ) ? date.toLocaleString() : '';
};

/**
 * Which package Deliver is handing over, and the way to throw it away.
 *
 * An unchanged selection reuses a finished package, so *Build the package* could land here on one
 * built hours earlier without a word (T9), and nothing anywhere could discard it short of
 * uninstalling the plugin (T8). Two presses rather than a browser `confirm()`: the package is
 * expensive to rebuild, and a modal dialog is not something this screen is allowed to open.
 *
 * @param {Object} props
 * @param {string} props.builtAt When the package was created.
 * @return {Element} The card.
 */
export const StartOver = ( { builtAt } ) => {
	const navigate = useNavigate();
	const [ armed, setArmed ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const discard = async () => {
		setBusy( true );
		setError( '' );

		const response = await api.exportCancel();

		setBusy( false );

		if ( response.failed ) {
			setError( response.error );
			return;
		}

		navigate( '/contents' );
	};

	const built = when( builtAt );

	return (
		<div className="nfd-sm-card" id="nfd-sm-start-over">
			<p className="nfd-sm-hint">
				{ built
					? sprintf(
							/* translators: %s: date and time the package was built. */
							__(
								'This is the package built on %s. Choosing what goes in again, or changing anything on this site since, means building a new one.',
								'nfd-site-migrator'
							),
							built
					  )
					: __(
							'Changing anything on this site since this package was built means building a new one.',
							'nfd-site-migrator'
					  ) }
			</p>

			{ error && (
				<div className="nfd-sm-note nfd-sm-note--stop">{ error }</div>
			) }

			<div className="nfd-sm-actions">
				{ ! armed ? (
					<button
						type="button"
						className="nfd-sm-btn"
						onClick={ () => setArmed( true ) }
					>
						{ __( 'Start over', 'nfd-site-migrator' ) }
					</button>
				) : (
					<>
						<button
							type="button"
							className="nfd-sm-btn nfd-sm-btn--danger"
							disabled={ busy }
							onClick={ discard }
						>
							{ busy
								? __( 'Discarding…', 'nfd-site-migrator' )
								: __(
										'Discard this package',
										'nfd-site-migrator'
								  ) }
						</button>
						<button
							type="button"
							className="nfd-sm-btn"
							disabled={ busy }
							onClick={ () => setArmed( false ) }
						>
							{ /* Not "Keep it": that is the Done screen's button for keeping an
							     import, the one decision in the plugin that cannot be undone. */ }
							{ __( 'Cancel', 'nfd-site-migrator' ) }
						</button>
						<p className="nfd-sm-hint">
							{ __(
								'The package, and any key or offer made for it, are deleted. The pairing with the destination stays.',
								'nfd-site-migrator'
							) }
						</p>
					</>
				) }
			</div>
		</div>
	);
};
