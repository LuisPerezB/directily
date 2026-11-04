module.exports = {
  testEnvironment: 'node',
  roots: ['<rootDir>/test'],
  testMatch: ['**/*.test.ts'],
  transform: { '^.+\\.tsx?$': ['ts-jest', { tsconfig: 'tsconfig.json' }] },
  // La síntesis completa de las plantillas con cdk-nag tarda; se reutiliza entre pruebas.
  testTimeout: 180000,
};
