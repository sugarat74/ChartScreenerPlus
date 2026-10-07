/**
 * Lightweight Charts lifecycle for one instrument's chart payload.
 *
 * The library is self-hosted (`lightweight-charts`, Apache-2.0) — never the
 * embedded TradingView widget — and the `attributionLogo` layout option stays
 * enabled to satisfy its attribution requirement. This component is
 * presentational: it never fetches, never computes indicators and never draws
 * stop/target (not modeled). The chart is created in one effect whose cleanup
 * always calls `chart.remove()`, so a ticker change or unmount (including
 * React StrictMode double-invocation) cannot leak a canvas.
 */

import { useEffect, useRef } from 'react'
import {
  CandlestickSeries,
  ColorType,
  CrosshairMode,
  HistogramSeries,
  LineStyle,
  createChart,
} from 'lightweight-charts'
import { LOCALE_DEFINITIONS } from '../../i18n/locales.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import type { InstrumentBar, InstrumentSignal, InstrumentSnapshot } from '../../lib/api.ts'
import {
  CHART_COLORS,
  signalLevels,
  smaLevels,
  toCandlestickData,
  toVolumeData,
} from '../../lib/chartData.ts'

/** Mirrors the `--font-mono` token stack. */
const MONO_FONT_FAMILY =
  'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace'

interface InteractiveChartProps {
  ticker: string
  bars: InstrumentBar[]
  snapshot: InstrumentSnapshot | null
  signals: InstrumentSignal[]
}

export default function InteractiveChart({
  ticker,
  bars,
  snapshot,
  signals,
}: InteractiveChartProps) {
  const { locale, t } = useI18n()
  const containerRef = useRef<HTMLDivElement | null>(null)
  const pivotTitle = t('chart.pivot')

  useEffect(() => {
    const container = containerRef.current
    if (container === null) {
      return
    }

    const chart = createChart(container, {
      autoSize: true,
      layout: {
        background: { type: ColorType.Solid, color: CHART_COLORS.surfaceBright },
        textColor: CHART_COLORS.ink,
        fontFamily: MONO_FONT_FAMILY,
        attributionLogo: true,
      },
      grid: {
        vertLines: { color: CHART_COLORS.grid },
        horzLines: { color: CHART_COLORS.grid },
      },
      rightPriceScale: { borderColor: CHART_COLORS.ink },
      timeScale: { borderColor: CHART_COLORS.ink },
      crosshair: { mode: CrosshairMode.Normal },
      // Axis prices and dates follow the selected language.
      localization: { locale: LOCALE_DEFINITIONS[locale].intl },
    })

    const candleSeries = chart.addSeries(CandlestickSeries, {
      upColor: CHART_COLORS.gain,
      downColor: CHART_COLORS.loss,
      borderUpColor: CHART_COLORS.ink,
      borderDownColor: CHART_COLORS.ink,
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

    // Latest-snapshot SMA values as horizontal reference lines (nulls omitted).
    for (const level of smaLevels(snapshot)) {
      candleSeries.createPriceLine({
        price: level.price,
        color: level.color,
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
        color: level.color,
        lineWidth: 2,
        lineStyle: LineStyle.Dashed,
        axisLabelVisible: true,
        title: level.title,
      })
    }

    chart.timeScale().fitContent()

    return () => {
      // Removes the series, price lines and observers from the container.
      chart.remove()
    }
  }, [ticker, bars, snapshot, signals, locale, pivotTitle])

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
