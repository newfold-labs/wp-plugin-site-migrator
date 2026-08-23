import { __, sprintf } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import { useNavigate } from 'react-router-dom';
import { Layout } from '../Layout';
import { useExport } from '../../utils/useExport';

const STAGE = {
	database: __( 'Copying the database', 'nfd-site-migrator' ),
	files: __( 'Packaging your files', 'nfd-site-migrator' ),
	finalize: __( 'Checking the package', 'nfd-site-migrator' ),
	done: __( 'Package ready', 'nfd-site-migrator' ),
};

/**
 * Build the package, one step per request.
 *
 * @return {Element} The screen.
 */
export const Exporting = () => {
	const { running, done, stage, part, files, bytes, error, start, pause } =
		useExport();
	const navigate = useNavigate();

	useEffect( () => {
		start();
	}, [ start ] );

	useEffect( () => {
		if ( done ) {
			navigate( '/download' );
		}
	}, [ done, navigate ] );

	const megabytes = ( bytes / 1048576 ).toFixed( 1 );

	return (
		<Layout
			eyebrow={ __( 'Step 4 · Source', 'nfd-site-migrator' ) }
			title={ __( 'Building the package', 'nfd-site-migrator' ) }
			intro={ __(
				'Your site stays online and unchanged throughout. This tab drives the work, so leave it open if you can — but closing it is safe, and reopening picks up where it stopped.',
				'nfd-site-migrator'
			) }
		>
			{ error && (
				<div className="nfd-sm-note nfd-sm-note--stop">
					<p>{ error }</p>
					<button
						type="button"
						className="nfd-sm-btn"
						onClick={ start }
					>
						{ __( 'Resume', 'nfd-site-migrator' ) }
					</button>
				</div>
			) }

			<div className="nfd-sm-card" id="nfd-sm-export-progress">
				<p className="nfd-sm-stage">
					{ STAGE[ stage ] ||
						__( 'Getting ready', 'nfd-site-migrator' ) }
					{ part && (
						<span className="nfd-sm-muted"> · { part }</span>
					) }
				</p>
				<p className="nfd-sm-counter">
					{ sprintf(
						/* translators: 1: file count, 2: size in MB. */
						__( '%1$d files · %2$s MB', 'nfd-site-migrator' ),
						files,
						megabytes
					) }
				</p>
				<div className="nfd-sm-bar">
					<i className={ running ? 'is-working' : '' } />
				</div>
			</div>

			<div className="nfd-sm-note nfd-sm-note--info">
				{ __(
					'Anything published on this site from now until the import will not be in the package. If the site is busy, put it in maintenance mode before this step.',
					'nfd-site-migrator'
				) }
			</div>

			<div className="nfd-sm-actions">
				{ running ? (
					<button
						type="button"
						className="nfd-sm-btn"
						onClick={ pause }
					>
						{ __( 'Pause', 'nfd-site-migrator' ) }
					</button>
				) : (
					! error && (
						<button
							type="button"
							className="nfd-sm-btn nfd-sm-btn--primary"
							onClick={ start }
						>
							{ __( 'Resume', 'nfd-site-migrator' ) }
						</button>
					)
				) }
			</div>
		</Layout>
	);
};
