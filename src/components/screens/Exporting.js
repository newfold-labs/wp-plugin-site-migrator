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

// The parts are named for what they are on disk. These are the same things said to somebody
// who has never heard of wp-content. Anything unlisted — a second theme root, on a site that
// registers one — falls back to its own name, which is at least true.
const PART = {
	plugins: __( 'Plugins', 'nfd-site-migrator' ),
	themes: __( 'Themes', 'nfd-site-migrator' ),
	'mu-plugins': __( 'Must-use plugins', 'nfd-site-migrator' ),
	uploads: __( 'Uploads', 'nfd-site-migrator' ),
	dropins: __( 'Drop-ins', 'nfd-site-migrator' ),
	'content-other': __( 'Other content', 'nfd-site-migrator' ),
	'root-extras': __( 'Root files', 'nfd-site-migrator' ),
};

const TILE = {
	done: __( 'Done', 'nfd-site-migrator' ),
	running: __( 'Running', 'nfd-site-migrator' ),
	queued: __( 'Queued', 'nfd-site-migrator' ),
};

/**
 * Bytes as the handoff writes them: whole megabytes, or gigabytes once it is worth it.
 *
 * @param {number} bytes Byte count.
 * @return {string} Formatted size.
 */
const size = ( bytes ) => {
	const mb = bytes / 1048576;

	return mb >= 1024
		? `${ ( mb / 1024 ).toFixed( 1 ) } GB`
		: `${ Math.round( mb ) } MB`;
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
		parts,
		written,
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
			navigate( '/send' );
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

	// The database is a stage in its own right and comes before every part, so it leads the
	// row. Everything after it is a part the exporter will walk, in walk order.
	const filesStarted = 'database' !== stage;
	const stages = [
		{
			id: 'database',
			label: __( 'Database', 'nfd-site-migrator' ),
			state: filesStarted ? 'done' : 'running',
		},
		...( parts || [] ).map( ( name, i ) => {
			let state = 'queued';

			if ( filesStarted && i < partIndex ) {
				state = 'done';
			} else if ( filesStarted && i === partIndex ) {
				state = 'running';
			}

			return { id: name, label: PART[ name ] || name, state };
		} ),
	];

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
			working={ running }
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
				<div className="nfd-sm-progress-head">
					<p className="nfd-sm-stage">
						{ label() }
						{ part && PART[ part ] ? ` · ${ PART[ part ] }` : '' }
					</p>
					<p className="nfd-sm-counter" id="nfd-sm-export-eta">
						{ plannedBytes > 0
							? `${ size( bytes ) } / ${ size( plannedBytes ) }`
							: sprintf(
									/* translators: 1: file count, 2: size in MB. */
									__(
										'%1$d files · %2$s MB',
										'nfd-site-migrator'
									),
									files,
									megabytes
							  ) }
						{ remaining() ? ` · ${ remaining() }` : '' }
					</p>
				</div>

				{ plannedBytes > 0 ? (
					<div className="nfd-sm-progress">
						<div
							className="nfd-sm-progress-bar"
							style={ { width: `${ percent }%` } }
						/>
					</div>
				) : (
					<div className="nfd-sm-bar">
						<i />
					</div>
				) }

				{ /* What is done, what is being worked on, what is waiting — from the parts
				     the exporter will actually walk, in the order it walks them. */ }
				{ stages.length > 0 && (
					<div className="nfd-sm-tiles">
						{ stages.map( ( tile ) => (
							<div
								key={ tile.id }
								className={ `nfd-sm-tile nfd-sm-tile--${ tile.state }` }
							>
								<div className="nfd-sm-tile-state">
									{ TILE[ tile.state ] }
								</div>
								<div className="nfd-sm-tile-name">
									{ tile.label }
								</div>
							</div>
						) ) }
					</div>
				) }
			</div>

			{ written?.length > 0 && (
				<div className="nfd-sm-log">
					<div className="nfd-sm-log-head">
						{ __( 'Activity', 'nfd-site-migrator' ) }
					</div>
					<ul className="nfd-sm-log-body">
						{ written.map( ( volume ) => (
							<li key={ volume.file }>
								{ volume.file.split( '/' ).pop() }
								{ ' — ' }
								{ size( volume.bytes ) }
							</li>
						) ) }
					</ul>
				</div>
			) }

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
