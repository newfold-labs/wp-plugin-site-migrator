import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useNavigate } from 'react-router-dom';
import { Layout } from '../../Layout';
import { Loading } from '../../Loading';
import { api, updateNonce } from '../../../utils/api';
import { DESTINATION_STEPS } from '../../../steps';

/**
 * What happened, and the two things still to decide.
 *
 * The site has changed by the time anyone reads this, so the screen's job is to be honest
 * about what changed, tell people how to sign in, and keep the undo within reach until they
 * say they do not need it.
 *
 * @return {Element} The screen.
 */
export const Done = () => {
	const navigate = useNavigate();
	const [ state, setState ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ outcome, setOutcome ] = useState( '' );
	const [ undone, setUndone ] = useState( [] );
	const [ undoneRemoved, setUndoneRemoved ] = useState( 0 );

	const refresh = () =>
		api.import.state().then( ( s ) => {
			if ( s.failed ) {
				setError( s.error );
			} else {
				updateNonce( s.nonce );
				setState( s );
			}
		} );

	useEffect( () => {
		refresh();
	}, [] );

	const undo = async () => {
		setBusy( 'rollback' );
		setError( '' );

		const response = await api.import.rollback();

		setBusy( '' );

		if ( response.failed ) {
			setError( response.error );
			return;
		}

		setUndone( response.notes || [] );
		setUndoneRemoved( ( response.removed || [] ).length );
		setOutcome( 'rolled-back' );
		refresh();
	};

	const keep = async () => {
		setBusy( 'confirm' );
		setError( '' );

		const response = await api.import.confirm();

		setBusy( '' );

		if ( response.failed ) {
			setError( response.error );
			return;
		}

		setOutcome( 'kept' );
		refresh();
	};

	// "3 plugins and 1 theme", built from whichever halves are non-zero, because undoing
	// deletes them and that is worth saying before the decision rather than after it.
	const added = state?.added || {};
	const addedCount = ( added.plugins || 0 ) + ( added.themes || 0 );
	const addedParts = [];

	if ( added.plugins > 0 ) {
		addedParts.push(
			sprintf(
				/* translators: %d: number of plugins. */
				_n(
					'%d plugin',
					'%d plugins',
					added.plugins,
					'nfd-site-migrator'
				),
				added.plugins
			)
		);
	}

	if ( added.themes > 0 ) {
		addedParts.push(
			sprintf(
				/* translators: %d: number of themes. */
				_n(
					'%d theme',
					'%d themes',
					added.themes,
					'nfd-site-migrator'
				),
				added.themes
			)
		);
	}

	// After a refresh the rollback's own response is gone, but the notes it wrote are in the
	// state it saved.
	const undoneNotes = undone.length > 0 ? undone : state?.notes || [];

	// What the server says already happened outranks nothing having happened in this tab. A
	// finished import stays on `stage: done` after it is rolled back or kept, so a refresh
	// lands back here — and without this the screen offers two buttons that can now only fail.
	const settled =
		outcome ||
		( state?.rolled_back && 'rolled-back' ) ||
		( state?.confirmed && 'kept' ) ||
		'';

	const users = state?.users || {};
	const renamed = users.renamed || [];
	const demoted = users.demoted || [];
	const logins = users.login_changes || [];

	if ( 'rolled-back' === settled ) {
		return (
			<Layout
				steps={ DESTINATION_STEPS }
				safetyDetail={ __(
					'The import was undone. This site is back to how it was.',
					'nfd-site-migrator'
				) }
				step="done"
				eyebrow={ __( 'Destination', 'nfd-site-migrator' ) }
				title={ __( 'Put back', 'nfd-site-migrator' ) }
				safety="safe"
				safetyText={ __(
					'This site is serving its own content again',
					'nfd-site-migrator'
				) }
				intro={
					undoneRemoved > 0 || addedCount > 0
						? __(
								'The import has been undone. Your posts, settings and accounts are exactly as they were, and the plugins and themes the package installed have been removed.',
								'nfd-site-migrator'
						  )
						: __(
								'The import has been undone. Your posts, settings and accounts are exactly as they were.',
								'nfd-site-migrator'
						  )
				}
			>
				{ undoneNotes.length > 0 && (
					<details className="nfd-sm-details">
						<summary>
							{ __( 'What happened', 'nfd-site-migrator' ) }
						</summary>
						<ul className="nfd-sm-list">
							{ undoneNotes.map( ( n ) => (
								<li key={ n }>{ n }</li>
							) ) }
						</ul>
					</details>
				) }

				<div className="nfd-sm-actions">
					<button
						type="button"
						className="nfd-sm-btn"
						onClick={ () => navigate( '/import' ) }
					>
						{ __( 'Try a different package', 'nfd-site-migrator' ) }
					</button>
				</div>
			</Layout>
		);
	}

	return (
		<Layout
			steps={ DESTINATION_STEPS }
			step="done"
			eyebrow={ __( 'Destination', 'nfd-site-migrator' ) }
			title={ __( 'Migration complete', 'nfd-site-migrator' ) }
			safety="committed"
			safetyText={ __(
				'This site now holds the imported content',
				'nfd-site-migrator'
			) }
			intro={ __(
				'Have a look around before you decide to keep it. Until you do, the previous site is still on the server and one click away.',
				'nfd-site-migrator'
			) }
		>
			{ error && (
				<div className="nfd-sm-note nfd-sm-note--stop">{ error }</div>
			) }

			{ ! state && ! error && (
				<div className="nfd-sm-card">
					<Loading>
						{ __(
							'Reading what the import did…',
							'nfd-site-migrator'
						) }
					</Loading>
				</div>
			) }

			{ state && (
				<div
					className="nfd-sm-note nfd-sm-note--pass"
					id="nfd-sm-import-summary"
				>
					{ sprintf(
						/* translators: 1: file count, 2: row count. */
						__(
							'%1$d files restored, %2$d database rows imported.',
							'nfd-site-migrator'
						),
						state.files || 0,
						state.rows || 0
					) }
				</div>
			) }

			{ ( logins.length > 0 || renamed.length > 0 ) && (
				<div className="nfd-sm-card">
					<p className="nfd-sm-eyebrow">
						{ __( 'How to sign in now', 'nfd-site-migrator' ) }
					</p>
					<ul className="nfd-sm-list">
						{ logins.map( ( c ) => (
							<li key={ `l${ c.was }` }>
								{ sprintf(
									/* translators: 1: old username, 2: new username. */
									__(
										'%1$s now signs in as %2$s, with the same password.',
										'nfd-site-migrator'
									),
									c.was,
									c.now
								) }
							</li>
						) ) }
						{ renamed.map( ( r ) => (
							<li key={ `r${ r.was }` }>
								{ sprintf(
									/* translators: 1: old username, 2: new username. */
									__(
										'%1$s was renamed to %2$s — a different person on the other site already used that name. Both accounts still work.',
										'nfd-site-migrator'
									),
									r.was,
									r.now
								) }
							</li>
						) ) }
					</ul>
					<p className="nfd-sm-hint">
						{ __(
							'You can always sign in with your email address instead of your username.',
							'nfd-site-migrator'
						) }
					</p>
				</div>
			) }

			{ demoted.length > 0 && (
				<div className="nfd-sm-note nfd-sm-note--warn">
					<p>
						{ __(
							'These accounts held a role the imported site does not define, so they were given the lowest one instead:',
							'nfd-site-migrator'
						) }
					</p>
					<ul>
						{ demoted.map( ( d ) => (
							<li key={ `${ d.login }${ d.was }` }>
								{ `${ d.login }: ${ d.was } → ${ d.now }` }
							</li>
						) ) }
					</ul>
				</div>
			) }

			{ state?.refused?.length > 0 && (
				<div className="nfd-sm-note nfd-sm-note--warn">
					<p>
						{ __(
							'Some paths in the package were refused because they pointed outside this site. They were not written:',
							'nfd-site-migrator'
						) }
					</p>
					<ul>
						{ state.refused.map( ( r ) => (
							<li key={ r } className="nfd-sm-mono">
								{ r }
							</li>
						) ) }
					</ul>
				</div>
			) }

			{ state?.manual?.length > 0 && (
				<div className="nfd-sm-card">
					<p className="nfd-sm-eyebrow">
						{ __( 'Left for you to decide', 'nfd-site-migrator' ) }
					</p>
					{ state.manual.map( ( step ) => (
						<div key={ step.id }>
							<p>{ step.label }</p>
							{ step.block && (
								<pre className="nfd-sm-block">
									{ step.block }
								</pre>
							) }
						</div>
					) ) }
				</div>
			) }

			{ state?.notes?.length > 0 && (
				<details className="nfd-sm-details">
					<summary>
						{ __( 'What else was done', 'nfd-site-migrator' ) }
					</summary>
					<ul className="nfd-sm-list">
						{ state.notes.map( ( n ) => (
							<li key={ n }>{ n }</li>
						) ) }
					</ul>
				</details>
			) }

			{ state &&
				( 'kept' === settled ? (
					<div className="nfd-sm-note nfd-sm-note--pass">
						{ __(
							'Kept. The previous site’s tables have been removed and the migration is finished.',
							'nfd-site-migrator'
						) }
					</div>
				) : (
					<div className="nfd-sm-card nfd-sm-card--commit">
						<p className="nfd-sm-eyebrow">
							{ __( 'One last decision', 'nfd-site-migrator' ) }
						</p>
						<p>
							{ __(
								'Check the site works — the front page, a few posts, your images, and signing in. Then keep it, or put the old one back.',
								'nfd-site-migrator'
							) }
						</p>
						<div className="nfd-sm-actions">
							<a
								className="nfd-sm-btn"
								href={ state?.site_url || '/' }
								target="_blank"
								rel="noreferrer"
							>
								{ __( 'View the site', 'nfd-site-migrator' ) }
							</a>
							<button
								type="button"
								className="nfd-sm-btn nfd-sm-btn--primary"
								id="nfd-sm-keep"
								disabled={ '' !== busy }
								onClick={ keep }
							>
								{ 'confirm' === busy
									? __( 'Finishing…', 'nfd-site-migrator' )
									: __( 'Keep it', 'nfd-site-migrator' ) }
							</button>
							<button
								type="button"
								className="nfd-sm-btn nfd-sm-btn--danger"
								id="nfd-sm-undo"
								disabled={ '' !== busy }
								onClick={ undo }
							>
								{ 'rollback' === busy
									? __(
											'Putting it back…',
											'nfd-site-migrator'
									  )
									: __(
											'Undo the import',
											'nfd-site-migrator'
									  ) }
							</button>
						</div>
						{ addedParts.length > 0 && (
							<p className="nfd-sm-hint">
								{ sprintf(
									/* translators: %s: a list such as "3 plugins and 1 theme". */
									__(
										'Undoing also removes the %s the package installed here. Anything this site already had is left alone.',
										'nfd-site-migrator'
									),
									addedParts.join(
										__( ' and ', 'nfd-site-migrator' )
									)
								) }
							</p>
						) }
						<p className="nfd-sm-hint">
							{ __(
								'Keeping it removes the old tables and frees the space. After that the import cannot be undone.',
								'nfd-site-migrator'
							) }
						</p>
					</div>
				) ) }
		</Layout>
	);
};
