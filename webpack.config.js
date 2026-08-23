const path = require( 'path' );
const { merge } = require( 'webpack-merge' );
const wpScriptsConfig = require( '@wordpress/scripts/config/webpack.config' );

// Output is deliberately unversioned. The old `build/${version}` layout required the
// plugin header, package.json and readme.txt to agree; when they drifted, PHP looked
// for a directory webpack had never written and the admin page rendered an empty div.
const siteMigratorWebpackConfig = {
	output: {
		path: path.resolve( process.cwd(), 'build' ),
		library: [ 'newfold', 'SiteMigrator', '[name]' ],
		libraryTarget: 'window',
	},
};

module.exports = merge( wpScriptsConfig, siteMigratorWebpackConfig );
