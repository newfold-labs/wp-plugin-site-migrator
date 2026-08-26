import { __, _n, sprintf } from '@wordpress/i18n';

const LABEL = {
	block: __( 'Blocking', 'nfd-site-migrator' ),
	warn: __( 'Heads up', 'nfd-site-migrator' ),
	pass: __( 'Fine', 'nfd-site-migrator' ),
};

/**
 * One line of a preflight or compatibility report.
 *
 * @param {Object} props
 * @param {Object} props.check A check from the report.
 * @return {Element} The rendered row.
 */
export const Gate = ( { check } ) => (
	<li className={ `nfd-sm-gate nfd-sm-gate--${ check.status }` }>
		<span
			className={ `nfd-sm-pill nfd-sm-pill--${ check.status }` }
			aria-label={ LABEL[ check.status ] }
		>
			{ 'pass' === check.status ? '✓' : LABEL[ check.status ] }
		</span>
		<div>
			<p className="nfd-sm-gate-label">{ check.label }</p>
			{ check.context?.fix && (
				<p className="nfd-sm-gate-fix">{ check.context.fix }</p>
			) }
			{ check.context?.detail && (
				<p className="nfd-sm-gate-fix">{ check.context.detail }</p>
			) }
			{ check.context?.reason && (
				<p className="nfd-sm-gate-fix">{ check.context.reason }</p>
			) }
		</div>
	</li>
);

/**
 * A report's blocking items and warnings, most important first.
 *
 * @param {Object}  props
 * @param {Object}  props.report   A report from the API.
 * @param {boolean} props.showPass Whether to list the checks that passed.
 * @param {boolean} props.summary  Whether to head the list with how it came out.
 * @return {Element} The rendered list.
 */
export const Gates = ( { report, showPass = false, summary = false } ) => {
	const attention = [
		...( report?.blocking || [] ),
		...( report?.warnings || [] ),
	];
	const passed = report?.passed || [];
	const rows = [ ...attention, ...( showPass ? passed : [] ) ];

	if ( ! rows.length ) {
		return null;
	}

	const total = attention.length + passed.length;

	return (
		<ul className="nfd-sm-gates">
			{ summary && (
				<li className="nfd-sm-gates-head">
					<span className="nfd-sm-gates-count">
						{ sprintf(
							/* translators: %d: number of checks. */
							_n(
								'%d check',
								'%d checks',
								total,
								'nfd-site-migrator'
							),
							total
						) }
					</span>
					<span className="nfd-sm-gates-tally">
						{ attention.length > 0 &&
							sprintf(
								/* translators: %d: number of checks needing attention. */
								__(
									'%d need attention · ',
									'nfd-site-migrator'
								),
								attention.length
							) }
						{ sprintf(
							/* translators: %d: number of checks that passed. */
							__( '%d fine', 'nfd-site-migrator' ),
							passed.length
						) }
					</span>
				</li>
			) }
			{ rows.map( ( check ) => (
				<Gate key={ check.id } check={ check } />
			) ) }
		</ul>
	);
};
