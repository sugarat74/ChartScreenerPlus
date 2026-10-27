// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import { MESSAGES } from '../../i18n/messages/index.ts'
import type { InstrumentSignal } from '../../lib/api.ts'
import { SCREENER_SIGNAL_TYPES, signalLabel, normalizeSignals } from '../../lib/screenerFilters.ts'
import { translate } from '../../i18n/translate.ts'
import type { MessageKey } from '../../i18n/translate.ts'
import SignalLevelsPanel from './SignalLevelsPanel.tsx'

function signal(type: string, metadata: Record<string, number>): InstrumentSignal {
  return { type, date: '2026-09-28', metadata } as InstrumentSignal
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en'))
afterEach(() => {
  cleanup()
  window.localStorage.clear()
})

describe('signal vocabulary', () => {
  it('lists the fifteen types and labels each in Spanish and English', () => {
    expect(SCREENER_SIGNAL_TYPES).toHaveLength(15)
    expect(normalizeSignals([...SCREENER_SIGNAL_TYPES].reverse())).toEqual(SCREENER_SIGNAL_TYPES)

    for (const locale of ['es', 'en'] as const) {
      const t = (key: MessageKey) => translate(MESSAGES[locale], key)
      for (const type of SCREENER_SIGNAL_TYPES) {
        expect(signalLabel(type, t)).not.toBe(type)
      }
    }
  })
})

describe('SignalLevelsPanel', () => {
  it('shows the metadata of the new signals', () => {
    render(
      <I18nProvider>
        <SignalLevelsPanel
          signals={[
            signal('adx_trend_strength', { adx: 26.5, plus_di: 30, minus_di: 12 }),
            signal('bollinger_squeeze', { bandwidth: 3.25, lookback_min: 3.5 }),
            signal('bollinger_close_above_upper', { close: 110, bb_upper: 104 }),
            signal('stoch_bullish_cross', { stoch_k: 22, stoch_d: 19 }),
          ]}
        />
      </I18nProvider>,
    )

    expect(screen.getByText('ADX trend strength')).toBeTruthy()
    expect(screen.getByText('26.50')).toBeTruthy()
    expect(screen.getByText('+DI')).toBeTruthy()
    expect(screen.getByText('Narrow bands')).toBeTruthy()
    expect(screen.getByText('3.25')).toBeTruthy()
    expect(screen.getByText('Upper band')).toBeTruthy()
    expect(screen.getByText('%K')).toBeTruthy()
    expect(document.body.textContent).not.toMatch(/buy|sell/i)
  })
})
