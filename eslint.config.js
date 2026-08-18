/**
 * The frontend scripts are plain browser scripts with no build step, so this
 * checks for real mistakes rather than style -- prettier owns formatting.
 */
export default [
  {
    ignores: [
      'assets/js/frontend/qrcode.min.js',
      'node_modules/**',
      'vendor/**',
      'build/**',
    ],
  },
  {
    files: ['assets/js/**/*.js'],
    languageOptions: {
      ecmaVersion: 2020,
      sourceType: 'script',
      globals: {
        window: 'readonly',
        document: 'readonly',
        navigator: 'readonly',
        fetch: 'readonly',
        URLSearchParams: 'readonly',
        jQuery: 'readonly',
        qrcode: 'readonly',
        BlinkPay: 'readonly',
        BlinkNotifications: 'readonly',
        wc: 'readonly',
        wp: 'readonly',
      },
    },
    rules: {
      'no-undef': 'error',
      'no-unused-vars': ['error', { args: 'none' }],
      eqeqeq: ['error', 'smart'],
      'no-var': 'off',
    },
  },
  {
    files: ['tests/js/**/*.js'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'module',
      globals: {
        globalThis: 'readonly',
        window: 'readonly',
        document: 'readonly',
        navigator: 'readonly',
        Event: 'readonly',
        qrcode: 'writable',
      },
    },
    rules: {
      'no-undef': 'error',
      'no-unused-vars': ['error', { args: 'none' }],
    },
  },
];
