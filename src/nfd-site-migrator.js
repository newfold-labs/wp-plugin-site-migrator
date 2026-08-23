import domReady from '@wordpress/dom-ready';
import { createRoot, render } from '@wordpress/element';

import './styles/nfd-site-migrator.css';
import App from './app';

const NFD_SM_PAGE_ROOT_ELEMENT = 'nfd-sm-app';

const RenderSiteMigrator = () => {
	const DOM_ELEMENT = document.getElementById(
		NFD_SM_PAGE_ROOT_ELEMENT
	);

	if ( null !== DOM_ELEMENT ) {
		if ( 'undefined' !== typeof createRoot ) {
			// WP 6.2+ only
			createRoot( DOM_ELEMENT ).render( <App /> );
		} else if ( 'undefined' !== typeof render ) {
			render( <App />, DOM_ELEMENT );
		}
	}
};

domReady( RenderSiteMigrator );
