import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useNavigate } from 'react-router-dom';
import { Layout } from '../../Layout';
import { Loading } from '../../Loading';
import { api } from '../../../utils/api';
import { usePull } from '../../../utils/usePull';
import { DESTINATION_STEPS } from '../../../steps';

const size = ( bytes ) => {
	if ( bytes >= 1073741824 ) {
		return `${ ( bytes / 1073741824 ).toFixed( 1 ) } GB`;
	}
	if ( bytes >= 1048576 ) {
		return `${ ( bytes / 1048576 ).toFixed( 1 ) } MB`;
	}
	return `${ Math.ceil( bytes / 1024 ) } KB`;
};

/**
 * Seconds left, from the rate this tab has actually seen.
 *
 * @param {Object} state Transfer state.
 * @return {string} A phrase, or empty when there is nothing to go on yet.
 */
const remaining = ( state ) => {
	const elapsed = ( Date.now() - state.startedAt ) / 1000;
	const moved = state.bytesDone - state.baseBytes;

	if ( ! state.startedAt || elapsed < 5 || moved <= 0 ) {
		return '';
	}

	const left = Math.max( 0, state.bytesTotal - state.bytesDone );
	const seconds = Math.round( left / ( moved / elapsed ) );

	if ( seconds < 90 ) {
		return sprintf(
			/* translators: %d: number of seconds. */
			__( 'about %d seconds left', 'nfd-site-migrator' ),
			seconds
		);
	}

	return sprintf(
		/* translators: %d: number of minutes. */
		__( 'about %d minutes left', 'nfd-site-migrator' ),
		Math.round( seconds / 60 )
	);
};

/**
 * Fetch the package straight from the source.
 *
 * The bytes land in the same staging directory a browser upload fills, so everything after this
 * screen — verification, the preview, the import itself — is the path that already worked. What
 * changes is only who does the carrying.
 *
 * Progress is not remembered here or on the server: it is measured from the files on disk each
 * time it is asked for. So closing this tab does not lose the transfer, opening a second one does
 * not double it, and resuming after a dropped connection needs nothing to have been written down.
 *
 * @return {Element} The screen.
 */
