import { defineConfig } from 'vitest/config'

// Standalone test config (kept separate from vite.config.ts, which uses the
// Nextcloud app build pipeline). Frontend unit tests live under tests/frontend.
export default defineConfig({
	test: {
		environment: 'happy-dom',
		include: ['tests/frontend/**/*.spec.ts'],
		globals: false,
	},
})
