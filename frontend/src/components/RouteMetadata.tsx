import { useEffect } from 'react'
import { useLocation } from 'react-router'
import { useI18n } from '../i18n/useI18n.ts'
import type { MessageKey } from '../i18n/translate.ts'

const ROUTE_METADATA: Record<string, { title: MessageKey; description: MessageKey }> = {
  '/screener': { title: 'meta.screenerTitle', description: 'meta.screenerDescription' },
  '/chart': { title: 'meta.chartTitle', description: 'meta.chartDescription' },
  '/portal': { title: 'meta.portalTitle', description: 'meta.portalDescription' },
  '/admin': { title: 'meta.adminTitle', description: 'meta.adminDescription' },
  '/login': { title: 'meta.loginTitle', description: 'meta.loginDescription' },
  '/register': { title: 'meta.registerTitle', description: 'meta.registerDescription' },
}

/**
 * Keep app-only routes out of search while leaving the public home crawlable.
 * Title and description follow the selected language; the indexing rules and
 * the canonical URL do not depend on it.
 */
export default function RouteMetadata() {
  const { pathname, search } = useLocation()
  const { t } = useI18n()

  useEffect(() => {
    const isHome = pathname === '/' && search === ''
    const tickerMatch = pathname.match(/^\/instruments\/([a-z0-9.-]+)$/i)
    const ticker = tickerMatch ? tickerMatch[1].toUpperCase() : null
    const keys = ROUTE_METADATA[pathname] ?? (pathname.startsWith('/admin/') ? ROUTE_METADATA['/admin'] : undefined)
    const routeMetadata = keys
      ? { title: t(keys.title), description: t(keys.description) }
      : ticker !== null
        ? {
            title: t('meta.instrumentTitle', { ticker }),
            description: t('meta.instrumentDescription', { ticker }),
          }
        : undefined
    document.title = isHome ? t('meta.homeTitle') : routeMetadata?.title ?? t('meta.notFoundTitle')

    let robots = document.querySelector<HTMLMetaElement>('meta[name="robots"]')
    if (!robots) {
      robots = document.createElement('meta')
      robots.name = 'robots'
      document.head.append(robots)
    }
    robots.content = isHome ? 'index, follow' : 'noindex, follow'

    let description = document.querySelector<HTMLMetaElement>('meta[name="description"]')
    if (!description) {
      description = document.createElement('meta')
      description.name = 'description'
      document.head.append(description)
    }
    description.content = isHome
      ? t('meta.homeDescription')
      : routeMetadata?.description ?? t('meta.notFoundDescription')

    let canonical = document.querySelector<HTMLLinkElement>('link[rel="canonical"]')
    if (isHome) {
      if (!canonical) {
        canonical = document.createElement('link')
        canonical.rel = 'canonical'
        document.head.append(canonical)
      }
      canonical.href = 'https://www.chartiko.com/'
    } else {
      canonical?.remove()
    }
  }, [pathname, search, t])

  return null
}
