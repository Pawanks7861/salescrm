import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    test: {
        include: ['tests/js/**/*.test.js'],
        // Component tests opt in to a DOM with a "@vitest-environment happy-dom" docblock.
        environment: 'node',
    },
});
