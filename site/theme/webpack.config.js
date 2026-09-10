/**
 * The theme's build.
 *
 * Two things come out of it, and where they land is not arbitrary:
 *
 *   assets/src/scss/main.scss  ->  style.css          (the theme root)
 *   assets/src/js/main.js      ->  assets/dist/main.js
 *
 * **The stylesheet is built to `style.css` in the theme root rather than into
 * `assets/dist/`,** because that is the one file WordPress itself reads: it
 * takes the theme's name, version and description from the comment at the top
 * of it, and a theme whose style.css is missing or headerless is not listed at
 * all. `main.scss` therefore opens with that header as a `/*!` comment, which
 * survives minification, and the built file must never be edited by hand.
 *
 * `webpack-remove-empty-scripts` is here for one reason: a CSS-only entry still
 * emits a stub `.js` file, and a theme root littered with `style.js` is a theme
 * root somebody eventually enqueues by accident.
 */

const path = require( 'path' );
const MiniCssExtractPlugin = require( 'mini-css-extract-plugin' );
const CssMinimizerPlugin = require( 'css-minimizer-webpack-plugin' );
const RemoveEmptyScripts = require( 'webpack-remove-empty-scripts' );

module.exports = ( env, argv ) => {
	const isProduction = 'production' === argv.mode;

	return {
		entry: {
			// The key is the output path, relative to `output.path` below.
			'assets/dist/main': path.resolve( __dirname, 'assets/src/js/main.js' ),
			style: path.resolve( __dirname, 'assets/src/scss/main.scss' ),
		},

		output: {
			path: __dirname,
			filename: '[name].js',
			clean: false,
		},

		module: {
			rules: [
				{
					test: /\.scss$/,
					use: [
						MiniCssExtractPlugin.loader,
						{
							loader: 'css-loader',
							// Nothing in this stylesheet points at a font or an
							// image: the mark is inline SVG and the fonts are
							// self-hosted through @font-face with absolute
							// theme URLs, so there is no url() for webpack to
							// rewrite and every one it found would be a mistake.
							options: { url: false, importLoaders: 2 },
						},
						{
							loader: 'postcss-loader',
							options: {
								postcssOptions: { plugins: [ 'autoprefixer' ] },
							},
						},
						{
							loader: 'sass-loader',
							options: {
								api: 'modern',
								sassOptions: { style: isProduction ? 'compressed' : 'expanded' },
							},
						},
					],
				},
			],
		},

		plugins: [
			new RemoveEmptyScripts(),
			new MiniCssExtractPlugin( { filename: '[name].css' } ),
		],

		optimization: {
			minimizer: [ '...', new CssMinimizerPlugin() ],
		},

		devtool: isProduction ? false : 'source-map',

		// A marketing site of three pages has no business shipping a bundle big
		// enough to warn about, so the ceiling is low on purpose: crossing it is
		// a question to answer, not a number to raise.
		performance: {
			maxAssetSize: 120000,
			maxEntrypointSize: 120000,
			hints: isProduction ? 'warning' : false,
		},
	};
};
