import { __ } from '@wordpress/i18n';
import { Steps } from './Steps';

/**
 * Frame shared by every screen.
 *
 * One panel, three bands: the safety strip, the stepper, and the body. The safety line is the
 * point of the first one. During a migration the only question a user really has is whether
 * anything has been broken yet, so the interface answers it permanently instead of making them
 * infer it from a progress bar.
 *
 * @param {Object}  props
 * @param {string}  props.eyebrow     Small label above the heading.
 * @param {string}  props.title       Screen heading.
 * @param {Element} props.badge       Verdict pill shown beside the heading.
 * @param {string}  props.intro       Sentence under the heading.
 * @param {string}  props.safety      'safe' or 'committed'.
 * @param {string}  props.safetyText  Override for the safety line.
 * @param {boolean} props.working     Whether work is in flight, which the strip's dot shows.
 * @param {Array}   props.steps       Journey definition, when this screen is part of one.
 * @param {string}  props.step        Id of this screen's step.
 * @param {boolean} props.stepsLocked Whether the journey has passed its point of no return.
 * @param {Element} props.children    Screen body.
 * @return {Element} The framed screen.
 */
export const Layout = ( {
	eyebrow,
	title,
	badge = null,
	intro,
	safety = 'safe',
	safetyText = '',
	working = false,
	steps = null,
	step = '',
	stepsLocked = false,
	children,
} ) => (
	<div className="nfd-sm-shell">
		<div
			className={ [
				'nfd-sm-safety',
				`nfd-sm-safety--${ safety }`,
				working ? 'nfd-sm-safety--working' : '',
			]
				.filter( Boolean )
				.join( ' ' ) }
		>
			<strong>
				{ safetyText ||
					( 'safe' === safety
						? __(
								'This site stays online and unchanged',
								'nfd-site-migrator'
						  )
						: __(
								'This site has been changed',
								'nfd-site-migrator'
						  ) ) }
			</strong>
			{ 'safe' === safety && (
				<span>
					{ __(
						'Exporting only reads. Nothing here is modified.',
						'nfd-site-migrator'
					) }
				</span>
			) }
		</div>

		{ steps && (
			<Steps steps={ steps } current={ step } locked={ stepsLocked } />
		) }

		<div className="nfd-sm-body">
			<div className="nfd-sm-head">
				{ eyebrow && <p className="nfd-sm-eyebrow">{ eyebrow }</p> }
				{ badge ? (
					<div className="nfd-sm-headline">
						<h1>{ title }</h1>
						{ badge }
					</div>
				) : (
					<div className="nfd-sm-headline">
						<h1>{ title }</h1>
					</div>
				) }
				{ intro && <p className="nfd-sm-intro">{ intro }</p> }
			</div>

			{ children }
		</div>
	</div>
);
