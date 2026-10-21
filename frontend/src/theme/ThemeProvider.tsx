import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { ThemeContext } from './context.ts'
import type { ThemeContextValue } from './context.ts'
import { applyTheme, darkSchemeQuery, readStoredTheme, systemTheme, writeStoredTheme } from './theme.ts'
import type { Theme } from './theme.ts'

/**
 * Owns the color theme. Without a stored choice it follows the operating
 * system, also when that setting changes while the page is open; once the
 * user picks a theme, that choice wins and is stored. Read it with `useTheme`.
 */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const [chosen, setChosen] = useState<Theme | null>(readStoredTheme)
  const [system, setSystem] = useState<Theme>(systemTheme)
  const theme = chosen ?? system

  useEffect(() => {
    applyTheme(theme)
  }, [theme])

  useEffect(() => {
    const query = darkSchemeQuery()
    if (query === null) {
      return
    }
    const onChange = () => setSystem(query.matches ? 'dark' : 'light')
    query.addEventListener('change', onChange)
    return () => query.removeEventListener('change', onChange)
  }, [])

  const setTheme = useCallback((next: Theme) => {
    writeStoredTheme(next)
    setChosen(next)
  }, [])

  const value = useMemo<ThemeContextValue>(() => ({ theme, setTheme }), [theme, setTheme])

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>
}
