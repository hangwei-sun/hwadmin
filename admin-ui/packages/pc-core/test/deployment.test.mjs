import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'

const source = readFileSync(new URL('../../../public/deployment-check.js', import.meta.url), 'utf8')
function setup(protocol = 'https:') {
  const context = { window: {}, location: { protocol }, Headers }
  vm.runInNewContext(source, context)
  return context.window.HwDeployment.inspect
}
const task = { id: 'a'.repeat(32), marker: 'random-marker', urls: Object.fromEntries([
  'rootControl', 'rootPhp', 'uploadControl', 'uploadPhp', 'uploadPhtml', 'pluginControl', 'pluginPhp',
].map(key => [key, '/' + key])) }
const html = '<html><div id="app"></div></html>'
const security = {
  'content-type': 'text/html', 'x-content-type-options': 'nosniff', 'x-frame-options': 'SAMEORIGIN',
  'referrer-policy': 'strict-origin-when-cross-origin',
  'content-security-policy': "default-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self';",
}
const response = (status, body = '', headers = {}) => ({ status, body, headers: new Headers(headers) })
function fixture(overrides = {}) {
  return async path => {
    if (Object.hasOwn(overrides, path)) return overrides[path]
    if (path.endsWith('Control')) return response(200, task.marker)
    if (/Php$|Phtml$/.test(path)) return response(404)
    if (path.startsWith('/install/')) return response(200, JSON.stringify({ code: 1, data: { challenge: task.id } }))
    if (path.startsWith('/admin/')) return response(200, html, security)
    if (path.startsWith('/index.php')) return response(403, JSON.stringify({ code: 0, msg: '系统已安装' }))
    throw new Error('Unexpected fixture request: ' + path)
  }
}
test('configured deployment passes all checks including the post-install lock', async () => {
  const results = await setup()(task, true, () => {}, fixture())
  assert.equal(results.length, 9)
  assert.ok(results.every(result => result.ok))
})
test('nonexistent controls never turn PHP 404s into a safety pass', async () => {
  const results = await setup()(task, false, () => {}, fixture({ '/uploadControl': response(404) }))
  for (const name of ['上传目录 PHP 禁执行', '上传目录 PHTML 禁执行']) assert.equal(results.find(item => item.name === name).ok, false)
})
test('PHP execution and PHP source disclosure both fail', async () => {
  const results = await setup()(task, false, () => {}, fixture({ '/rootPhp': response(200, task.marker), '/uploadPhp': response(200, '<?php echo marker;') }))
  assert.equal(results.find(item => item.name === '只允许 index.php 执行').ok, false)
  assert.equal(results.find(item => item.name === '上传目录 PHP 禁执行').ok, false)
})
test('missing rewrite, admin entry, TLS and lock are not reported as passed', async () => {
  const results = await setup('http:')(task, true, () => {}, fixture({
    ['/install/index/deploymentRoute?challenge=' + task.id]: response(404),
    '/admin/': response(404, ''),
    '/index.php?s=/install/index/check': response(200, '{}'),
  }))
  for (const name of ['伪静态接口路由', '后台静态页面', 'HTTPS 访问', '安装程序已锁定']) assert.equal(results.find(item => item.name === name).ok, false)
})
test('redirect and timeout do not pass', async () => {
  const mock = fixture()
  const results = await setup()(task, false, () => {}, async path => {
    if (path === '/rootPhp') throw new Error('redirect')
    return mock(path)
  })
  assert.equal(results.find(item => item.name === '只允许 index.php 执行').ok, false)
})
