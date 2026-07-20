import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        include: ['tests/frontend/**/*.test.ts'],
        coverage: {
            provider: 'v8',
            include: ['resources/shared/**/*.ts'],
            exclude: ['resources/shared/generated/**'],
            reporter: ['text', 'json-summary'],
            thresholds: {
                lines: 85,
                branches: 80,
                functions: 85,
                statements: 85,
            },
        },
    },
});
