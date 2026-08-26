import { __ } from '@wordpress/i18n';
import { useNavigate } from 'react-router-dom';

/**
 * Which of the three states a step is in.
 *
 * @param {number} i      Index of the step.
 * @param {number} active Index of the current step.
 * @return {string} 'done', 'current' or 'todo'.
 */
const stateOf = ( i, active ) => {
	if ( i < active ) {
		return 'done';
	}

	return i === active ? 'current' : 'todo';
};

/**
 * What goes in a step's circle: its number, or a tick once it is behind you.
 *
 * @param {string} state 'done', 'current' or 'todo'.
 * @param {number} i     Index of the step.
 * @return {string} The glyph.
 */
const mark = ( state, i ) => ( 'done' === state ? '✓' : String( i + 1 ) );

/**
 * Where you are, what is behind you, and what is left.
 *
 * Completed steps are links, because everything before the point of no return is a decision
 * rather than a commitment: re-pairing, re-checking compatibility and re-choosing a package are
 * all safe to do again, and a migration is exactly the situation where somebody wants to go back
 * and check what they answered. Past that point the list still shows where you are but stops
 * being navigable — there is no "back" from a replaced database, only rollback.
 *
 * @param {Object}  props
 * @param {Array}   props.steps   Step definitions.
 * @param {string}  props.current Id of the active step.
 * @param {boolean} props.locked  Force the whole list non-navigable.
 * @return {Element} The stepper.
 */
export const Steps = ( { steps = [], current = '', locked = false } ) => {
	const navigate = useNavigate();
	const index = steps.findIndex( ( s ) => s.id === current );
	const active = index < 0 ? 0 : index;

	// One irreversible step anywhere behind us locks the whole thing: the list describes a
	// journey, and part of that journey no longer being undoable makes the earlier parts
	// unreachable too.
	const passedPoint =
		locked || steps.slice( 0, active + 1 ).some( ( s ) => s.locked );

	return (
		<ol
			className="nfd-sm-journey"
			aria-label={ __( 'Progress', 'nfd-site-migrator' ) }
		>
			{ steps.map( ( step, i ) => {
				const state = stateOf( i, active );
				const canGo = 'done' === state && ! passedPoint;

				return (
					<li
						key={ step.id }
						className={ `nfd-sm-journey-step is-${ state }` }
						aria-current={ i === active ? 'step' : undefined }
					>
						{ canGo ? (
							<button
								type="button"
								className="nfd-sm-journey-link"
								onClick={ () => navigate( step.path ) }
							>
								<span className="nfd-sm-journey-num">
									{ mark( state, i ) }
								</span>
								<span>{ step.label }</span>
							</button>
						) : (
							<span className="nfd-sm-journey-link">
								<span className="nfd-sm-journey-num">
									{ mark( state, i ) }
								</span>
								<span>{ step.label }</span>
							</span>
						) }
					</li>
				);
			} ) }
		</ol>
	);
};
