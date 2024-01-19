/**
 * External dependencies
 */
const path = require( 'path' );
const CssMinimizerPlugin = require( 'css-minimizer-webpack-plugin' );
const RemoveEmptyScriptsPlugin = require( 'webpack-remove-empty-scripts' );
const RtlCssPlugin = require( 'rtlcss-webpack-plugin' );

/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

/** @type {import('webpack').Configuration} */
const sharedConfig = {
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

// Module: Image loading optimization.
const imageLoadingOptimization = {
	...sharedConfig,
	externals: {
		'perf-labs-ilo-detect': 'perfLabsILODetectArgs',
	},
	entry: {
		detect: path.resolve(
			process.cwd(),
			'modules/images/image-loading-optimization/assets/src/js/detection/detect.js'
		),
	},
	output: {
		path: path.resolve(
			process.cwd(),
			'modules/images/image-loading-optimization/assets/js/detection'
		),
	},
	plugins: [
		...sharedConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
	],
};

module.exports = [ imageLoadingOptimization ];
