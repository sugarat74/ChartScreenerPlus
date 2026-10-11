import { Navigate, useLocation } from 'react-router'

/** Keep previously shared Screener URLs working with their filter state intact. */
export default function LegacyScreenerRedirect() {
  const { search, hash } = useLocation()
  return <Navigate to={`/${search}${hash}`} replace />
}
