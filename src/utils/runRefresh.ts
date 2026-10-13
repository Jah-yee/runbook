/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure helpers for refreshing the run execution page in place. Reloading after
 * a mutation must not replace the page with a spinner: that unmounts every
 * step card, loses unsaved input in other steps and resets scroll and focus.
 */

export interface RefreshRequest {
	/** The caller asked for a refresh rather than a full load. */
	requested: boolean
	/** A run detail is currently rendered. */
	hasDetail: boolean
	/** Identifier of the rendered run, if any. */
	loadedRunId: number | null
	/** Identifier of the run being loaded. */
	runId: number
}

/**
 * Whether a load can keep the current page rendered.
 *
 * Only a requested refresh of the run that is already shown qualifies; the
 * first load and a switch to another run still show the loading state.
 *
 * @param request Current load request.
 */
export function isInPlaceRefresh(request: RefreshRequest): boolean {
	return request.requested && request.hasDetail && request.loadedRunId === request.runId
}

/**
 * Stable serialisation of a value with sorted object keys.
 *
 * @param value Any JSON-compatible value.
 */
function stableStringify(value: unknown): string {
	if (Array.isArray(value)) {
		return `[${value.map(stableStringify).join(',')}]`
	}
	if (value !== null && typeof value === 'object') {
		const entries = Object.keys(value as Record<string, unknown>)
			.sort()
			.map((key) => `${JSON.stringify(key)}:${stableStringify((value as Record<string, unknown>)[key])}`)
		return `{${entries.join(',')}}`
	}

	return JSON.stringify(value) ?? 'null'
}

/**
 * Content key of a step as returned by the server.
 *
 * A reload replaces every step object, so watching the object itself would
 * reset the local draft of every card. Watching this key only reacts when the
 * step really changed.
 *
 * @param step Run step as returned by the API.
 */
export function stepStateKey(step: object): string {
	return stableStringify(step)
}
