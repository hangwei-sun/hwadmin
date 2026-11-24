import test from 'node:test'
import assert from 'node:assert/strict'
import { JSDOM } from 'jsdom'

const dom = new JSDOM('<!doctype html>')
globalThis.window = dom.window
globalThis.document = dom.window.document
const { sanitizeHtml } = await import('../../../src/utils/sanitizeHtml.ts')

test('rich text strips scripts, event handlers and dangerous URLs while preserving content', () => {
  const result = sanitizeHtml('<p>Hello <strong>world</strong></p><img src=x onerror="alert(1)"><a href="javascript:alert(1)">link</a><svg onload="alert(1)"></svg><script>alert(1)</script>')
  const root = document.createElement('div')
  root.innerHTML = result
  assert.equal(root.querySelector('strong')?.textContent, 'world')
  assert.equal(root.querySelector('script, svg'), null)
  assert.equal(root.querySelector('img')?.hasAttribute('onerror'), false)
  assert.equal(root.querySelector('a')?.hasAttribute('href'), false)
})
