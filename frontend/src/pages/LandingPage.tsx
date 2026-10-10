import { Link } from 'react-router'
import { useI18n } from '../i18n/useI18n.ts'

const CTA_CLASS =
  'inline-flex w-fit items-center rounded-md border-2 border-outline bg-primary-container px-5 py-3 font-headline text-sm font-bold uppercase tracking-wide text-on-primary-container shadow-[3px_3px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:outline-2 focus:outline-offset-4 focus:outline-outline'

const STEP_CLASS = 'border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]'

export default function LandingPage() {
  const { t } = useI18n()

  const steps = [
    { label: t('landing.step1Label'), title: t('landing.step1Title'), body: t('landing.step1Body') },
    { label: t('landing.step2Label'), title: t('landing.step2Title'), body: t('landing.step2Body') },
    { label: t('landing.step3Label'), title: t('landing.step3Title'), body: t('landing.step3Body') },
  ]

  const faqs = [
    { question: t('landing.faqMarketQ'), answer: t('landing.faqMarketA') },
    { question: t('landing.faqSignalQ'), answer: t('landing.faqSignalA') },
    { question: t('landing.faqRealtimeQ'), answer: t('landing.faqRealtimeA') },
  ]

  return (
    <article className="mx-auto flex w-full max-w-5xl flex-col gap-12 py-6 sm:py-12">
      <header className="flex flex-col items-start gap-6 border-b-2 border-outline pb-10">
        <p className="font-mono text-xs font-bold uppercase tracking-[0.18em] text-on-surface-variant">
          {t('landing.eyebrow')}
        </p>
        <h1 className="max-w-4xl font-headline text-4xl font-black leading-tight tracking-tight sm:text-6xl">
          {t('landing.title')}
        </h1>
        <p className="max-w-3xl text-base leading-7 text-on-surface-variant sm:text-lg">
          {t('landing.intro')}
        </p>
        <Link to="/screener" className={CTA_CLASS}>
          {t('landing.openScreener')} <span aria-hidden="true" className="ml-2">→</span>
        </Link>
      </header>

      <section aria-labelledby="workflow-heading" className="grid gap-6 md:grid-cols-3">
        <h2 id="workflow-heading" className="sr-only">{t('landing.workflowHeading')}</h2>
        {steps.map((step) => (
          <article key={step.label} className={STEP_CLASS}>
            <p className="font-mono text-xs font-bold text-on-surface-variant">{step.label}</p>
            <h3 className="mt-3 font-headline text-xl font-bold">{step.title}</h3>
            <p className="mt-2 text-sm leading-6 text-on-surface-variant">{step.body}</p>
          </article>
        ))}
      </section>

      <section aria-labelledby="method-heading" className="max-w-3xl">
        <h2 id="method-heading" className="font-headline text-2xl font-black tracking-tight sm:text-3xl">
          {t('landing.methodHeading')}
        </h2>
        <p className="mt-3 text-sm leading-7 text-on-surface-variant sm:text-base">
          {t('landing.methodBody')}
        </p>
      </section>

      <section aria-labelledby="faq-heading" className="max-w-3xl border-t-2 border-outline pt-8">
        <h2 id="faq-heading" className="font-headline text-2xl font-black tracking-tight">
          {t('landing.faqHeading')}
        </h2>
        <div className="mt-5 divide-y divide-outline-variant">
          {faqs.map((faq) => (
            <div key={faq.question} className="py-4">
              <h3 className="font-headline text-base font-bold">{faq.question}</h3>
              <p className="mt-2 text-sm leading-6 text-on-surface-variant">{faq.answer}</p>
            </div>
          ))}
        </div>
        <Link to="/screener" className={`${CTA_CLASS} mt-5`}>
          {t('landing.exploreScreener')}
        </Link>
      </section>
    </article>
  )
}
