/** @type {import('jest').Config} */
export default {
  testEnvironment: 'node',
  extensionsToTreatAsEsm: ['.ts'],
  transform: {
    '^.+\\.ts$': ['ts-jest', {useESM: true, tsconfig: 'tsconfig.test.json'}],
  },
  testMatch: ['**/__tests__/**/*.test.ts'],
  collectCoverageFrom: ['src/**/*.ts'],
  coverageReporters: ['text', 'lcov'],
};
