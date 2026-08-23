import apiFetch from '@wordpress/api-fetch';

const API_BASE = '/nfd-site-migrator/v1/';
const MIGRATION_CHECK_BASE = API_BASE.concat( 'migration-check' );

export const SiteMigratorAPIs = () => {
	return {
		migrationCheck: {
			checkCompatibility: async () => {
				return await apiFetch( {
					path: MIGRATION_CHECK_BASE.concat( '/compatible' ),
					method: 'GET',
				} );
			},
			runMigrationChecks: async () => {
				return await apiFetch( {
					path: MIGRATION_CHECK_BASE.concat( '/' ),
					method: 'POST',
				} );
			},
			getCurrentStep: async () => {
				return await apiFetch( {
					path: MIGRATION_CHECK_BASE.concat( '/step' ),
					method: 'GET',
				} );
			},
		},
	};
};
