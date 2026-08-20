import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'jsdom',
    include: ['tests/js/**/*.test.js'],
    restoreMocks: true,
    unstubGlobals: true,
    coverage: {
      // Istanbul rather than v8: v8 over-counts implicit branches in
      // transpiled output and is unstable at exactly 100%, so it cannot back a
      // hard gate.
      provider: 'istanbul',
      reporter: ['text', 'lcov', 'cobertura', 'json-summary'],
      reportsDirectory: 'build/coverage-js',
      include: ['assets/js/frontend/pay.js'],
      // Without this a file that is never imported simply vanishes from the
      // report and appears to be fully covered.
      all: true,
      thresholds: {
        perFile: true,
        lines: 100,
        functions: 100,
        branches: 100,
        statements: 100,
      },
    },
  },
});
