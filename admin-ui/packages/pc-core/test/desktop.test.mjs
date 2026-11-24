import test from 'node:test'
import assert from 'node:assert/strict'
import { resolveServerOrigin, isBackendApi } from '../src/desktop.mjs'

test('web keeps same-origin APIs; desktop binds to its configured backend', () => {
  assert.equal(resolveServerOrigin(false, 'https://unused.example', 'https://web.example'), 'https://web.example')
  assert.equal(resolveServerOrigin(true, '', 'https://tauri.localhost'), 'http://127.0.0.1:8000')
  assert.equal(resolveServerOrigin(true, 'https://api.example:8443/', ''), 'https://api.example:8443')
})

test('desktop rejects insecure remote origins, credentials and URL path ambiguity', () => {
  for (const value of ['http://api.example', 'https://user:password@api.example', 'https://api.example/adminapi',
    'https://api.example?target=x', 'https://api.example#x', 'file:///test', '//api.example']) {
    assert.throws(() => resolveServerOrigin(true, value, ''))
  }
})

test('administrator credentials are eligible only for API paths on the configured origin', () => {
  const origin = 'https://backend.example'
  for (const url of ['/adminapi/index/info', '/api/index/ping', `${origin}/adminapi/index/info`]) {
    assert.equal(isBackendApi(url, origin), true)
  }
  for (const url of ['https://attacker.example/adminapi/index/info', '//attacker.example/api', '/adminapi-evil',
    '/uploads/image.png', '/plugins/example/admin.html']) {
    assert.equal(isBackendApi(url, origin), false)
  }
})
