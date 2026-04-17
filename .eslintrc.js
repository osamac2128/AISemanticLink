module.exports = {
	root: true,
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	overrides: [
		{
			files: [ 'admin/js/src/**/*.{js,jsx}' ],
			env: {
				browser: true,
			},
			rules: {
				'@wordpress/no-unused-vars-before-return': 'off',
				camelcase: 'off',
				'jsdoc/check-param-names': 'off',
				'jsdoc/no-undefined-types': 'off',
				'jsdoc/require-param-type': 'off',
				'jsx-a11y/click-events-have-key-events': 'off',
				'jsx-a11y/label-has-associated-control': 'off',
				'jsx-a11y/no-static-element-interactions': 'off',
				'no-alert': 'off',
				'no-nested-ternary': 'off',
				'react/no-unescaped-entities': 'off',
			},
		},
	],
};
