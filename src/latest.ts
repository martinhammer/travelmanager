/**
 * Tags overlapping calls so that only the most recent one acts on its result.
 *
 * Each call to the returned function starts a turn and gets back a check that
 * stays true only until the next turn starts. For anything whose responses can
 * settle in a different order than the requests went out — an older answer must
 * not replace a newer one.
 */
export const latestOnly = (): (() => () => boolean) => {
	let generation = 0
	return () => {
		const mine = ++generation
		return () => mine === generation
	}
}
