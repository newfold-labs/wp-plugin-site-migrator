import { __ } from '@wordpress/i18n';

/**
 * Frame shared by every screen.
 *
 * The safety line is the point. During a migration the only question a user really has is
 * whether anything has been broken yet, so the interface answers it permanently instead of
 * making them infer it from a progress bar.
 *
 * @param {Object}  props
 * @param {string}  props.eyebrow    Small label above the heading.
 * @param {string}  props.title      Screen heading.
 * @param {string}  props.intro      Sentence under the heading.
 * @param {string}  props.safety     'safe' or 'committed'.
 * @param {string}  props.safetyText Override for the safety line.
 * @param {Element} props.children   Screen body.
 * @return {Element} The framed screen.
 */
export const Layout = ( {
	eyebrow,
	title,
	intro,
	safety = 'safe',
	safetyText = '',
	children,
} ) => (
	<div className="nfd-sm-shell">
		<div className={ `nfd-sm-safety nfd-sm-safety--${ safety }` }>
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

		<div className="nfd-sm-head">
			{ eyebrow && <p className="nfd-sm-eyebrow">{ eyebrow }</p> }
			<h1>{ title }</h1>
			{ intro && <p className="nfd-sm-intro">{ intro }</p> }
		</div>

		{ children }
	</div>
);
