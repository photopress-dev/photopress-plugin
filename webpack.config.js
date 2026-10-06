/**
 * Build config: @wordpress/scripts defaults, adjusted to emit the files the PHP
 * enqueues from dist/:
 *
 *   blocks.build.js           editor script        (framework/class-pp-framework.php)
 *   blocks.editor.build.css   editor styles        (editor.scss files)
 *   blocks.style.build.css    front end + editor   (style.scss files; also the options page)
 *   options.build.js          settings page script (modules/base/base.php)
 *   gallery-layouts.build.js  front-end masonry for core/gallery (modules/gallery/gallery.php)
 *   gallery-slideshow.build.js  front end of the Gallery Slideshow block
 *   press-navigation.build.js   click-anywhere navigation for the lightbox (modules/slideshow/slideshow.php)
 *
 * Each JS file is accompanied by a .asset.php listing its script dependencies,
 * which the PHP reads instead of hardcoding them.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// Every stylesheet has always been compiled with common.scss prepended, which is
// how its variables reach the block styles and how options.scss reaches the
// options page (via blocks.style.build.css).
const commonScss = `@import "${ path.resolve( __dirname, 'src/common.scss' ) }";\n`;

const isSassLoader = ( loader ) => String( loader.loader || loader ).includes( 'sass-loader' );

const rules = defaultConfig.module.rules.map( ( rule ) => {
	if ( ! ( rule.use || [] ).some( isSassLoader ) ) {
		return rule;
	}
	return {
		...rule,
		use: rule.use.map( ( loader ) =>
			isSassLoader( loader )
				? { ...loader, options: { ...loader.options, additionalData: commonScss } }
				: loader
		),
	};
} );

// Not wanted here: CopyWebpackPlugin copies src/**/block.json into the output,
// and RtlCssPlugin writes -rtl.css files nothing enqueues.
const plugins = defaultConfig.plugins.filter(
	( plugin ) => ! [ 'CopyPlugin', 'RtlCssPlugin' ].includes( plugin.constructor.name )
);

const cssGroup = ( name, test ) => ( {
	type: 'css/mini-extract',
	test,
	chunks: 'all',
	enforce: true,
	name,
} );

module.exports = {
	...defaultConfig,
	entry: {
		'blocks.build': './src/blocks.js',
		'options.build': './src/options.js',
		'gallery-layouts.build': './src/frontend/gallery-layouts.js',
		'gallery-slideshow.build': './src/frontend/gallery-slideshow.js',
		'press-navigation.build': './src/frontend/press-navigation.js',
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'dist' ),
	},
	module: { ...defaultConfig.module, rules },
	plugins,
	optimization: {
		...defaultConfig.optimization,
		splitChunks: {
			cacheGroups: {
				style: cssGroup( 'blocks.style.build', /[\\/]style\.scss$/ ),
				editor: cssGroup( 'blocks.editor.build', /[\\/]editor\.scss$/ ),
			},
		},
	},
};
