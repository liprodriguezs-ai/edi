import js from '@eslint/js'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'
import { defineConfig, globalIgnores } from 'eslint/config'
import globals from 'globals'
import tseslint from 'typescript-eslint'

export default defineConfig([
  globalIgnores(['dist']),
  {
    files: ['**/*.{ts,tsx}'],
    extends: [
      js.configs.recommended,
      tseslint.configs.recommended,
      reactHooks.configs.flat.recommended,
      reactRefresh.configs.vite,
    ],
    languageOptions: {
      ecmaVersion: 2020,
      globals: globals.browser,
    },
    rules: {
      ...reactHooks.configs.recommended.rules,
      'react-refresh/only-export-components': ['warn', { allowConstantExport: true }],
      '@typescript-eslint/no-unused-vars': ['error', { vars: 'all', args: 'after-used', ignoreRestSiblings: true, varsIgnorePattern: '^_', argsIgnorePattern: '^_' }],
      // Prohibir console.log y debugger en commits
      'no-console': ['error', { allow: ['warn', 'error'] }],
      'no-debugger': 'error',
      'no-var': 'error',
      'prefer-const': 'error',
      "react-hooks/rules-of-hooks": "error",
      "react-hooks/exhaustive-deps": "warn",
      "no-shadow": "error",
      "max-lines": ["warn", { "max": 500, "skipBlankLines": true }],
      "no-multiple-empty-lines": ["error", { "max": 1 }],
      // Forzar comillas simples
      'no-unused-vars': 'off',
      quotes: ['error', 'single', { avoidEscape: true, allowTemplateLiterals: true }],
      'no-restricted-imports': [
        'error',
        {
          patterns: [
            {
              group: ['../**', './**'], // Bloquea rutas relativas
              message:
                "Por favor, usa el alias '@/' para las importaciones en lugar de rutas relativas.",
            },
          ],
        },
      ],
    },
  },
  // emails/ is a self-contained React Email project; relative imports are standard there
  {
    files: ['**/emails/**/*.ts', '**/emails/**/*.tsx'],
    rules: {
      'no-restricted-imports': 'off',
    },
  },
  // vendored shadcn components — max-lines not applicable
  {
    files: ['src/components/ui/**/*.{ts,tsx}'],
    rules: {
      'max-lines': 'off',
    },
  },
  // test files — relative imports are standard in Vitest co-located tests
  {
    files: ['**/*.test.{ts,tsx}', '**/__tests__/**/*.{ts,tsx}'],
    rules: {
      'no-restricted-imports': 'off',
    },
  },
  // .cursor/ skill examples — not production code
  {
    files: ['.cursor/**/*.{ts,tsx}'],
    rules: {
      'no-unused-vars': 'off',
    },
  },
])

