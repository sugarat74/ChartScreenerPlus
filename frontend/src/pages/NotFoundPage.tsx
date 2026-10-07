import { Link } from 'react-router'
import { DEFAULT_ROUTE } from '../nav.ts'
import { useI18n } from '../i18n/useI18n.ts'

export default function NotFoundPage() {
  const { t } = useI18n()

  return (
    <section className="flex flex-col gap-5">
      <span className="w-fit rounded-[4px] border border-outline bg-secondary-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-secondary-container shadow-[1px_1px_0px_#1a1a1a]">
        404
      </span>

      <div>
        <h2 className="font-headline text-3xl font-black tracking-tight uppercase text-on-surface">
          {t('notFound.title')}
        </h2>
        <p className="mt-2 max-w-2xl text-sm text-on-surface-variant">
          {t('notFound.body')}
        </p>
      </div>

      <Link
        to={DEFAULT_ROUTE}
        className="w-fit rounded-md border-2 border-outline bg-primary-container px-4 py-2 font-headline text-sm font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_#1a1a1a]"
      >
        {t('notFound.back')}
      </Link>
    </section>
  )
}
