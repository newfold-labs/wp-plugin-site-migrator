import { __ } from '@wordpress/i18n';

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
		<span className={ `nfd-sm-pill nfd-sm-pill--${ check.status }` }>
			{ LABEL[ check.status ] }
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
 * @return {Element} The rendered list.
 */
export const Gates = ( { report, showPass = false } ) => {
	const rows = [
		...( report?.blocking || [] ),
		...( report?.warnings || [] ),
		...( showPass ? report?.passed || [] : [] ),
	];

	if ( ! rows.length ) {
		return null;
	}

	return (
		<ul className="nfd-sm-gates">
			{ rows.map( ( check ) => (
				<Gate key={ check.id } check={ check } />
			) ) }
		</ul>
	);
};
