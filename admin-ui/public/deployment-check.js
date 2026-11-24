/* Shared by the offline installer and the administrator deployment panel. No CDN. */
;(function (global) {
  'use strict'
  async function probe(path) {
    const url = new URL(path, location.origin)
    if (url.origin !== location.origin) throw new Error('检查只允许访问当前站点')
    url.searchParams.set('_hw_check', Date.now().toString())
    const response = await fetch(url, {
      cache: 'no-store', credentials: 'omit', redirect: 'error', signal: AbortSignal.timeout(10000),
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    const body = await response.text()
    return { status: response.status, headers: response.headers, body }
  }

  async function inspect(task, installed, report, fetchProbe = probe) {
    const results = []
    const add = (name, ok, detail) => { results.push({ name, ok, detail }); report(results) }
    const get = async (path) => {
      try { return await fetchProbe(path) } catch { return null }
    }
    const controls = {}
    for (const area of ['root', 'upload', 'plugin']) {
      const response = await get(task.urls[area + 'Control'])
      controls[area] = response?.status === 200 && response.body === task.marker
    }
    add('站点目录与静态文件通路', Object.values(controls).every(Boolean),
      '三个临时静态标记必须原样可读；否则后续脚本检查不能确认通过。')
    for (const [key, name, area] of [
      ['rootPhp', '只允许 index.php 执行', 'root'],
      ['uploadPhp', '上传目录 PHP 禁执行', 'upload'],
      ['uploadPhtml', '上传目录 PHTML 禁执行', 'upload'],
      ['pluginPhp', '插件静态目录 PHP 禁执行', 'plugin'],
    ]) {
      const response = await get(task.urls[key])
      add(name, controls[area] && !!response && [403, 404].includes(response.status),
        !controls[area] ? '待确认：静态标记不可读，请先检查站点目录、WAF 或代理。' :
          !response ? '待确认：请求失败、重定向或超时。' :
            [403, 404].includes(response.status) ? '已确认真实存在的无害探针被服务器拒绝访问。' :
              '未通过：脚本路径没有被拒绝（HTTP ' + response.status + '），请先保存伪静态规则后重试。')
    }
    const route = await get('/install/index/deploymentRoute?challenge=' + task.id)
    let routeOk = false
    try { const data = JSON.parse(route?.body || ''); routeOk = route.status === 200 && data.code === 1 && data.data.challenge === task.id } catch { /* invalid route */ }
    add('伪静态接口路由', routeOk, routeOk ? '无 index.php 的接口地址已到达程序。' : '未通过：将伪静态规则保存到宝塔「伪静态」后重试。')
    const admin = await get('/admin/')
    const adminOk = admin?.status === 200 && /text\/html/i.test(admin.headers.get('content-type') || '') && /id=["']app["']/.test(admin.body)
    add('后台静态页面', !!adminOk, adminOk ? '后台 HTML 可正常访问。' : '待确认：后台构建文件缺失，或请求被代理拦截。')
    add('HTTPS 访问', location.protocol === 'https:', location.protocol === 'https:' ? '当前浏览器通过 HTTPS 访问；证书续期仍需面板管理。' : '未通过：当前为 HTTP。内网测试可继续，公网发布请先在宝塔启用 HTTPS，再用 HTTPS 地址复查。')
    if (installed) {
      const lock = await get('/index.php?s=/install/index/check')
      let locked = false
      try { const data = JSON.parse(lock?.body || ''); locked = lock.status === 403 && data.code === 0 && /已安装/.test(data.msg) } catch { /* not confirmed */ }
      add('安装程序已锁定', locked, locked ? '安装检测接口已拒绝再次安装。' : '待确认：未取得预期的安装锁定响应。')
    }
    return results
  }

  function randomChallenge() {
    if (global.crypto?.getRandomValues) {
      const bytes = new Uint8Array(16)
      global.crypto.getRandomValues(bytes)
      return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('')
    }
    return Array.from({ length: 32 }, () => Math.floor(Math.random() * 16).toString(16)).join('')
  }

  function mount(container, options) {
    const shadow = container.attachShadow({ mode: 'open' })
    shadow.innerHTML = `<style>
      :host{display:block;text-align:left;font:14px/1.6 system-ui,sans-serif;color:#334155}*{box-sizing:border-box}
      section{border:1px solid #dbe2ef;border-radius:10px;padding:18px;margin:16px 0;background:#f8fafc}
      h3{margin:0 0 8px;font-size:16px;color:#0f172a;display:flex;flex-wrap:wrap;align-items:center;gap:8px}
      p{margin:8px 0}ol{padding-left:22px}
      button{padding:8px 14px;margin:6px 8px 6px 0;border:1px solid #cbd5e1;border-radius:6px;background:white;color:#334155;cursor:pointer;font-size:13px;font-weight:500}
      button.primary{background:#3d73f1;color:white;border-color:#3d73f1}button:disabled{opacity:.5;cursor:wait}
      button.accent{background:#0f766e;color:white;border-color:#0f766e}
      textarea{width:100%;min-height:190px;font:12px/1.5 monospace;padding:10px;border:1px solid #cbd5e1;border-radius:6px;resize:vertical;background:white}
      input{max-width:100%;width:320px;padding:7px 9px;border:1px solid #cbd5e1;border-radius:5px;font-size:13px}
      .status{font-weight:600;white-space:pre-wrap}.item{padding:9px 0;border-bottom:1px solid #e2e8f0}.good{color:#047857}.bad{color:#b45309}
      small{display:block;color:#64748b;overflow-wrap:anywhere;margin-top:4px}.path{overflow-wrap:anywhere;font-family:monospace;background:#eef4ff;color:#142c59;padding:6px 10px;border-radius:6px;display:inline-block}
      details{margin:12px 0;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;background:#ffffff}
      summary{cursor:pointer;font-weight:600;color:#142c59;font-size:13px;user-select:none}
      .row{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin:8px 0}
      .field{display:flex;flex-direction:column;gap:4px}
      .field label{font-size:12px;color:#475569;font-weight:600}
      .badge-tip{display:inline-block;padding:2px 10px;border-radius:999px;background:#eef4ff;color:#1d4ed8;font-size:12px;font-weight:600}
      .badge-tip.is-done{background:#ecfdf5;color:#047857}
      .rewrite-banner{margin:10px 0;padding:10px 12px;border-radius:8px;border:1px solid #dbeafe;background:#eff6ff;color:#1e40af;font-size:13px}
      .rewrite-banner.is-done{border-color:#a7f3d0;background:#ecfdf5;color:#065f46;font-weight:500}
    </style><section>
      <h3><span>宝塔伪静态与部署安全检查</span><span class="badge-tip">正在检测伪静态状态…</span></h3>
      <div class="rewrite-banner">只需将完整伪静态规则（已集成 PHP 入口限制）保存到宝塔「伪静态」一处即可，无需分别修改配置文件。</div>
      <p class="path" hidden></p>
      <div class="actions">
        <button data-action="config" class="btn-config">查看 / 获取伪静态规则</button>
        <button class="primary" data-action="check">实际检查</button>
      </div>
      <div class="configs" hidden>
        <p><strong>唯一宝塔「伪静态」规则（无需改动站点配置文件）：</strong></p>
        <ol>
          <li>打开宝塔面板 <strong>“网站 → 点击你的站点 → 左侧菜单『伪静态』”</strong>，清空原有内容后点击下方按钮复制并粘贴保存。</li>
          <li>确认同页面左侧 <strong>“网站目录 → 运行目录”</strong> 已设为上方所示 <code>.../public</code> 并保存，随后点击上方 <strong>“实际检查”</strong>。</li>
        </ol>
        <button data-copy="rewrite" class="primary">复制伪静态规则</button>
        <textarea class="rewrite" aria-label="宝塔伪静态配置" readonly spellcheck="false"></textarea>

        <details>
          <summary>自动配置方式一：填入宝塔面板 API 密钥，自动备份并写入伪静态</summary>
          <small>在宝塔“面板设置 → API 接口”开启接口并将 <code>127.0.0.1</code> 加入白名单后，填入下方参数即可自动匹配站点、同步运行目录、写入伪静态并重载 Nginx（密钥仅当次请求使用，不保存）。</small>
          <div class="row">
            <div class="field">
              <label for="btPanelUrl">本机宝塔面板地址</label>
              <input id="btPanelUrl" class="bt-url" value="http://127.0.0.1:8888" placeholder="http://127.0.0.1:8888" autocomplete="off">
            </div>
            <div class="field">
              <label for="btApiKey">宝塔 API 密钥</label>
              <input id="btApiKey" class="bt-key" type="password" placeholder="粘贴宝塔 API 接口密钥" autocomplete="off">
            </div>
          </div>
          <button data-action="applyBaota" class="accent">确认自动写入并重载 Nginx</button>
        </details>

        <details>
          <summary>自动配置方式二：不方便开 API？复制一条命令在宝塔「终端」一键完成</summary>
          <small>打开宝塔左侧 <strong>“终端”</strong>，复制下方整行命令粘贴回车，将自动定位本站点伪静态、先备份原文件、写入新规则、校验 <code>nginx -t</code>（失败自动回滚）并重载 Nginx。</small>
          <button data-copy="command">复制一键终端命令</button>
          <textarea class="command" style="min-height:92px" aria-label="一键终端自动配置命令" readonly spellcheck="false"></textarea>
        </details>
      </div>
      <p class="status" role="status">尚未运行完整检查。数据库安装完成不代表部署安全配置已通过。</p><div class="results"></div>
      <small>检查会创建仅返回随机标记的临时脚本与静态文件，并在结束后清理。异常关闭留下的文件会在下一次检查时按 5 分钟有效期清理。结果仅覆盖当前浏览器访问路径与所列项目，不替代服务器、数据库和业务验收。</small>
    </section>`
    const find = selector => shadow.querySelector(selector)
    const status = (message, ok) => { find('.status').textContent = message; find('.status').className = 'status ' + (ok ? 'good' : 'bad') }
    const setRewriteState = (configured, detailText) => {
      const badge = find('.badge-tip')
      const banner = find('.rewrite-banner')
      const cfgBtn = find('.btn-config')
      if (configured) {
        badge.textContent = '✓ 已配置伪静态'
        badge.classList.add('is-done')
        banner.textContent = detailText || '✓ 检测到当前站点已配置伪静态（接口路由与规则文件保护已生效），可直接点击“实际检查”完成验证。'
        banner.classList.add('is-done')
        cfgBtn.textContent = '查看 / 重新生成伪静态规则（已配置）'
      } else {
        badge.textContent = '只需配置 1 处伪静态'
        badge.classList.remove('is-done')
        banner.textContent = detailText || '暂未检测到有效伪静态规则：请点击“查看 / 获取伪静态规则”，将完整规则粘贴到宝塔「伪静态」保存后再点“实际检查”。'
        banner.classList.remove('is-done')
        cfgBtn.textContent = '查看 / 获取伪静态规则'
      }
    }
    const detectRewriteStatus = async () => {
      if (typeof fetch !== 'function' || typeof location !== 'object') return
      try {
        const challenge = randomChallenge()
        const [routeRes, htaccessRes] = await Promise.all([
          probe('/install/index/deploymentRoute?challenge=' + challenge).catch(() => null),
          probe('/nginx.htaccess').catch(() => null),
        ])
        let routeOk = false
        try {
          const data = JSON.parse(routeRes?.body || '')
          routeOk = routeRes?.status === 200 && data?.code === 1 && data?.data?.challenge === challenge
        } catch { /* ignore */ }
        const blockedStatic = !!htaccessRes && [403, 404].includes(htaccessRes.status)
        setRewriteState(routeOk && blockedStatic)
      } catch {
        setRewriteState(false)
      }
    }
    void detectRewriteStatus()

    const render = results => {
      find('.results').replaceChildren(...results.map(result => {
        const item = document.createElement('div'); item.className = 'item'
        const title = document.createElement('strong'); title.className = result.ok ? 'good' : 'bad'
        title.textContent = (result.ok ? '✓ ' : '⚠ ') + result.name
        const detail = document.createElement('small'); detail.textContent = result.detail
        item.append(title, detail); return item
      }))
    }
    let busy = false
    shadow.addEventListener('click', async event => {
      const button = event.target.closest('button')
      if (!button || busy) return
      if (button.dataset.copy) {
        const area = find('.' + button.dataset.copy)
        try { await navigator.clipboard.writeText(area.value); status('已复制，请粘贴到宝塔对应位置保存或执行。', true) }
        catch { area.focus(); area.select(); status('当前浏览器不能自动复制，已选中文本，请按 Ctrl+C 或长按复制。', false) }
        return
      }
      if (button.dataset.action === 'applyBaota') {
        const panelUrl = find('.bt-url').value.trim()
        const apiKey = find('.bt-key').value.trim()
        if (!panelUrl || !apiKey) {
          status('请先填写本机宝塔面板地址与 API 密钥。', false)
          return
        }
        if (typeof global.confirm === 'function' && !global.confirm('将连接本机宝塔 API 自动备份原伪静态、写入最新伪静态规则并重载 Nginx，确认继续？')) {
          return
        }
        busy = true
        shadow.querySelectorAll('button').forEach(item => { item.disabled = true })
        try {
          status('正在通过宝塔面板 API 自动备份并写入伪静态……', false)
          const res = await options.request('deploymentApplyBaota', { panel_url: panelUrl, api_key: apiKey })
          find('.bt-key').value = ''
          status('已自动更新站点 ' + (res?.site || '') + ' 的伪静态配置并重载 Nginx，正在执行实际检查……', true)
          const task = await options.request('deploymentPrepare', {})
          let results
          try {
            results = await inspect(task, options.installed(), render)
          } finally {
            await options.request('deploymentCleanup', { id: task.id })
          }
          const rewriteOk = !!results.find(r => r.name === '伪静态接口路由')?.ok && !!results.find(r => r.name === '只允许 index.php 执行')?.ok
          setRewriteState(rewriteOk)
          const ok = results.every(item => item.ok)
          status(ok ? '自动配置完成且所有部署安全检查均已通过！' : '规则已写入，但尚有检查项未通过（如 HTTPS 或运行目录），请核对下方结果。', ok)
        } catch (error) {
          status('自动写入失败：' + error.message, false)
        } finally {
          busy = false
          shadow.querySelectorAll('button').forEach(item => { item.disabled = false })
        }
        return
      }
      busy = true
      shadow.querySelectorAll('button').forEach(item => { item.disabled = true })
      let task
      try {
        if (button.dataset.action === 'config') {
          if (!find('.configs').hidden && find('.rewrite').value) {
            find('.configs').hidden = true
            return
          }
          const config = await options.request('deploymentConfig', {})
          find('.path').textContent = '运行目录：' + config.public_path
          find('.path').hidden = false
          find('.rewrite').value = config.rewrite
          if (config.command) find('.command').value = config.command
          find('.configs').hidden = false
          status('已展开完整伪静态规则（含 PHP 入口防护）：如未配置，复制粘贴至宝塔「伪静态」保存后点击“实际检查”。', true)
        } else {
          status('正在从当前浏览器检查实际生效情况……', false); render([])
          task = await options.request('deploymentPrepare', {})
          const results = await inspect(task, options.installed(), render)
          await options.request('deploymentCleanup', { id: task.id }); task = null
          const rewriteOk = !!results.find(r => r.name === '伪静态接口路由')?.ok && !!results.find(r => r.name === '只允许 index.php 执行')?.ok
          setRewriteState(rewriteOk)
          const ok = results.every(item => item.ok)
          status(ok ? '本轮所列部署安全检查全部通过，临时文件已清理。' : '部署安全尚未全部确认：请处理标记项目，保存配置后重新检查。临时文件已清理。', ok)
        }
      } catch (error) {
        status('检查未完成：' + error.message, false)
      } finally {
        if (task) {
          try { await options.request('deploymentCleanup', { id: task.id }) }
          catch { status('检查未完成且临时文件清理失败。请恢复网络 / 权限后重新检查以清理过期文件。', false) }
        }
        busy = false
        shadow.querySelectorAll('button').forEach(item => { item.disabled = false })
      }
    })
    return { check: () => find('[data-action="check"]').click() }
  }
  global.HwDeployment = { mount, inspect }
})(window)
