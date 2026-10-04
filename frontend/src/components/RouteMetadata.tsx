import { useEffect } from 'react'
import { useLocation } from 'react-router'

const INDEXABLE_TITLE = 'Chartiko | Screener de análisis técnico de acciones'
const INDEXABLE_DESCRIPTION =
  'Filtra acciones del S&P 500 con criterios técnicos, revisa Candidates ordenados y explora sus gráficos e indicadores.'

const ROUTE_METADATA: Record<string, { title: string; description: string }> = {
  '/screener': {
    title: 'Screener de acciones | Chartiko',
    description: 'Aplica filtros técnicos al universo de acciones y consulta Candidates ordenados en Chartiko.',
  },
  '/chart': {
    title: 'Gráficos de acciones | Chartiko',
    description: 'Explora precios, volumen, indicadores y Signals de los instrumentos en Chartiko.',
  },
  '/portal': {
    title: 'Mi Portal | Chartiko',
    description: 'Consulta tus Screeners guardados y tu Watchlist de Chartiko.',
  },
  '/admin': {
    title: 'Administración | Chartiko',
    description: 'Panel privado de administración de Chartiko.',
  },
  '/login': {
    title: 'Iniciar sesión | Chartiko',
    description: 'Inicia sesión en Chartiko.',
  },
  '/register': {
    title: 'Crear cuenta | Chartiko',
    description: 'Regístrate en Chartiko para guardar Screeners y mantener una Watchlist.',
  },
}

/** Keep app-only routes out of search while leaving the public home crawlable. */
export default function RouteMetadata() {
  const { pathname, search } = useLocation()

  useEffect(() => {
    const isHome = pathname === '/' && search === ''
    const tickerMatch = pathname.match(/^\/instruments\/([a-z0-9.-]+)$/i)
    const routeMetadata =
      ROUTE_METADATA[pathname] ??
      (tickerMatch
        ? {
            title: `Gráfico de ${tickerMatch[1].toUpperCase()} | Chartiko`,
            description: `Consulta el gráfico, los indicadores y las Signals disponibles para ${tickerMatch[1].toUpperCase()} en Chartiko.`,
          }
        : undefined)
    document.title = isHome ? INDEXABLE_TITLE : routeMetadata?.title ?? 'Página no encontrada | Chartiko'

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
      ? INDEXABLE_DESCRIPTION
      : routeMetadata?.description ?? 'No se encontró esta página de Chartiko.'

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
  }, [pathname, search])

  return null
}
