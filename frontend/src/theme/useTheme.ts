import { useContext } from 'react'
import { ThemeContext } from './context.ts'
import type { ThemeContextValue } from './context.ts'

/** Read the active color theme and its setter. */
export function useTheme(): ThemeContextValue {
  return useContext(ThemeContext)
}
