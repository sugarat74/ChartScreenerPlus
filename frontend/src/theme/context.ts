import { createContext } from 'react'
import type { Theme } from './theme.ts'

export interface ThemeContextValue {
  theme: Theme
  setTheme: (theme: Theme) => void
}

/**
 * The default keeps components that read the theme (the chart, the selector)
 * renderable without a provider, e.g. in isolated tests: light, no-op setter.
 */
export const ThemeContext = createContext<ThemeContextValue>({
  theme: 'light',
  setTheme: () => {},
})
