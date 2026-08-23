import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Navigate, useNavigate } from 'react-router-dom';
import { Layout } from '../Layout';
import { Gates } from '../Gate';
import { api } from '../../utils/api';

/**
 * The verdict, with a re-check that does not require leaving the page.
 *
 * Most blocked gates are fixable in a minute — update core, free some disk. Being able to fix
 * and re-check in place is the main practical reason the profile is fetched live rather than
 * pasted.
 *
 * @param {Object}   props
 * @param {Object}   props.result   The comparison result.
 * @param {Function} props.onResult Called with a fresh result after re-checking.
 * @param {Object}   props.request  What was submitted, so it can be repeated.
 * @return {Element} The screen.
 */
export const Compatibility = ( { result, onResult, request } ) => {
	const [ busy, setBusy ] = useState( false );
	const navigate = useNavigate();

	if ( ! result ) {
		return <Navigate to="/pair" replace />;
	}

	const report = result.report;
	const blocked = ! report?.ok;

	const recheck = async () => {
		setBusy( true );
		const fresh = await api.compare( request );
		setBusy( false );

		if ( ! fresh.failed && fresh.ok ) {
			onResult( fresh );
		}
	};

	return (
		<Layout
			eyebrow={ __( 'Step 3 · Source', 'nfd-site-migrator' ) }
			title={ __( 'Compatibility', 'nfd-site-migrator' ) }
			intro={
				'paired' === result.via
					? sprintf(
							/* translators: %s: destination site URL. */
							__(
								'Read live from %s a moment ago. Fix anything blocking and check again — no need to go back and forth.',
								'nfd-site-migrator'
							),
							result.destination?.site_url || ''
					  )
					: __(
							'Read from the profile you pasted. Generate a fresh one on the destination if anything below is out of date.',
							'nfd-site-migrator'
					  )
			}
		>
			<div
				className={ `nfd-sm-note nfd-sm-note--${
					blocked ? 'stop' : 'pass'
				}` }
			>
				{ blocked
					? __(
							'This migration cannot go ahead yet.',
							'nfd-site-migrator'
					  )
					: __( 'Ready to migrate.', 'nfd-site-migrator' ) }
			</div>

			<Gates report={ report } showPass={ true } />

			<div className="nfd-sm-actions">
				<button
					type="button"
					className="nfd-sm-btn"
					disabled={ busy }
					onClick={ recheck }
				>
					{ busy
						? __( 'Checking…', 'nfd-site-migrator' )
						: __( 'Check again', 'nfd-site-migrator' ) }
				</button>

				<button
					type="button"
					className="nfd-sm-btn nfd-sm-btn--primary"
					id="nfd-sm-build-package"
					disabled={ blocked }
					onClick={ () => navigate( '/export' ) }
				>
					{ __( 'Build the package', 'nfd-site-migrator' ) }
				</button>
			</div>
		</Layout>
	);
};
