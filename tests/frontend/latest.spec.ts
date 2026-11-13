import { describe, expect, it } from 'vitest'
import { latestOnly } from '../../src/latest'

describe('latestOnly', () => {
	it('lets only the most recent of overlapping calls act', () => {
		const turn = latestOnly()
		const first = turn()
		const second = turn()
		// The first answer settling last must not replace the second's.
		expect(second()).toBe(true)
		expect(first()).toBe(false)
	})

	it('keeps a lone call current for as long as no other starts', () => {
		const turn = latestOnly()
		const only = turn()
		expect(only()).toBe(true)
		expect(only()).toBe(true)
	})

	it('keeps separate counters for separate uses', () => {
		const a = latestOnly()
		const b = latestOnly()
		const fromA = a()
		b()
		expect(fromA()).toBe(true)
	})
})
