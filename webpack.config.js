/**
 * Extends the @wordpress/scripts default build to use assets/src as the source
 * and assets/dist as the output, producing admin.js + admin.asset.php.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve( __dirname, 'assets/src/admin/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/dist' ),
	},
};