export const Pull = () => {
	const navigate = useNavigate();
	const { state, connect, disconnect, run, halt } = usePull();

	const [ url, setUrl ] = useState( '' );
	const [ key, setKey ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ formError, setFormError ] = useState( '' );
	const [ cleared, setCleared ] = useState( 0 );
	const [ verifying, setVerifying ] = useState( false );
	const [ problems, setProblems ] = useState( null );

	// A transfer that finished while this tab was away still has to be checked before it is
	// handed to the import, and the check is the one thing here that cannot be split across
	// steps — so it runs once, when the last byte is in, rather than on every visit.
	useEffect( () => {
		if ( ! state.done || verifying || problems ) {
			return;
		}

		setVerifying( true );

		api.import.uploadVerify().then( ( response ) => {
			setVerifying( false );
			setProblems(
				response.failed ? [ response.error ] : response.problems || []
			);
		} );
	}, [ state.done, verifying, problems ] );

	const submit = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		setFormError( '' );
		setProblems( null );

		const response = await connect( url.trim(), key.trim() );

		setBusy( false );

		if ( response.failed ) {
			setFormError( response.error );
			return;
		}

		setCleared( response.cleared || 0 );
		setKey( '' );
		run();
	};

	const percent = state.bytesTotal
		? Math.min(
				100,
				Math.round( ( state.bytesDone / state.bytesTotal ) * 100 )
		  )
		: 0;

	const eta = remaining( state );
	const settled = state.done && null !== problems;
	const sound = settled && 0 === problems.length;

	return (
		<Layout
			steps={ DESTINATION_STEPS }
			step="choose"
			eyebrow={ __( 'Destination', 'nfd-site-migrator' ) }
			title={ __( 'Fetch it from the source', 'nfd-site-migrator' ) }
			intro={ __(
				'This site downloads the package directly from the site you are moving from. Nothing here changes until you have seen what the package would do and confirmed it.',
				'nfd-site-migrator'
			) }
			working={ state.running }
		>
			{ ! state.hydrated && (
				<div className="nfd-sm-card">
					<Loading>
						{ __(
							'Checking for a transfer already under way…',
							'nfd-site-migrator'
						) }
					</Loading>
				</div>
			) }

			{ formError && (
				<div className="nfd-sm-note nfd-sm-note--stop">
					{ formError }
				</div>
			) }

			{ state.error && (
				<div className="nfd-sm-note nfd-sm-note--stop">
					<p>{ state.error }</p>
					<p>
						{ __(
							'Nothing already fetched is lost. Fix it at the source and press Resume — the transfer picks up from the byte it stopped at.',
							'nfd-site-migrator'
						) }
					</p>
				</div>
			) }

			{ cleared > 0 && (
				<div className="nfd-sm-note nfd-sm-note--warn">
					{ sprintf(
						/* translators: %s: amount of data, e.g. "1.4 GB". */
						__(
							'%s of a different package was already staged here and has been removed. Two half-packages in one directory cannot be told apart later.',
							'nfd-site-migrator'
						),
						size( cleared )
					) }
				</div>
			) }

			{ state.hydrated && ! state.connected && (
				<div className="nfd-sm-card">
					<form className="nfd-sm-form" onSubmit={ submit }>
						<label htmlFor="nfd-sm-source-url">
							{ __(
								'The source site’s address',
								'nfd-site-migrator'
							) }
						</label>
						<input
							id="nfd-sm-source-url"
							className="nfd-sm-input"
							type="url"
							required
							placeholder="https://example.com"
							value={ url }
							onChange={ ( e ) => setUrl( e.target.value ) }
						/>

						<label htmlFor="nfd-sm-transfer-key-input">
							{ __( 'Transfer key', 'nfd-site-migrator' ) }
						</label>
						<input
							id="nfd-sm-transfer-key-input"
							className="nfd-sm-input"
							type="text"
							required
							spellCheck="false"
							autoComplete="off"
							placeholder={ __(
								'48 characters, from the source’s send screen',
								'nfd-site-migrator'
							) }
							value={ key }
							onChange={ ( e ) => setKey( e.target.value ) }
						/>

						<div className="nfd-sm-actions">
							<button
								type="submit"
								className="nfd-sm-btn nfd-sm-btn--primary"
								id="nfd-sm-pull-connect"
								disabled={ busy }
							>
								{ busy
									? __( 'Connecting…', 'nfd-site-migrator' )
									: __(
											'Start the transfer',
											'nfd-site-migrator'
									  ) }
							</button>
							<button
								type="button"
								className="nfd-sm-btn"
								onClick={ () => navigate( '/import' ) }
							>
								{ __(
									'Use a package I already have',
									'nfd-site-migrator'
								) }
							</button>
						</div>
					</form>

					<p className="nfd-sm-hint">
						{ __(
							'Generate the key on the source, under Package ready → Send it to the destination. It is read-only, works for this site alone once used, and can be withdrawn there at any time.',
							'nfd-site-migrator'
						) }
					</p>
				</div>
			) }

			{ state.connected && (
				<div className="nfd-sm-card" id="nfd-sm-pull-progress">
					<div className="nfd-sm-progress-head">
						<p className="nfd-sm-eyebrow">
							{ state.source ||
								__( 'The source', 'nfd-site-migrator' ) }
						</p>
						<p className="nfd-sm-counter">
							{ sprintf(
								/* translators: 1: bytes fetched, 2: total. */
								__( '%1$s of %2$s', 'nfd-site-migrator' ),
								size( state.bytesDone ),
								size( state.bytesTotal )
							) }
						</p>
					</div>

					<div className="nfd-sm-progress">
						<div
							className="nfd-sm-progress-bar"
							style={ { width: `${ percent }%` } }
						/>
					</div>

					<p className="nfd-sm-hint" id="nfd-sm-pull-status">
						{ sprintf(
							/* translators: 1: files fetched, 2: total files. */
							__( '%1$d of %2$d files', 'nfd-site-migrator' ),
							state.filesDone,
							state.filesTotal
						) }
						{ state.current ? ` · ${ state.current }` : '' }
						{ eta && state.running ? ` · ${ eta }` : '' }
					</p>

					{ state.notes.length > 0 && (
						<div className="nfd-sm-log">
							<p className="nfd-sm-log-head">
								{ __( 'Along the way', 'nfd-site-migrator' ) }
							</p>
							<ul className="nfd-sm-log-body">
								{ state.notes.map( ( note, i ) => (
									// eslint-disable-next-line react/no-array-index-key
									<li key={ `${ i }-${ note }` }>{ note }</li>
								) ) }
							</ul>
						</div>
					) }

					{ ! state.done && (
						<div className="nfd-sm-actions">
							{ state.running ? (
								<button
									type="button"
									className="nfd-sm-btn"
									id="nfd-sm-pull-pause"
									onClick={ halt }
								>
									{ __( 'Pause', 'nfd-site-migrator' ) }
								</button>
							) : (
								<button
									type="button"
									className="nfd-sm-btn nfd-sm-btn--primary"
									id="nfd-sm-pull-resume"
									onClick={ run }
								>
									{ __( 'Resume', 'nfd-site-migrator' ) }
								</button>
							) }
							<button
								type="button"
								className="nfd-sm-btn"
								onClick={ disconnect }
							>
								{ __(
									'Stop and forget the source',
									'nfd-site-migrator'
								) }
							</button>
						</div>
					) }

					{ ! state.done && (
						<p className="nfd-sm-hint">
							{ __(
								'You can close this tab. The transfer stops when you do and picks up from the same byte when you come back — nothing is fetched twice.',
								'nfd-site-migrator'
							) }
						</p>
					) }
				</div>
			) }

			{ state.done && verifying && (
				<div className="nfd-sm-card">
					<Loading>
						{ __(
							'Everything is here. Checking every file against the source’s own checksums…',
							'nfd-site-migrator'
						) }
					</Loading>
				</div>
			) }

			{ settled && ! sound && (
				<div className="nfd-sm-note nfd-sm-note--stop">
					<p>
						{ __(
							'The package arrived but does not match its own checksums, so it will not be imported.',
							'nfd-site-migrator'
						) }
					</p>
					<ul>
						{ problems.map( ( p ) => (
							<li key={ p }>{ p }</li>
						) ) }
					</ul>
				</div>
			) }

			{ sound && (
				<div className="nfd-sm-card" id="nfd-sm-pull-done">
					<div className="nfd-sm-note nfd-sm-note--pass">
						{ sprintf(
							/* translators: 1: file count, 2: total size. */
							__(
								'All %1$d files are here — %2$s, every one matching the checksum the source recorded when it wrote them.',
								'nfd-site-migrator'
							),
							state.filesTotal,
							size( state.bytesTotal )
						) }
					</div>
					<div className="nfd-sm-actions">
						<button
							type="button"
							className="nfd-sm-btn nfd-sm-btn--primary"
							id="nfd-sm-pull-review"
							onClick={ () => navigate( '/import/review' ) }
						>
							{ __(
								'See what it would do',
								'nfd-site-migrator'
							) }
						</button>
					</div>
					<p className="nfd-sm-hint">
						{ __(
							'Still nothing written. The next screen shows the URL change, which accounts merge, and what would be replaced — and only then is there a button that changes this site.',
							'nfd-site-migrator'
						) }
					</p>
				</div>
			) }
		</Layout>
	);
};
