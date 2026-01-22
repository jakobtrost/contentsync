module.exports = [
	{
		files: [ '**/*.js' ],
		languageOptions: {
			ecmaVersion: 'latest',
			sourceType: 'script',
			globals: {
				window: 'readonly',
				document: 'readonly',
				console: 'readonly',
				jQuery: 'readonly',
				$: 'readonly',
				wp: 'readonly',
				ajaxurl: 'readonly',
			},
		},
		rules: {
			// Spaces inside parentheses
			'space-in-parens': [ 'error', 'always' ],
			// Spaces inside array brackets
			'array-bracket-spacing': [ 'error', 'always' ],
			// Spaces inside computed properties
			'computed-property-spacing': [ 'error', 'always' ],
			// Spaces inside object braces
			'object-curly-spacing': [ 'error', 'always' ],
			// Empty lines between statements
			'padding-line-between-statements': [
				'error',
				{ blankLine: 'always', prev: 'function', next: '*' },
				{ blankLine: 'always', prev: '*', next: 'function' },
				{ blankLine: 'always', prev: 'block-like', next: '*' },
				{ blankLine: 'always', prev: '*', next: 'return' },
			],
			// Use tabs for indentation
			indent: [ 'error', 'tab' ],
			'no-mixed-spaces-and-tabs': 'error',
			// Semicolons required
			semi: [ 'error', 'always' ],
			// Single quotes
			quotes: [ 'error', 'single' ],
		},
	},
];
