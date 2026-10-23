import { useI18n } from '../../i18n/useI18n.ts'
import type { MarketRegime, MarketRegimeLabel, RegimeComponent, RegimeComponentKey } from '../../lib/api.ts'
import HistoryPanel from './HistoryPanel.tsx'
import MarketCard, { NoData, StaleBadge } from './MarketCard.tsx'
import { dateLine, reasonSentence, signed } from './marketText.ts'

const COMPONENT_KEYS: readonly RegimeComponentKey[] = [
  'breadth',
  'index_trend',
  'sector_participation',
  'yield_curve',
  'financial_conditions',
  'liquidity',
  'volatility',
]

/** Icon + colour of each label; the text is always shown next to the icon. */
const LABEL_STYLE: Record<MarketRegimeLabel, { icon: string; className: string }> = {
  favourable: { icon: '▲', className: 'text-gain' },
  neutral: { icon: '●', className: 'text-on-surface' },
  adverse: { icon: '▼', className: 'text-secondary' },
  insufficient: { icon: '?', className: 'text-on-surface-variant' },
}

function isComponentKey(key: string): key is RegimeComponentKey {
  return (COMPONENT_KEYS as readonly string[]).includes(key)
}

function Contribution({ component }: { component: RegimeComponent }) {
  const { t } = useI18n()
  if (component.status !== 'ok' || component.score === null) {
    return <span className="font-mono text-[11px] font-bold text-on-surface-variant">{t('marketContext.regime.notCounted')}</span>
  }
  const [icon, key, className] =
    component.score > 0
      ? (['▲', 'marketContext.regime.contribution.plus', 'text-gain'] as const)
      : component.score < 0
        ? (['▼', 'marketContext.regime.contribution.minus', 'text-secondary'] as const)
        : (['●', 'marketContext.regime.contribution.zero', 'text-on-surface'] as const)
  return (
    <span className={`font-mono text-[11px] font-bold ${className}`}>
      <span aria-hidden="true">{icon} </span>
      {t(key)}
    </span>
  )
}

/** The Market Regime light: label with icon and text, score, count and reasons (C7). */
export default function RegimeCard({ regime }: { regime: MarketRegime | null }) {
  const i18n = useI18n()
  const { t } = i18n

  const history = (
    <HistoryPanel
      options={[
        {
          metric: 'regime_score',
          label: t('marketContext.history.metrics.regime_score'),
          reference: [-3, 0, 3],
          formatValue: (value) => signed(i18n, value, 0),
        },
      ]}
    />
  )

  if (regime === null) {
    return (
      <MarketCard title={t('marketContext.regime.title')} meaning={t('marketContext.regime.meaning')} sources={[t('marketContext.sourceChartiko')]}>
        <NoData />
      </MarketCard>
    )
  }

  const style = LABEL_STYLE[regime.regime]
  const insufficient = regime.regime === 'insufficient'
  const reasons = Object.entries(regime.components).filter(
    (entry): entry is [RegimeComponentKey, RegimeComponent] => isComponentKey(entry[0]) && entry[1] !== undefined,
  )

  return (
    <MarketCard
      title={t('marketContext.regime.title')}
      dateLine={dateLine(i18n, regime.date)}
      stale={regime.stale}
      meaning={t('marketContext.regime.meaning')}
      sources={[t('marketContext.sourceChartiko')]}
      history={history}
    >
      <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
        <p
          className={`inline-flex items-center gap-2 rounded-md border-2 border-outline bg-surface px-3 py-1.5 font-headline text-2xl font-black uppercase tracking-tight shadow-[2px_2px_0px_var(--color-shadow)] ${style.className}`}
          data-testid="regime-label"
        >
          <span aria-hidden="true">{style.icon}</span>
          {t(`marketContext.regime.labels.${regime.regime}`)}
        </p>
        <div className="flex flex-col font-mono text-xs text-on-surface">
          {!insufficient && regime.score !== null ? (
            <span className="text-base font-bold">{t('marketContext.regime.score', { score: signed(i18n, regime.score, 0) })}</span>
          ) : null}
          <span className="text-on-surface-variant">
            {t('marketContext.regime.available', { available: regime.available, min: regime.rules.min_available })}
          </span>
        </div>
      </div>
      {insufficient ? (
        <p className="text-sm text-on-surface-variant">
          {t('marketContext.regime.insufficientBody', { min: regime.rules.min_available, available: regime.available })}
        </p>
      ) : null}
      <div>
        <h3 className="font-headline text-[11px] font-bold uppercase tracking-wide text-on-surface-variant">
          {t('marketContext.regime.reasons')}
        </h3>
        <ul className="mt-1 divide-y divide-outline-variant">
          {reasons.map(([key, component]) => (
            <li key={key} className="flex flex-col gap-0.5 py-1.5 sm:flex-row sm:items-baseline sm:justify-between sm:gap-3">
              <div className="min-w-0">
                <span className="font-headline text-xs font-bold text-on-surface">
                  {t(`marketContext.regime.components.${key}.name`)}
                </span>
                <span className="text-xs text-on-surface-variant"> · {reasonSentence(i18n, key, component)}</span>
                {component.as_of !== null ? (
                  <span className="ml-1 font-mono text-[10px] text-on-surface-variant">({dateLine(i18n, component.as_of)})</span>
                ) : null}
              </div>
              <div className="flex shrink-0 items-center gap-2">
                {component.status === 'stale' ? <StaleBadge /> : null}
                <Contribution component={component} />
              </div>
            </li>
          ))}
        </ul>
      </div>
    </MarketCard>
  )
}
