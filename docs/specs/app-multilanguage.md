# Feature Implementation Spec: Support Spanish and English across Chartiko

## Source Feature

- `id`: `app-multilanguage`
- `area`: `localization`
- `depends_on`: `app-shell-navigation`, `auth-registration-login`, `public-marketing-seo-geo` (all `accepted`)
- `source`: `feature_list.json`

## Goal

Visitors, Registered Users and Admins can switch Chartiko between Spanish (`es`) and English (`en`)
with an accessible selector in the header. Every SPA surface (public home, Screener, Candidate list,
chart, account forms, Portal, Admin, 404, page titles/descriptions) and every user-facing API message
follows the selected language. The choice persists on the same device.

## Non-Goals

- Account-synced language preference, more languages, automatic/machine translation.
- Translated URLs (`/en/...`), hreflang or any new SEO indexing strategy: the static `index.html`
  that crawlers read stays Spanish and canonical rules are unchanged.
- Translating data: ticker symbols, company/sector names, technical acronyms (RSI, MACD, RVOL, SMA),
  user-authored Saved Screener names, Signal codes, ingestion log lines stored by the backend and
  engine/console output.

## Design

### SPA (`frontend/src/i18n/`)

- `locales.ts` — registry (`SUPPORTED_LOCALES`, `LOCALE_DEFINITIONS` with native name, short label
  and `Intl` tag), pure resolution `resolveLocale(stored, browserLanguages)` and storage helpers that
  never throw. Order: explicit saved choice (`localStorage` key `chartiko.locale`) → first supported
  browser language by primary subtag (`en-GB` → `en`) → Spanish.
- `messages/es.ts` defines the catalog shape (`Messages`); `messages/en.ts` is typed `Messages`, so a
  missing or extra key fails `tsc`. `translate.ts` exposes a typed `MessageKey` (dot paths) and
  `{name}` interpolation with fallback to Spanish, then to the key.
- `I18nProvider` sits above `AuthProvider` and the router: switching re-renders in place, so the URL
  (filters, sort, ticker), the selected Candidate and the session survive. It sets
  `<html lang>` and the module-level active locale read by `lib/api.ts`.
- `useI18n()` returns `t` plus `Intl` formatters bound to the locale (prices, signed percentages,
  multiples, compact volume, timestamps, market-session dates formatted in UTC so no time zone
  shifts a session). `useTranslateRef()` gives async effect callbacks the latest `t` without making
  a language switch refetch data.
- `LanguageSelector` — segmented `ES | EN` button group (`role="group"`, `aria-pressed`, each option
  labelled with its native name in its own `lang`), DESIGN.md token styling, always visible.
- `Interpolate` renders templates whose placeholders are React nodes (bold counts).
- `lib/api.ts` sends `Accept-Language: <locale>` on every request (CSRF cookie included) and
  `apiErrorMessage()` replaces six duplicated `messageFor` helpers.

### Laravel

- `App\Http\Middleware\SetLocale` is prepended to the `api` group. It picks the first supported
  language from `Accept-Language` in q-value order by primary subtag; an absent, unsupported or
  invalid header falls back to `config('locales.default')` (`es`). It sets `Content-Language`.
- `config/locales.php` lists supported locales. `lang/{es,en}/{validation,auth,passwords,pagination,
  messages}.php` hold the strings; controllers and `EnsureUserIsAdmin` use `__('messages.*')`
  instead of hardcoded text. Status codes, JSON shapes, `errors` keys and Signal codes are unchanged.

## Decisions

- Admin keeps its EOD operational wording in both languages; every non-Admin catalog entry is tested
  to contain no EOD/end-of-day wording.
- Laravel's test client sends `Accept-Language: en-us` by default, so existing English assertions
  still hold; `LocalizationTest` sends explicit headers (empty header → Spanish).
- Number formatting is display-only: filter inputs, URL params and stored definitions keep raw
  numbers. Prices stay plain two-decimal numbers (no currency symbol), as before.

## Adding A Language

1. Add the code to `SUPPORTED_LOCALES` and `LOCALE_DEFINITIONS` in `frontend/src/i18n/locales.ts`.
2. Add `frontend/src/i18n/messages/<code>.ts` typed `Messages` and register it in `messages/index.ts`.
3. Add the code to `config/locales.php` and create `lang/<code>/` with the same files and keys as
   `lang/en/`.
4. Run `.\init.ps1`: `tsc` rejects missing SPA keys; Vitest and `LocalizationTest` check key and
   placeholder parity for both catalogs.

## Verification

- `npm --prefix frontend run test` (Vitest): locale resolution/persistence (saved, regional browser
  languages, invalid/unavailable storage), catalog completeness and placeholder parity, no EOD outside
  Admin, interpolation/fallback, locale-aware number and market-date formatting.
- `php artisan test --filter=LocalizationTest`: header resolution matrix, localized 404/422/403
  messages with identical statuses and `errors` keys, identical successful payloads across
  languages, `lang/es` ↔ `lang/en` key and placeholder parity.
- `.\init.ps1` (now also runs the SPA unit tests).
