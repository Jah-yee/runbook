/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * In-place refresh of the run execution page: a reload after a mutation must
 * keep the page rendered, and a step card must only reset its local draft when
 * that step actually changed on the server.
 */

import assert from 'node:assert/strict'
import test from 'node:test'
import { isInPlaceRefresh, isRunGone, stepStateKey } from '../../src/utils/runRefresh.ts'

function step(overrides = {}) {
	return {
		id: 9,
		title: 'Deploy',
		status: 'PENDING',
		response: null,
		assigneeType: 'USER',
		assigneeId: 'alice',
		dueAt: null,
		...overrides,
	}
}

test('a refresh of the loaded run is in place', () => {
	assert.equal(isInPlaceRefresh({ requested: true, hasDetail: true, loadedRunId: 5, runId: 5 }), true)
})

test('the first load and a load for another run show the spinner', () => {
	assert.equal(isInPlaceRefresh({ requested: true, hasDetail: false, loadedRunId: null, runId: 5 }), false)
	assert.equal(isInPlaceRefresh({ requested: true, hasDetail: true, loadedRunId: 4, runId: 5 }), false)
	assert.equal(isInPlaceRefresh({ requested: false, hasDetail: true, loadedRunId: 5, runId: 5 }), false)
})

test('an unchanged step keeps the same state key across reloads', () => {
	// A reload replaces every step object; equal content must not reset drafts.
	assert.equal(stepStateKey(step()), stepStateKey(step()))
})

test('a server-side change produces a new state key', () => {
	const before = stepStateKey(step())
	assert.notEqual(stepStateKey(step({ status: 'COMPLETED' })), before)
	assert.notEqual(stepStateKey(step({ response: 'done' })), before)
	assert.notEqual(stepStateKey(step({ assigneeId: 'bob' })), before)
	assert.notEqual(stepStateKey(step({ dueAt: 1700000000 })), before)
})

test('the state key does not depend on property order', () => {
	const a = { id: 1, status: 'PENDING', response: null }
	const b = { response: null, status: 'PENDING', id: 1 }
	assert.equal(stepStateKey(a), stepStateKey(b))
})

test('only a 404 means the run is gone', () => {
	assert.equal(isRunGone({ response: { status: 404 } }), true)
	assert.equal(isRunGone({ response: { status: 409 } }), false)
	assert.equal(isRunGone({ response: { status: 403 } }), false)
	assert.equal(isRunGone(new Error('network')), false)
	assert.equal(isRunGone(null), false)
})
