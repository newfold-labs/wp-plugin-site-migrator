import { useEffect, useState } from '@wordpress/element';
import { LoadingSpinner } from './common/LoadingSpinner';
import { apiCall } from '../utils/apiCall';
import { SiteMigratorAPIs } from '../utils/api';
import { CompatibilityCheck } from './compatibility/Check';
import { useNavigate } from 'react-router-dom';

// The base component that loads the required step based on current migration state
export const Migration = () => {
	const [ stepResult, setStepResult ] = useState( {
		loading: true,
		compatible: false,
		checked: false,
		failed: false,
		error: '',
	} );

	const navigate = useNavigate();

	useEffect( () => {
		const getCurrentStep = async () => {
			const response = await apiCall( {
				apiCallFunc: SiteMigratorAPIs().migrationCheck.getCurrentStep,
			} );
			if ( response.failed ) {
				setStepResult( {
					loading: false,
					error: response.error,
					failed: true,
				} );
				return;
			}
			setStepResult( {
				loading: false,
				compatible: response.compatible,
				checked: response.checked,
			} );
		};

		getCurrentStep();
	}, [] );

	if ( stepResult.loading ) {
		return <LoadingSpinner />;
	}

	if ( ! stepResult.checked ) {
		return <CompatibilityCheck />;
	}

	if ( ! stepResult.compatible ) {
		navigate( '/incompatible' );
	}

	// The export flow is rebuilt in phase 3; nothing follows the check yet.
	return <CompatibilityCheck />;
};
