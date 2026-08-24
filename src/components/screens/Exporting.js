import { __, sprintf } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import { useNavigate } from 'react-router-dom';
import { Layout } from '../Layout';
import { useExport } from '../../utils/useExport';
import { SOURCE_STEPS } from '../../steps';

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
	const {
		hydrated,
		running,
		paused,
		done,
		stage,
		part,
		files,
		bytes,
		partIndex,
		partCount,
		plannedFiles,
		plannedBytes,
		startedAt,
		baseBytes,
		error,
		hydrate,
		start,
		pause,
	} = useExport();
	const navigate = useNavigate();

	// Read the checkpoint before asking for more work, so the screen opens on the run it is
	// joining. Reopening a tab picks up where it stopped — but a run somebody deliberately
	// paused stays paused, because a reload is not a change of mind.
	useEffect( () => {
		let live = true;

		hydrate().then( ( snapshot ) => {
			if (
				live &&
				! snapshot.failed &&
				! snapshot.paused &&
				! snapshot.complete
			) {
				start();
			}
		} );

		return () => {
			live = false;
		};
	}, [ hydrate, start ] );

	useEffect( () => {
		if ( done ) {
			navigate( '/download' );
		}
	}, [ done, navigate ] );

	const megabytes = ( bytes / 1048576 ).toFixed( 1 );

	// Until the checkpoint has been read this screen knows nothing, and saying "Getting ready"
	// would be a guess that is wrong for every export that is already half done.
	const label = () => {
		if ( ! hydrated ) {
			return __( 'Picking up where you left off', 'nfd-site-migrator' );
		}
		if ( paused && ! running ) {
			return __( 'Paused', 'nfd-site-migrator' );
		}
		return STAGE[ stage ] || __( 'Getting ready', 'nfd-site-migrator' );
	};

	// Progress is reported against what the walk has actually counted, so it is a real
	// fraction of real work. Parts are walked as they are reached, so the denominator grows;
	// the part counter alongside it is what stops that reading as the bar going backwards.
	const percent =
		plannedBytes > 0
			? Math.min( 100, Math.floor( ( bytes / plannedBytes ) * 100 ) )
			: 0;

	const elapsed = startedAt ? ( Date.now() - startedAt ) / 1000 : 0;
	const rate =
		elapsed > 2 && bytes > baseBytes ? ( bytes - baseBytes ) / elapsed : 0;
	const left =
		rate > 0 && plannedBytes > bytes ? ( plannedBytes - bytes ) / rate : 0;

	const remaining = () => {
		if ( ! left ) {
			return '';
		}
		if ( left < 90 ) {
			return __( 'under a minute left', 'nfd-site-migrator' );
		}
		return sprintf(
			/* translators: %d: minutes. */
			__( 'about %d minutes left', 'nfd-site-migrator' ),
			Math.round( left / 60 )
		);
	};

	return (
		<Layout
			steps={ SOURCE_STEPS }
			step="export"
			eyebrow={ __( 'Source', 'nfd-site-migrator' ) }
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
					{ label() }
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
					{ partCount > 0 && (
						<span className="nfd-sm-muted">
							{ sprintf(
								/* translators: 1: current part, 2: total parts. */
								__(
									' · group %1$d of %2$d',
									'nfd-site-migrator'
								),
								Math.min( partIndex + 1, partCount ),
								partCount
							) }
						</span>
					) }
				</p>

				{ plannedBytes > 0 ? (
					<>
						<div className="nfd-sm-progress">
							<div
								className="nfd-sm-progress-bar"
								style={ { width: `${ percent }%` } }
							/>
						</div>
						<p className="nfd-sm-hint" id="nfd-sm-export-eta">
							{ sprintf(
								/* translators: 1: percent, 2: total files in this group. */
								__(
									'%1$d%% of this group (%2$d files)',
									'nfd-site-migrator'
								),
								percent,
								plannedFiles
							) }
							{ remaining() ? ` · ${ remaining() }` : '' }
						</p>
					</>
				) : (
					<div className="nfd-sm-bar">
						<i className={ running ? 'is-working' : '' } />
					</div>
				) }
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
						id="nfd-sm-pause"
						onClick={ pause }
					>
						{ __( 'Pause', 'nfd-site-migrator' ) }
					</button>
				) : (
					! error &&
					hydrated && (
						<button
							type="button"
							className="nfd-sm-btn nfd-sm-btn--primary"
							id="nfd-sm-resume"
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
