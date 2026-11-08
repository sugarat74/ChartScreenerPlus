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
import { activePanes, chartHeight } from '../../lib/chartIndicators.ts'
import type { ChartIndicatorId, ChartPaneId } from '../../lib/chartIndicators.ts'
import { patternLabel } from '../../lib/screenerFilters.ts'
import type { InstrumentBar, InstrumentIndicatorPoint, InstrumentPattern, InstrumentSignal, InstrumentSnapshot } from '../../lib/api.ts'
import {
  ADX_REFERENCE,
  CHART_COLORS,
  STOCH_ZONES,
  CHART_THEME_COLORS,
  bandSignalLevels,
  patternLevels,
  patternMarkers,
  signalLevels,
  smaLevels,
  toBandData,
  toIndicatorData,
  toCandlestickData,
  toVolumeData,
} from '../../lib/chartData.ts'
import { useTheme } from '../../theme/useTheme.ts'

const NO_PATTERNS: InstrumentPattern[] = []
const NO_INDICATORS: readonly ChartIndicatorId[] = []

/** Mirrors the `--font-mono` token stack. */
const MONO_FONT_FAMILY =
  'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace'

interface InteractiveChartProps {
  ticker: string
  bars: InstrumentBar[]
  snapshot: InstrumentSnapshot | null
  signals: InstrumentSignal[]
  patterns?: InstrumentPattern[]
  /** Stored indicator series; `null` (default) draws no band, no pane and no band line. */
  series?: InstrumentIndicatorPoint[] | null
  /** Switched-on indicators: Bollinger on the price pane, ADX/ATR/Stochastic as panes. */
  indicators?: readonly ChartIndicatorId[]
}

export default function InteractiveChart({
  ticker,
  bars,
  snapshot,
  signals,
  patterns = NO_PATTERNS,
  series = null,
  indicators = NO_INDICATORS,
}: InteractiveChartProps) {
  const { locale, t } = useI18n()
  const { theme } = useTheme()
  const containerRef = useRef<HTMLDivElement | null>(null)
  const pivotTitle = t('chart.pivot')
  const upperBandTitle = t('chart.bandUpper')
  const lowerBandTitle = t('chart.bandLower')
  const bands = series !== null && indicators.includes('bollinger') ? series : null
  // Panes only exist once the stored series arrived; their height follows the count.
  const activeIds = series === null ? [] : activePanes(indicators)
  const paneKey = activeIds.join(',')
  const height = chartHeight(activeIds.length)

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

    const panes = (paneKey === '' ? [] : paneKey.split(',')) as ChartPaneId[]
    // Indicator sub-panes after the volume pane (index 1): 2, 3, 4 in the fixed order.
    // A pane's stretch factor is its pixel share (price and volume 210 each as
    // before, 120 per indicator pane), so the price pane keeps its size.
    if (series !== null && panes.length > 0) {
      const line = { priceLineVisible: false, lastValueVisible: true, lineWidth: 1 } as const
      panes.forEach((pane, offset) => {
        const index = 2 + offset
        if (pane === 'adx') {
          const adx = chart.addSeries(LineSeries, { ...line, color: chrome.adx, lineWidth: 2, title: 'ADX' }, index)
          adx.setData(toIndicatorData(series, 'adx'))
          adx.createPriceLine({
            price: ADX_REFERENCE,
            color: chrome.reference,
            lineWidth: 1,
            lineStyle: LineStyle.Dashed,
            axisLabelVisible: true,
            title: '',
          })
          chart
            .addSeries(LineSeries, { ...line, color: CHART_COLORS.gain, title: '+DI' }, index)
            .setData(toIndicatorData(series, 'plus_di'))
          chart
            .addSeries(LineSeries, { ...line, color: CHART_COLORS.loss, title: '-DI' }, index)
            .setData(toIndicatorData(series, 'minus_di'))
        } else if (pane === 'atr') {
          chart
            .addSeries(LineSeries, { ...line, color: chrome.atr, lineWidth: 2, title: 'ATR' }, index)
            .setData(toIndicatorData(series, 'atr14'))
        } else {
          // Fixed 0..100 axis with the 20 / 80 zone lines.
          const fixedRange = () => ({ priceRange: { minValue: 0, maxValue: 100 } })
          const stochK = chart.addSeries(
            LineSeries,
            { ...line, color: chrome.stochK, lineWidth: 2, title: '%K', autoscaleInfoProvider: fixedRange },
            index,
          )
          stochK.setData(toIndicatorData(series, 'stoch_k'))
          stochK.priceScale().applyOptions({ scaleMargins: { top: 0.05, bottom: 0.05 } })
          for (const zone of STOCH_ZONES) {
            stochK.createPriceLine({
              price: zone,
              color: chrome.reference,
              lineWidth: 1,
              lineStyle: LineStyle.Dashed,
              axisLabelVisible: true,
              title: '',
            })
          }
          chart
            .addSeries(
              LineSeries,
              { ...line, color: chrome.stochD, title: '%D', autoscaleInfoProvider: fixedRange },
              index,
            )
            .setData(toIndicatorData(series, 'stoch_d'))
        }
      })
      const layout = chart.panes()
      layout[0]?.setStretchFactor(210)
      layout[1]?.setStretchFactor(210)
      for (let offset = 0; offset < panes.length; offset += 1) {
        layout[2 + offset]?.setStretchFactor(120)
      }
    }

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
  }, [ticker, bars, snapshot, signals, patterns, series, bands, paneKey, locale, pivotTitle, upperBandTitle, lowerBandTitle, t, theme])

  return (
    <div
      ref={containerRef}
      role="img"
      aria-label={t(snapshot === null ? 'chart.chartAriaNoSnapshot' : 'chart.chartAria', {
        ticker,
        count: bars.length,
      })}
      style={{ height }}
      className="w-full"
    />
  )
}
