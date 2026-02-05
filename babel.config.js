/**
 * Babel config for JSX → JS with WordPress wp.element pragma.
 * Used by scripts/compile-jsx.js for all registered folders.
 */
module.exports = {
	presets: [
		'@babel/preset-env',
		[
			'@babel/preset-react',
			{
				pragma: 'wp.element.createElement',
				pragmaFrag: 'wp.element.Fragment',
			},
		],
	],
};
