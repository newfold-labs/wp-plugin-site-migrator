import { __ } from '@wordpress/i18n';
import { useEffect, useRef } from '@wordpress/element';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Layout } from '../../Layout';
import { STAGES, useImport } from '../../../utils/useImport';
import { DESTINATION_STEPS } from '../../../steps';

/**
 * The import in progress.
 *
 * The stage list is shown in full, with the swap marked, so the person watching can see both
 * where the run is and where the point of no return sits relative to it. A single bar cannot
 * say that, and "please do not close this window" without saying why is not an explanation.
 *
 * @return {Element} The screen.
 */
export const Running = () => {
	const navigate = useNavigate();
	const [ params ] = useSearchParams();
	const dir = params.get( 'dir' ) || '';

	const { state, running, swapped, error, run } = useImport( dir );
	const started = useRef( false );

	useEffect( () => {
		if ( ! started.current ) {
			started.current = true;
			run();
		}
	}, [ run ] );

	useEffect( () => {
		if ( state?.done ) {
			navigate(
				`/import/done${
					dir ? `?dir=${ encodeURIComponent( dir ) }` : ''
				}`,
				{
					replace: true,
				}
			);
		}
	}, [ state, navigate, dir ] );

	const current = state?.stage || 'precheck';
	const index = STAGES.findIndex( ( s ) => s.id === current );

	const statusOf = ( i ) => {
		if ( i < index ) {
			return 'done';
		}

		return i === index ? 'active' : 'todo';
	};

	return (
		<Layout
			steps={ DESTINATION_STEPS }
			step="run"
			eyebrow={ __( 'Destination', 'nfd-site-migrator' ) }
			title={ __( 'Importing', 'nfd-site-migrator' ) }
			safety={ swapped ? 'committed' : 'safe' }
			safetyText={
				swapped
					? __(
							'This site now holds the imported content',
							'nfd-site-migrator'
					  )
					: __(
							'This site is still serving its own content',
							'nfd-site-migrator'
					  )
			}
			intro={
				swapped
					? __(
							'The switch has happened. What is left is tidying up, and it can be undone from the next screen.',
							'nfd-site-migrator'
					  )
					: __(
							'The new database is being built beside the live one. Nothing visitors see has changed yet.',
							'nfd-site-migrator'
					  )
			}
		>
			{ error && (
				<div className="nfd-sm-note nfd-sm-note--stop">
					<p>{ error }</p>
					<p>
						{ swapped
							? __(
									'The switch had already happened, so this site now holds the imported content. You can undo it from the previous-site screen.',
									'nfd-site-migrator'
							  )
							: __(
									'Nothing on this site has been changed. You can try again.',
									'nfd-site-migrator'
							  ) }
					</p>
					<div className="nfd-sm-actions">
						<button
							type="button"
							className="nfd-sm-btn"
							onClick={ () => run() }
						>
							{ __( 'Try again', 'nfd-site-migrator' ) }
						</button>
						<button
							type="button"
							className="nfd-sm-btn"
							onClick={ () => navigate( '/import' ) }
						>
							{ __( 'Start over', 'nfd-site-migrator' ) }
						</button>
					</div>
				</div>
			) }

			<ol className="nfd-sm-steps" id="nfd-sm-stages">
				{ STAGES.filter( ( s ) => 'done' !== s.id ).map(
					( stage, i ) => {
						const status = statusOf( i );

						return (
							<li
								key={ stage.id }
								className={ `nfd-sm-step nfd-sm-step--${ status }` }
								data-stage={ stage.id }
							>
								<span
									className="nfd-sm-step-mark"
									aria-hidden="true"
								/>
								<span className="nfd-sm-step-label">
									{ stage.label }
									{ 'swap' === stage.id && (
										<span className="nfd-sm-step-note">
											{ __(
												'the point of no return — one instant, and reversible afterwards',
												'nfd-site-migrator'
											) }
										</span>
									) }
								</span>
							</li>
						);
					}
				) }
			</ol>

			{ running && (
				<p className="nfd-sm-hint" id="nfd-sm-running">
					{ __(
						'Keep this tab open. It is doing the work, not just watching it — if you close it, the import pauses and picks up where it stopped.',
						'nfd-site-migrator'
					) }
				</p>
			) }

			{ state && (
				<p className="nfd-sm-muted">
					{ `${ state.files || 0 } files · ${
						state.rows || 0
					} rows` }
				</p>
			) }
		</Layout>
	);
};
