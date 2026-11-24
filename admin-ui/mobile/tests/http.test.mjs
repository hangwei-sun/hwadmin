import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createClient } from '../src/http/client.ts'
import { ApiError, unwrapResponse } from '../src/http/protocol.ts'

test('unwrap HwAdmin success and list/total pagination without renaming fields', () => {
  const data = { list: [{ id: 7 }], total: 1 }
  assert.deepEqual(unwrapResponse(200, { code: 1, msg: 'ok', data, time: 1 }), data)
})

test('business failures, forbidden, malformed payloads and HTTP errors reject', () => {
  for (const [status, body, code] of [
    [200, { code: 0, msg: '密码错误' }, 0],
    [200, { code: 403, msg: '无权限' }, 403],
    [403, {}, 403],
    [500, { code: 1, data: 'invalid' }, 0],
    [200, '<html>', 0],
  ]) {
    assert.throws(
      () => unwrapResponse(status, body),
      (error) => error instanceof ApiError && error.code === code
    )
  }
})

function harness(statusCode = 200, data = { code: 1, data: null }) {
  const calls = []
  let expired = 0
  const request = createClient({
    baseURL: () => 'https://example.com/api',
    token: () => 'member-token',
    unauthorized: () => expired++,
    transport: (options) => {
      calls.push(options)
      options.success({ statusCode, data })
    },
  })
  return { request, calls, expired: () => expired }
}

test('authenticated requests add bearer token; public login never carries it', async () => {
  const h = harness()
  await h.request({ path: '/user/info' })
  await h.request({ path: '/user/login', method: 'POST', authenticated: false })
  assert.equal(h.calls[0].url, 'https://example.com/api/user/info')
  assert.equal(h.calls[0].header.Authorization, 'Bearer member-token')
  assert.equal(h.calls[1].header.Authorization, undefined)
})

test('both HTTP and business 401 invalidate sessions; login rejection does not', async () => {
  for (const [status, data] of [
    [401, {}],
    [200, { code: 401, msg: 'expired' }],
  ]) {
    const h = harness(status, data)
    await assert.rejects(h.request({ path: '/user/info' }), { code: 401 })
    assert.equal(h.expired(), 1)
    await assert.rejects(h.request({ path: '/user/login', authenticated: false }))
    assert.equal(h.expired(), 1)
  }
})

test('external and protocol-relative URLs cannot receive member tokens', async () => {
  const h = harness()
  for (const path of ['https://other.example/api', '//other.example/api', '/../adminapi']) {
    await assert.rejects(h.request({ path }), { code: 0 })
  }
  assert.equal(h.calls.length, 0)
})

test('transport failures reject with a network error', async () => {
  const request = createClient({ baseURL: () => '/api', token: () => '', unauthorized() {}, transport: (options) => options.fail() })
  await assert.rejects(request({ path: '/index/ping' }), (error) => error.status === 0)
})
