import { useRoutes } from 'react-router-dom';
import { Migration } from './components/Migration';
import { Incompatible } from './components/compatibility/Incompatible';

export default function Routes() {
	return useRoutes( [
		{
			path: '/',
			element: <Migration />,
		},
		{
			path: '/incompatible',
			element: <Incompatible />,
		},
	] );
}
