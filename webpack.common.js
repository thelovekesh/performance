/**
 * External dependencies
 */
const CssMinimizerPlugin = require( 'css-minimizer-webpack-plugin' );
const RemoveEmptyScriptsPlugin = require( 'webpack-remove-empty-scripts' );
const RtlCssPlugin = require( 'rtlcss-webpack-plugin' );

/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const commonConfig = {
	...defaultConfig,
	plugins: [
		...defaultConfig.plugins
			.map( ( plugin ) => {
				if ( plugin.constructor.name === 'MiniCssExtractPlugin' ) {
					plugin.options.filename = '../css/[name].css';
				}
				return plugin;
			} )
			.filter(
				( plugin ) => plugin.constructor.name !== 'CleanWebpackPlugin'
			),
		new RtlCssPlugin( {
			filename: '../css/[name]-rtl.css',
		} ),
		new RemoveEmptyScriptsPlugin(),
	],
	optimization: {
		...defaultConfig.optimization,
		splitChunks: {
			...defaultConfig.optimization.splitChunks,
			cacheGroups: {
				...defaultConfig.optimization.splitChunks.cacheGroups,
				// Disable `style` cache group from default config.
				style: false,
			},
		},
		minimizer: defaultConfig.optimization.minimizer.concat( [
			new CssMinimizerPlugin(),
		] ),
	},
};

module.exports = commonConfig;
