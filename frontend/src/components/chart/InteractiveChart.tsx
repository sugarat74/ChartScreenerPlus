/**
 * Lightweight Charts lifecycle for one instrument's chart payload.
 *
 * The library is self-hosted (`lightweight-charts`, Apache-2.0) — never the
 * embedded TradingView widget — and the `attributionLogo` layout option stays
 * enabled to satisfy its attribution requirement. This component is
 * presentational: it never fetches, never computes indicators and never draws
 * stop/target (not modeled). Active chartist patterns add key-point markers
 * and one breakout-level line each. The chart is created in one effect whose cleanup
 * always calls `chart.remove()`, so a ticker change or unmount (including
 * React StrictMode double-invocation) cannot leak a canvas.
 */

import { useEffect, useRef } from 'react'
import {
  CandlestickSeries,
  ColorType,
  CrosshairMode,
  HistogramSeries,
  LineSeries,
  LineStyle,
  createChart,
  createSeriesMarkers,
} from 'lightweight-charts'
import { LOCALE_DEFINITIONS } from '../../i18n/locales.ts'
import type { MessageKey } from '../../i18n/translate.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import { patternLabel } from '../../lib/screenerFilters.ts'
import type { InstrumentBar, InstrumentIndicatorPoint, InstrumentPattern, InstrumentSignal, InstrumentSnapshot } from '../../lib/api.ts'
import {
  CHART_COLORS,
  CHART_THEME_COLORS,
  bandSignalLevels,
  patternLevels,
  patternMarkers,
  signalLevels,
  smaLevels,
  toBandData,
  toCandlestickData,
  toVolumeData,
} from '../../lib/chartData.ts'
import { useTheme } from '../../theme/useTheme.ts'

const NO_PATTERNS: InstrumentPattern[] = []

/** Mirrors the `--font-mono` token stack. */
const MONO_FONT_FAMILY =
  'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace'

interface InteractiveChartProps {
  ticker: string
  bars: InstrumentBar[]
  snapshot: InstrumentSnapshot | null
  signals: InstrumentSignal[]
  patterns?: InstrumentPattern[]
  /** Stored Bollinger series; `null` (default) draws no band and no band line. */
  bands?: InstrumentIndicatorPoint[] | null
}

export default function InteractiveChart({
  ticker,
  bars,
  snapshot,
  signals,
  patterns = NO_PATTERNS,
  bands = null,
}: InteractiveChartProps) {
  const { locale, t } = useI18n()
  const { theme } = useTheme()
  const containerRef = useRef<HTMLDivElement | null>(null)
  const pivotTitle = t('chart.pivot')
  const upperBandTitle = t('chart.bandUpper')
  const lowerBandTitle = t('chart.bandLower')

  useEffect(() => {
    const container = containerRef.current
    if (container === null) {
      return
    }

    // Rebuilt on a theme switch (in the dependencies) with that theme's chrome.
    const chrome = CHART_THEME_COLORS[theme]
    // Data helpers use the light blue; swap in the theme's for lines and markers.
    const tint = (color: string) => (color === CHART_COLORS.tertiary ? chrome.tertiary : color)
    const chart = createChart(container, {
      autoSize: true,
      layout: {
        background: { type: ColorType.Solid, color: chrome.background },
        textColor: chrome.text,
        fontFamily: MONO_FONT_FAMILY,
        attributionLogo: true,
      },
      grid: {
        vertLines: { color: chrome.grid },
        horzLines: { color: chrome.grid },
      },
      rightPriceScale: { borderColor: chrome.border },
      timeScale: { borderColor: chrome.border },
      crosshair: { mode: CrosshairMode.Normal },
      // Axis prices and dates follow the selected language.
      localization: { locale: LOCALE_DEFINITIONS[locale].intl },
    })

    const candleSeries = chart.addSeries(CandlestickSeries, {
      upColor: CHART_COLORS.gain,
      downColor: CHART_COLORS.loss,
      borderUpColor: chrome.candleUp,
      borderDownColor: chrome.candleDown,
      wickUpColor: CHART_COLORS.gain,
      wickDownColor: CHART_COLORS.loss,
    })
    candleSeries.setData(toCandlestickData(bars))

    // Separate volume pane below the price pane (v5 pane index argument).
    const volumeSeries = chart.addSeries(
      HistogramSeries,
      { priceFormat: { type: 'volume' } },
      1,
    )
    volumeSeries.setData(toVolumeData(bars))

    // Bollinger Bands (only when the overlay is on): stored series, warm-up
    // points omitted; the two band Signals add a dashed line at the crossed band.
    if (bands !== null) {
      const edge = { color: chrome.band, lineWidth: 1, lastValueVisible: false, priceLineVisible: false } as const
      chart.addSeries(LineSeries, edge).setData(toBandData(bands, 'bb_upper'))
      chart.addSeries(LineSeries, edge).setData(toBandData(bands, 'bb_lower'))
      chart
        .addSeries(LineSeries, { ...edge, lineStyle: LineStyle.Dashed })
        .setData(toBandData(bands, 'bb_middle'))

      for (const level of bandSignalLevels(signals, { upper: upperBandTitle, lower: lowerBandTitle })) {
        candleSeries.createPriceLine({
          price: level.price,
          color: chrome.band,
          lineWidth: 1,
          lineStyle: LineStyle.Dashed,
          axisLabelVisible: true,
          title: level.title,
        })
      }
    }

    // Latest-snapshot SMA values as horizontal reference lines (nulls omitted).
    for (const level of smaLevels(snapshot)) {
      candleSeries.createPriceLine({
        price: level.price,
        color: tint(level.color),
        lineWidth: 1,
        lineStyle: LineStyle.Dashed,
        axisLabelVisible: true,
        title: level.title,
      })
    }

    // Only the pivot of an active `pivot_breakout_rvol` signal is modeled.
    for (const level of signalLevels(signals, pivotTitle)) {
      candleSeries.createPriceLine({
        price: level.price,
        color: tint(level.color),
        lineWidth: 2,
        lineStyle: LineStyle.Dashed,
        axisLabelVisible: true,
        title: level.title,
      })
    }

    // One breakout-level line per active pattern plus its key points.
    for (const level of patternLevels(patterns, (pattern) => patternLabel(pattern.type, t))) {
      candleSeries.createPriceLine({
        price: level.price,
        color: tint(level.color),
        lineWidth: 2,
        lineStyle: LineStyle.Dotted,
        axisLabelVisible: true,
        title: level.title,
      })
    }
    const markers = patternMarkers(patterns, bars, (role) => t(`patterns.rolesShort.${role}` as MessageKey))
    if (markers.length > 0) {
      createSeriesMarkers(candleSeries, markers.map((marker) => ({ ...marker, color: tint(marker.color) })))
    }

    chart.timeScale().fitContent()

    return () => {
      // Removes the series, price lines and observers from the container.
      chart.remove()
    }
  }, [ticker, bars, snapshot, signals, patterns, bands, locale, pivotTitle, upperBandTitle, lowerBandTitle, t, theme])

  return (
    <div
      ref={containerRef}
      role="img"
      aria-label={t(snapshot === null ? 'chart.chartAriaNoSnapshot' : 'chart.chartAria', {
        ticker,
        count: bars.length,
      })}
      className="h-[420px] w-full"
    />
  )
}
