import test from 'node:test'
import assert from 'node:assert/strict'
import { filePage, isBusy, moveReference } from '../resources/js/state.js'

test('1000 selections render only one page and failures block save', () => {
    const files = Array.from({ length: 1000 }, (_, id) => ({ id, meta: { verified: true } }))
    assert.equal(filePage(files, 1).length, 25)
    assert.equal(filePage(files, 40)[24].id, 999)
    assert.equal(isBusy(files), false)
    files[999].meta.verified = false
    assert.equal(isBusy(files), true)
    assert.equal(isBusy([], true), true)
})
test('mixed ordering preserves references and handles boundaries', () => {
    const order = ['media:1', 'upload:a', 'media:2']
    assert.deepEqual(moveReference(order, 'upload:a', -1), ['upload:a', 'media:1', 'media:2'])
    assert.deepEqual(moveReference(order, 'media:1', -1), order)
    assert.deepEqual(order, ['media:1', 'upload:a', 'media:2'])
})
