import { test } from 'node:test'
import assert from 'node:assert/strict'
import { resolveProjectConfig, isHwAdminSuccess } from '../src/runtime.mjs'

test('defaults preserve existing deployment and namespace', () => {
  assert.equal(resolveProjectConfig().title, 'HwAdmin')
  assert.equal(resolveProjectConfig().storageNamespace, 'hwadmin')
})
test('runtime config can switch projects without rebuilding', () => {
  const config = resolveProjectConfig({ title: '项目 A', apiBase: '/gateway/api/', demoEnabled: false, storageNamespace: 'project-a' })
  assert.equal(config.apiBase, '/gateway/api')
  assert.equal(config.demoEnabled, false)
})
test('reject external endpoints, unsafe paths and malformed config', () => {
  for (const apiBase of ['//evil.test/api', 'https://evil.test/api', '/api?x=1', '/a/../api', '/api//user']) {
    assert.throws(() => resolveProjectConfig({ apiBase }))
  }
  assert.throws(() => resolveProjectConfig({ demoEnabled: 'false' }))
  assert.throws(() => resolveProjectConfig({ tokenHeader: 'Token\r\nX' }))
})

test('HwAdmin success requires a real envelope and successful HTTP status', () => {
  assert.ok(isHwAdminSuccess({ code: 1 }, 200))
  assert.ok(isHwAdminSuccess({ code: '1' }, 200))
  for (const data of [null, '<html>error</html>', {}, { code: 0 }, { code: '401' }]) {
    assert.equal(isHwAdminSuccess(data, 200), false)
  }
  assert.equal(isHwAdminSuccess({ code: 1 }, 500), false)
})
