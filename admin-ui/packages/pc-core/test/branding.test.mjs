import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

const normalizeAvatar = (value = '') =>
  /(?:^|\/)static\/svg\/avatar\.svg(?:[?#].*)?$/.test(value) ? '' : value

test('legacy avatar paths are discarded without changing custom avatars', () => {
  assert.equal(normalizeAvatar('/admin/static/svg/avatar.svg'), '')
  assert.equal(normalizeAvatar('static/svg/avatar.svg?v=1'), '')
  assert.equal(normalizeAvatar('/uploads/avatar.webp'), '/uploads/avatar.webp')
})

test('empty configured assets never resolve to the deployment root', () => {
  const resolve = (value = '') => {
    const normalized = value.trim()
    if (!normalized) return ''
    if (/^(?:https?:|data:|blob:)/i.test(normalized)) return normalized
    return normalized.startsWith('/') ? normalized : `/${normalized}`
  }
  assert.equal(resolve(''), '')
  assert.equal(resolve('   '), '')
  assert.equal(resolve('uploads/brand/logo.svg'), '/uploads/brand/logo.svg')
})

test('brand defaults resolve under the configured Vite base', () => {
  const resolve = (path, baseUrl, documentUrl) => new URL(`${baseUrl}${path}`, documentUrl).href
  assert.equal(resolve('brand/logo.svg', './', 'http://localhost:15173/admin/'), 'http://localhost:15173/admin/brand/logo.svg')
  assert.equal(resolve('brand/logo.svg', '/admin/', 'http://localhost/'), 'http://localhost/admin/brand/logo.svg')
})

test('service worker does not pin unhashed branding assets', async () => {
  const source = await readFile(new URL('../../../library/build/pwa/index.ts', import.meta.url), 'utf8')
  assert.match(source, /globIgnores:\s*\[[^\]]*'\*\*\/brand\/\*\*'/)
  assert.match(source, /globIgnores:\s*\[[^\]]*'\*\*\/favicon\.svg'/)
})
