/**
 * "Cómo funciona" user manual (how-it-works-manual): what each screen, filter,
 * indicator, signal and pattern means. The texts live in the typed guide
 * catalogs (`frontend/src/i18n/guide/*.ts`) and must match the code; this
 * component only lays them out (DESIGN.md tokens, accessible headings and an
 * in-page table of contents).
 */

import { Link } from 'react-router'
import TechnicalExample from '../components/TechnicalExample.tsx'
import { GUIDE_TEXTS } from '../i18n/guide/index.ts'
import type { GuideSection } from '../i18n/guide/types.ts'
import { useI18n } from '../i18n/useI18n.ts'

const CTA_CLASS =
  'inline-flex w-fit items-center rounded-md border-2 border-outline bg-primary-container px-5 py-3 font-headline text-sm font-bold uppercase tracking-wide text-on-primary-container shadow-[3px_3px_0px_var(--color-shadow)] transition-transform hover:-translate-y-px focus:outline-2 focus:outline-offset-4 focus:outline-outline'

const CARD_CLASS = 'border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_var(--color-shadow)]'

const LINK_CLASS =
  'font-mono text-xs font-bold text-on-surface underline-offset-4 hover:underline focus:outline-2 focus:outline-offset-2 focus:outline-outline'

export default function HowItWorksPage() {
  const { locale } = useI18n()
  const guide = GUIDE_TEXTS[locale]

  return (
    <article className="mx-auto flex w-full max-w-5xl flex-col gap-10 py-6 sm:py-10">
      <header className="flex flex-col items-start gap-5 border-b-2 border-outline pb-8">
        <p className="font-mono text-xs font-bold uppercase tracking-[0.18em] text-on-surface-variant">{guide.eyebrow}</p>
        <h1 className="font-headline text-4xl font-black leading-tight tracking-tight sm:text-5xl">{guide.title}</h1>
        <p className="max-w-3xl text-base leading-7 text-on-surface-variant sm:text-lg">{guide.intro}</p>
        <Link to="/" className={CTA_CLASS}>
          {guide.openScreener} <span aria-hidden="true" className="ml-2">→</span>
        </Link>
      </header>

      <nav aria-labelledby="guide-toc" className={CARD_CLASS}>
        <h2 id="guide-toc" className="font-headline text-sm font-black uppercase tracking-wider">{guide.tocTitle}</h2>
        <ol className="mt-3 grid list-decimal gap-x-8 gap-y-1.5 pl-5 sm:grid-cols-2">
          {guide.sections.map((section) => (
            <li key={section.id} className="text-sm">
              <a href={`#${section.id}`} className={LINK_CLASS}>
                {section.title}
              </a>
            </li>
          ))}
          <li className="text-sm">
            <a href="#faq" className={LINK_CLASS}>
              {guide.faqTitle}
            </a>
          </li>
        </ol>
      </nav>

      {guide.sections.map((section) => (
        <GuideSectionBlock key={section.id} section={section} />
      ))}

      <section id="faq" aria-labelledby="faq-heading" className="scroll-mt-24 border-t-2 border-outline pt-8">
        <h2 id="faq-heading" className="font-headline text-2xl font-black tracking-tight sm:text-3xl">{guide.faqTitle}</h2>
        <div className="mt-4 divide-y divide-outline-variant">
          {guide.faq.map((item) => (
            <div key={item.question} className="py-4">
              <h3 className="font-headline text-base font-bold">{item.question}</h3>
              <p className="mt-2 text-sm leading-6 text-on-surface-variant">{item.answer}</p>
            </div>
          ))}
        </div>
        <Link to="/" className={`${CTA_CLASS} mt-5`}>
          {guide.openScreener} <span aria-hidden="true" className="ml-2">→</span>
        </Link>
      </section>

      <p className="border-t border-outline-variant pt-5 text-xs leading-5 text-on-surface-variant">{guide.disclaimer}</p>
    </article>
  )
}

function GuideSectionBlock({ section }: { section: GuideSection }) {
  const headingId = `${section.id}-heading`

  return (
    <section id={section.id} aria-labelledby={headingId} className="flex scroll-mt-24 flex-col gap-4">
      <h2 id={headingId} className="font-headline text-2xl font-black tracking-tight sm:text-3xl">
        {section.title}
      </h2>

      {section.paragraphs?.map((paragraph) => (
        <p key={paragraph} className="max-w-3xl text-sm leading-7 text-on-surface-variant sm:text-base">
          {paragraph}
        </p>
      ))}

      {section.steps ? (
        <ol className="grid gap-4 md:grid-cols-3">
          {section.steps.map((step, index) => (
            <li key={step.title} className={CARD_CLASS}>
              <p className="font-mono text-xs font-bold text-on-surface-variant">{String(index + 1).padStart(2, '0')}</p>
              <h3 className="mt-2 font-headline text-lg font-bold">{step.title}</h3>
              <p className="mt-2 text-sm leading-6 text-on-surface-variant">{step.body}</p>
            </li>
          ))}
        </ol>
      ) : null}

      {section.entries ? (
        <dl className="grid gap-3 md:grid-cols-2">
          {section.entries.map((entry) => (
            <div key={entry.term} className={CARD_CLASS}>
              <dt className="font-headline text-base font-bold">{entry.term}</dt>
              <dd className="mt-2 text-sm leading-6 text-on-surface-variant">
                <p>{entry.summary}</p>
                {entry.illustration ? (
                  <div className="mt-3">
                    <TechnicalExample kind={entry.illustration.kind} label={entry.illustration.alt} />
                  </div>
                ) : null}
                {entry.details ? (
                  <ul className="mt-2 list-disc space-y-1 pl-5">
                    {entry.details.map((detail) => (
                      <li key={detail}>{detail}</li>
                    ))}
                  </ul>
                ) : null}
              </dd>
            </div>
          ))}
        </dl>
      ) : null}

      {section.notes ? (
        <ul className="max-w-3xl list-disc space-y-1.5 pl-5 text-sm leading-6 text-on-surface-variant">
          {section.notes.map((note) => (
            <li key={note}>{note}</li>
          ))}
        </ul>
      ) : null}
    </section>
  )
}
