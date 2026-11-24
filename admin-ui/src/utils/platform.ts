import { resolveServerOrigin } from '../../packages/pc-core/src/desktop.mjs'

export const isDesktop = import.meta.env.MODE === 'desktop'
export const serverOrigin = resolveServerOrigin(
  isDesktop,
  import.meta.env.VITE_DESKTOP_SERVER_ORIGIN,
  window.location.origin
)

/** Root-relative backend assets and public URLs; bundled frontend assets stay local. */
export const serverUrl = (path: string) => new URL(path, `${serverOrigin}/`).href

export const openExternalUrl = async (path: string) => {
  const url = serverUrl(path)
  if (!/^https?:$/.test(new URL(url).protocol)) throw new Error('仅支持打开 HTTP(S) 链接')
  if (isDesktop) {
    const { openUrl } = await import('@tauri-apps/plugin-opener')
    await openUrl(url)
  } else {
    window.open(url, '_blank', 'noopener,noreferrer')
  }
}

export const setupDesktopLinks = () => {
  if (!isDesktop) return
  document.addEventListener('click', (event) => {
    const anchor = event.target instanceof Element ? event.target.closest('a[target="_blank"]') : null
    if (!(anchor instanceof HTMLAnchorElement) || !/^https?:$/.test(anchor.protocol)) return
    event.preventDefault()
    void openExternalUrl(anchor.getAttribute('href') || anchor.href).catch(console.error)
  })
}
