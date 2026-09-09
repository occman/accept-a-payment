const nextJest = require('next/jest');

const createJestConfig = nextJest({dir: './'});

module.exports = createJestConfig({
  testEnvironment: 'node',
  testMatch: ['<rootDir>/__tests__/**/*.test.ts'],
  setupFiles: ['<rootDir>/jest.setup.js'],
  collectCoverageFrom: ['app/api/**/route.ts', 'lib/**/*.ts'],
  coverageReporters: ['text', 'lcov'],
});
