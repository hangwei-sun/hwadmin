import DOMPurify from 'dompurify'

/** Treat stored and source-mode editor HTML as untrusted at every rendering boundary. */
export const sanitizeHtml = (html: string) => DOMPurify.sanitize(html || '', { USE_PROFILES: { html: true } })
