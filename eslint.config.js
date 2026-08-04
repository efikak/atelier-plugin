export default [
  {
    files: ['assets/*.js'],
    languageOptions: {ecmaVersion: 2022, sourceType: 'script'},
    rules: {
      'no-unused-vars': ['error', {argsIgnorePattern: '^_'}],
      'no-undef': 'off'
    }
  }
];
