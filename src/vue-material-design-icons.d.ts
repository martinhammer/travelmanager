/**
 * Types for the icon set.
 *
 * `vue-material-design-icons` does ship declarations (`Airplane.d.vue.ts` beside
 * each component), but its package.json `exports` map does not list them, so
 * TypeScript refuses to resolve them and every icon import becomes an implicit
 * `any` under `noImplicitAny`. Declaring the shape here is the usual workaround
 * and costs nothing: every icon in the set takes the same three props.
 *
 * Drop this file if the package ever fixes its exports map.
 */
declare module 'vue-material-design-icons/*.vue' {
	import type { DefineComponent } from 'vue'

	const component: DefineComponent<{
		/** Accessible name; the icon is aria-hidden without one. */
		title?: string
		/** Defaults to currentColor, so CSS `color` drives it. */
		fillColor?: string
		/** Edge length in px; defaults to 24. */
		size?: number
	}>

	export default component
}
