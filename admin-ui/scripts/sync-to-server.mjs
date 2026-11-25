#!/usr/bin/env node
import { createHash } from 'node:crypto'
import { cpSync, existsSync, mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const adminUiDir = resolve(__dirname, '..')
const distDir = resolve(adminUiDir, 'dist')

if (!existsSync(resolve(distDir, 'index.html'))) {
  console.error('[build:sync] 缺少 dist/index.html，请先执行 pnpm build')
  process.exit(1)
}

// 自动探测仓库模式 (../server/public/admin) 与发行包模式 (../../public/admin)
const repoPublic = resolve(adminUiDir, '../server/public')
const releasePublic = resolve(adminUiDir, '../../public')

let targetDir = ''
let targetPublicRoot = ''
if (existsSync(repoPublic)) {
  targetPublicRoot = repoPublic
  targetDir = resolve(repoPublic, 'admin')
} else if (existsSync(releasePublic)) {
  targetPublicRoot = releasePublic
  targetDir = resolve(releasePublic, 'admin')
} else {
  console.error('[build:sync] 未找到后端 public 目录（期望 ../server/public 或 ../../public）')
  process.exit(1)
}

mkdirSync(targetDir, { recursive: true })
for (const entry of readdirSync(targetDir)) {
  if (entry === '.gitkeep') continue
  rmSync(resolve(targetDir, entry), { recursive: true, force: true })
}

cpSync(distDir, targetDir, { recursive: true })
for (const iconName of ['favicon.ico', 'favicon.svg']) {
  const srcIcon = resolve(adminUiDir, 'public', iconName)
  if (existsSync(srcIcon)) {
    cpSync(srcIcon, resolve(targetPublicRoot, iconName))
  }
}

// 生成前端构建指纹清单，用于 OTA 升级区分“官方纯净前端”与“二开自定义编译前端”
let frameworkVersion = '1.6.8'
try {
  const pkg = JSON.parse(readFileSync(resolve(adminUiDir, 'package.json'), 'utf8'))
  if (pkg?.version) {
    frameworkVersion = String(pkg.version).replace(/^v/i, '')
  }
} catch {
  // 忽略读取失败，使用默认版本号
}

const targetIndexPath = resolve(targetDir, 'index.html')
const indexHtmlSha256 = createHash('sha256').update(readFileSync(targetIndexPath)).digest('hex')
const isOfficialBuild = process.env.HWADMIN_OFFICIAL_BUILD === '1'
const uiManifest = {
  official: isOfficialBuild,
  version: frameworkVersion,
  index_html_sha256: indexHtmlSha256,
  built_at: new Date().toISOString(),
}
writeFileSync(resolve(targetDir, 'hwadmin-official-ui.json'), `${JSON.stringify(uiManifest, null, 2)}\n`, 'utf8')

console.log(
  `[build:sync] 已将 admin-ui/dist 同步至 ${targetDir}（含根站点 favicon 与指纹 hwadmin-official-ui.json，official=${isOfficialBuild}）`
)
