import eslint from '@eslint/js';
import prettier from 'eslint-config-prettier';
import vue from 'eslint-plugin-vue';
import tseslint from 'typescript-eslint';

export default tseslint.config(
    { ignores: ['node_modules/**', 'public/build/**', 'resources/shared/generated/**', 'coverage/**'] },
    eslint.configs.recommended,
    ...tseslint.configs.recommended,
    ...vue.configs['flat/recommended'],
    {
        files: ['**/*.ts'],
        rules: {
            '@typescript-eslint/consistent-type-imports': ['error', { prefer: 'type-imports' }],
            '@typescript-eslint/no-explicit-any': 'error',
            'vue/one-component-per-file': 'off',
            'vue/require-prop-types': 'off',
        },
    },
    {
        files: ['tests/frontend/**/*.test.mjs'],
        languageOptions: {
            globals: { URL: 'readonly' },
        },
    },
    prettier,
);
