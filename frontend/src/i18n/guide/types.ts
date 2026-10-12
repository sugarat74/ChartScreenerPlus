/**
 * Shape of the "Cómo funciona" user manual (how-it-works-manual). Every rule,
 * threshold and label must match the code and the on-screen texts: indicator
 * periods (CONSTRAINTS.md -> Indicators), signal rules (-> Signals), pattern
 * parameters (engine/app/patterns/rules.py, engine/app/signals/rules.py;
 * chart-patterns-calibration will change them) and UI labels in
 * `frontend/src/i18n/messages/*.ts`. Update this text whenever those change.
 */

/** One explained item: a filter, an indicator, a signal, a pattern… */
export type GuideIllustrationKind =
  | 'movingAverages' | 'rsi' | 'macd' | 'rvol' | 'pivot' | 'volume'
  | 'goldenCross' | 'deathCross' | 'bullishAlignment' | 'bearishAlignment'
  | 'pivotBreakoutRvol' | 'rsiOverbought' | 'rsiOversold' | 'macdBullish' | 'macdBearish'
  | 'doubleTop' | 'doubleBottom' | 'cupWithHandle' | 'bullFlag'

export type GuideEntry = {
  term: string
  /** What it is / what it measures. */
  summary: string
  /** Optional details: exact rule, how to read it, caveats. */
  details?: string[]
  /** Accessible, schematic image for an indicator, signal or chart pattern. */
  illustration?: { kind: GuideIllustrationKind; alt: string }
}

export type GuideSection = {
  /** Stable anchor id used by the table of contents (`#id`). */
  id: string
  title: string
  paragraphs?: string[]
  /** Numbered steps (only the quick start uses them). */
  steps?: { title: string; body: string }[]
  entries?: GuideEntry[]
  /** Short notes shown after the entries. */
  notes?: string[]
}

export type GuideTexts = {
  eyebrow: string
  title: string
  intro: string
  openScreener: string
  tocTitle: string
  sections: GuideSection[]
  faqTitle: string
  faq: { question: string; answer: string }[]
  disclaimer: string
}
