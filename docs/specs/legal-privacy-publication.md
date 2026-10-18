# Feature Implementation Spec: Publish the privacy policy with minimal owner data

## Source Feature

- `id`: legal-privacy-publication
- `area`: legal
- `depends_on`: legal-compliance-eu (accepted)
- `status`: passing (implemented and self-verified 2026-10-18; awaiting independent validation)
- `source`: `feature_list.json`

## Goal

Publish `/privacidad` (es/en) in production. It names the data controller as **Roberto Rodríguez**, with **contacto@chartiko.com** as the contact for GDPR rights (GDPR art. 13.1.a: identity and contact details). The legal notice (`/aviso-legal`, LSSI art. 10) stays withheld until a NIF and an address exist. To do this, the single owner gate from `legal-compliance-eu` becomes two gates, one per page, and no unpublished owner field may ever leave the server.

Owner decisions, taken 2026-10-18 and not to be reopened:

- The email is shown as readable text, never as a `mailto:` link.
- `robots.txt` disallows `/api/legal`.
- The production values are written by a deploy script on every deploy.
- The 14-day server-log retention stated in the policy becomes true in production.
- The legacy SQLite copies that hold personal data are deleted before publication. This is an operational step, not code.

## Non-Goals

- Publishing the NIF, a postal address or registry data, or the legal notice itself. These are never invented; this spec has no placeholder values for them.
- Changing any legal text other than removing the NIF and address lines from the privacy policy. That includes the processing inventory, legal bases and retention figures.
- Changing the cookie policy. It stays always published and needs no owner data. Its behaviour is unchanged.
- Self-service account deletion or export, a contact form, email obfuscation in JavaScript, captchas, sitemap changes, `X-Robots-Tag` changes and nginx changes.
- Off-VPS backups, journald retention, and a professional legal review. The review is still recommended.

## Job Story

When a visitor or a Registered User wants to know who processes their data and how to exercise their rights,
I want to read a published privacy policy that names the controller and gives a contact email,
so I can contact the controller without the owner having to publish a tax id or a home address.

## Users And Permissions

- Visitor and Registered User: read `/privacidad`, `/cookies` and `GET /api/legal`. These are anonymous; the endpoint keeps its `throttle:60,1,legal`.
- Operator (orchestrator, root console): runs the pre-publication deletions and the post-deploy checks. Deleting files under `/root` needs root. The `deploy` user only has sudo for `systemctl`.

## Acceptance Scenarios

### Scenario 1: privacy published, notice withheld (production target)

Given only `LEGAL_OWNER_NAME` and `LEGAL_CONTACT_EMAIL` are set
When `GET /api/legal` is called
Then it returns `privacy_published: true` and `notice_published: false`
And it returns `owner: {name, email, tax_id: null, address: null, registry: null}`
And there is no `published` key.

### Scenario 2: privacy page

Given scenario 1
When `/privacidad` is opened in es or en
Then the full policy renders, with "Responsable: Roberto Rodríguez" (en: "Controller: …")
And the contact email appears as plain text in the controller block and in "Tus derechos"
And the page has no `a[href^="mailto:"]`, no NIF/Domicilio (en: Tax ID/Address) line and no literal `{placeholder}`.

### Scenario 3: footer and registration

Given scenario 1
Then the footer links Privacidad and Cookies, and does not link Aviso legal
And `/register` shows the first layer naming the controller with a link to the policy
And `/register` has no "condiciones de uso" link and no sentence about accepting them
And `/aviso-legal` shows the existing "Página en preparación" notice.

### Scenario 4: every field set (future)

Given name, tax id, address and a valid email are set
Then both flags are true and the owner object holds every field (registry only when set)
And the footer links both pages, the register first layer includes the terms link, and the notice shows the NIF and the address (email still without `mailto:`).

### Scenario 5: incomplete or invalid data leaks nothing

Given the name or the email is missing, blank or whitespace, or the email fails `FILTER_VALIDATE_EMAIL`
Then both flags are false and `owner` is null, even when a tax id or an address is configured
And no configured value appears anywhere in the response body.
Given name and email are valid and only one of tax id or address is set
Then privacy is published, the notice is not, and the configured tax id or address is absent from the body.

### Scenario 6: anti-bot and logs in production

Given the release is deployed
Then `/robots.txt` contains `Disallow: /api/legal`
And Laravel writes `storage/logs/laravel-YYYY-MM-DD.log` with mode `0660` and group `www-data`
And daily log files older than 14 days are deleted by the scheduled `logs:prune`.

## Repository Research

### Files Inspected

- `AGENTS.md`, `CONSTRAINTS.md` (no legal section; retention rules at lines 165, 192 and 220), `ARCHITECTURE.md` (no legal section; `## Configuration`), the top of `PROGRESS.md`, `feature_list.json`, `docs/specs/legal-compliance-eu.md`.
- `config/legal.php`: owner from `LEGAL_*`; `updated_at` is `2026-10-17`; `server_log_retention_days` comes from `LOG_DAILY_DAYS`.
- `app/Http/Controllers/LegalController.php`: one gate over the four required fields; returns `published` and `owner` (or null).
- `routes/api.php:41`, `tests/Feature/LegalInfoTest.php` (5 tests built on `published`).
- `frontend/src/pages/LegalPage.tsx`: the `NEEDS_OWNER` gate; `placeholderValues` builds a `mailto:` anchor for `{ownerEmail}`.
- `frontend/src/components/AppFooter.tsx`: one `published` flag for both links.
- `frontend/src/pages/RegisterPage.tsx:133-153`: shows the first layer when `owner` is set (not `published`), with `{policy}` and a `{terms}` link to `/aviso-legal`.
- `frontend/src/lib/api.ts:986-1018` (`LegalOwner`, `LegalInfo`), `frontend/src/legal/useLegalInfo.ts`, `frontend/src/i18n/Interpolate.tsx`: an unknown key stays as literal `{key}` text.
- `frontend/src/i18n/legal/{es,en,types}.ts`: the privacy controller block has NIF and address items at lines 17-18. `{ownerEmail}` appears in privacy "Tus derechos" and in the notice. The cookie policy uses no owner placeholders.
- `frontend/src/i18n/messages/{es,en}.ts` (`auth.registerPrivacy*`, `legal.pending*`, `footer.*`), `frontend/src/pages/LegalPage.test.tsx`.
- `frontend/public/robots.txt` (`Allow: /` only), `frontend/public/sitemap.xml` (`/` only), `deploy/nginx.conf` (`robots.txt` is served from `frontend/dist`).
- `config/logging.php`: `stack` uses `LOG_STACK` (default `single`); `daily` has `days` but no `permission`. `.env.example` has `LOG_STACK=single` and empty `LEGAL_*`.
- Vendor: Monolog 3.12 `StreamHandler` runs `@chmod($url, $filePermission)` after `fopen`. Laravel `createRotatingDriver` passes `$config['permission']`. `RotatingFileHandler` keeps `days` **files**, not days, and deletes only `laravel-YYYY-MM-DD.log` names.
- `deploy/configure-fundamentals-env.py`, `deploy/configure-ai-env.py`, `deploy/deploy.sh` (`umask 027`; artisan runs as `deploy`), `deploy/release-permissions.sh`, `deploy/setup-server.sh` (`shared/storage` dirs `2770 deploy:www-data`, cron `schedule:run` as `www-data`), `deploy/alphapulse-queue.service` (`www-data`), `deploy/remediate-permissions.sh`, `deploy/migrate-to-postgresql.sh`, `deploy/README.md`, `.github/workflows/ci-deploy.yml`.
- `routes/console.php:83-91` (prune schedules), `app/Console/Commands/PruneNotifications.php`, `tests/Feature/AdminUsersSessionsTest.php:338` (`schedule:list` assertion pattern).

### Existing Patterns To Follow

- Server-side env scripts: `build_env()` drops existing lines for the managed keys and appends the pinned values. The script writes atomically through `tempfile` + `os.replace`, preserves mode and owner, prints nothing sensitive, and runs as a CI step before `deploy.sh` caches the config.
- Retention prune commands: a `*:prune` command plus a named `Schedule::command(...)->dailyAt()->withoutOverlapping()` under "Personal-data retention"; tests use `schedule:list`.
- Legal texts are typed catalogs; `messages/es.ts` defines the shape and `en.ts` must match. Owner data and retention values are rendered from `/api/legal`, never hardcoded.

### Current Gaps

- There is one gate, so privacy cannot be published without the NIF and the address.
- The privacy catalog hardcodes NIF and address lines. Without a value, `Interpolate` would render the literal placeholder.
- The registration first layer always links "condiciones de uso" to `/aviso-legal`.
- Production uses one unrotated `laravel.log`, and the `daily` channel does not set a shared-writable file mode. Files created by `deploy` (umask 027 → `0640`) could not be written by `www-data`.
- `RotatingFileHandler` keeps 14 files, not 14 days. A quiet period lets older files survive, so the 14-day claim needs an explicit prune.
- Besides the two SQLite locations the owner named, `deploy/remediate-permissions.sh:101` also wrote `/root/chartiko-security-*/database.before.sqlite`. This is another legacy SQLite copy with personal data.

## Technical Approach

**API (`LegalController`).** Read and trim the five fields; a blank value becomes `null`.

- `privacy_published = name && email && filter_var(email, FILTER_VALIDATE_EMAIL)`.
- `notice_published = privacy_published && tax_id && address`.
- `owner` is `null` when privacy is not published. Otherwise it is `{name, email, tax_id, address, registry}`, where `tax_id`, `address` and `registry` are non-null only when `notice_published`.
- **Remove `published`** and migrate every consumer: the controller, `LegalInfoTest`, `api.ts`, `AppFooter`, `LegalPage`, `RegisterPage` and `LegalPage.test`. No other consumer exists; a grep found none in deploy, scripts or the engine. A browser still running an old bundle reads `published` as undefined and withholds the pages, which is the safe failure.
- `updated_at` and `retention` are unchanged.

**SPA.**

- `LegalInfo` becomes `{privacy_published, notice_published, owner: LegalOwner | null, …}`, with `LegalOwner.tax_id`, `address` and `registry` typed `string | null`.
- `LegalPage` replaces `NEEDS_OWNER` with a per-kind check:
  - privacy: `info.privacy_published`;
  - notice: `info.notice_published`;
  - cookies: always shown.
- `placeholderValues` sets `ownerTaxId` and `ownerAddress` only when they are non-null. `ownerEmail` becomes `<span translate="no" className="font-bold text-on-surface break-all">{email}</span>`: ordinary text, read normally by screen readers, selectable and copyable, with no `mailto:`, no link, no JavaScript tricks and no aria hacks.
- `AppFooter` shows the Privacidad link when `privacy_published` and the Aviso legal link when `notice_published`.
- `RegisterPage` keys the first layer on `privacy_published`:
  - with `notice_published`, it keeps `auth.registerPrivacy` (`{owner}`, `{policy}`, `{terms}`);
  - otherwise it uses a new `auth.registerPrivacyNoTerms` (es/en), the same text without the terms clause.
  - es: `Responsable: {owner}. Finalidad: crear y gestionar tu cuenta. Base jurídica: ejecución del contrato. Destinatarios: no se ceden datos salvo obligación legal. Derechos: acceso, rectificación, supresión y otros, como explica la {policy}. Al crear la cuenta declaras tener al menos 14 años.`
  - en: the matching translation of `registerPrivacy` without "accept the {terms} and".
  - With neither flag, `registerPrivacyBasic` is unchanged.

**Catalogs.**

- Delete the `NIF: {ownerTaxId}` and `Domicilio: {ownerAddress}` items from `legalEs.privacy` and the `Tax ID (NIF)` and `Address` items from `legalEn.privacy`. The privacy policy needs no NIF or address, even later.
- The notice catalog is unchanged; it renders only when every field exists.
- Update the `types.ts` doc comment: `{ownerEmail}` is plain text, and `{ownerTaxId}`/`{ownerAddress}` are notice-only.
- `config/legal.php`: rewrite the header comment for the split gate and set `updated_at` to the production publication date, the day the PR merges (expected `2026-10-18`; bump it if the merge slips).

**robots.txt.** Add `Disallow: /api/legal` under `User-agent: *` and keep `Allow: /` and `Sitemap`. Residual risk: a crawler that runs JavaScript still sees the rendered page. Accepted.

**Logging.**

- In `config/logging.php`, add `'permission' => 0660` to the `daily` channel. The creating process chmods the new file; the SGID `2770 deploy:www-data` directory supplies group `www-data`. Both users can then append, and both can unlink during rotation or prune.
- New `app/Console/Commands/PruneServerLogs.php` (`logs:prune`):
  - deletes only files matching `laravel-YYYY-MM-DD.log` in `dirname(config('logging.channels.daily.path'))` whose filename date is `<= today(UTC) - config('legal.server_log_retention_days')` days (`max(1, …)`);
  - prints the count;
  - never touches `laravel.log` or other files.
- Schedule it in `routes/console.php` as `prune-server-logs`, `dailyAt('03:25')`, `withoutOverlapping()`, and extend the retention comment.

**Deploy script.** New `deploy/configure-legal-env.py`, a copy of the `configure-fundamentals-env.py` contract (no secret, no stdin, prints one confirmation line). It pins:

```
LEGAL_OWNER_NAME="Roberto Rodríguez"
LEGAL_CONTACT_EMAIL=contacto@chartiko.com
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=14
```

- The name is double-quoted because it contains a space. UTF-8 is written with `encoding='utf-8'`; phpdotenv reads UTF-8 values unchanged.
- The script does not touch `LEGAL_OWNER_TAX_ID`, `LEGAL_OWNER_ADDRESS` or `LEGAL_OWNER_REGISTRY`. The post-deploy check proves the notice is still unpublished.
- Add a `ci-deploy.yml` step, "Configure legal publication and log retention in private server environment", after the SEC step and before "Activate release".

**Pre-publication operations: DONE by the orchestrator on 2026-10-18, before any publication.**

- Pre-check: production `DB_CONNECTION=pgsql`; daily PostgreSQL dumps exist for 2026-10-16, 17 and 18 (14-day retention).
- Deleted exactly these five files holding personal data:
  - `/var/www/alphapulse/shared/database/database.sqlite`
  - `/root/chartiko-pgsql-cutover-20261006T183353Z/database.before.sqlite`
  - `/root/chartiko-pgsql-cutover-20261006T184407Z/database.before.sqlite`
  - `/root/chartiko-security-20261004T153313Z/database.before.sqlite`
  - `/home/deploy/chartiko-pre-release-20261004T154604Z/database.sqlite`
- Afterwards no other `*.sqlite` was found on the filesystem, and `/up` and `/api/screener` returned 200.
- The cut-over, security and pre-release **directories are kept**: they now hold only config, `.env` and permission backups, no personal data. (This replaces the planned deletion of the whole `/root/chartiko-pgsql-cutover-*` directories.)
- Consequence: rollback to SQLite is no longer possible; `deploy/README.md`, `docs/risks-and-open-questions.md`, `docs/specs/db-postgresql-migration.md` and `docs/specs/legal-compliance-eu.md` record it.

**Post-deploy (orchestrator decision: accepted).** Once a `laravel-YYYY-MM-DD.log` exists, delete the legacy `shared/storage/logs/laravel.log`. It holds unrotated entries since 2026-10-04, and keeping it would make the published 14-day claim false. Record its size and date range before deleting. This is an operational step for the orchestrator, not code; the commands are in "Implementation Findings".

## Expected File Changes

- `app/Http/Controllers/LegalController.php`: modify; split gate, no `published`.
- `config/legal.php`: modify; comment and `updated_at`.
- `config/logging.php`: modify; `daily.permission => 0660`.
- `app/Console/Commands/PruneServerLogs.php`: create. `routes/console.php`: modify; schedule.
- `tests/Feature/LegalInfoTest.php`: rewrite. `tests/Feature/PruneServerLogsTest.php`: create.
- `frontend/src/lib/api.ts`, `frontend/src/pages/LegalPage.tsx`, `frontend/src/components/AppFooter.tsx`, `frontend/src/pages/RegisterPage.tsx`: modify.
- `frontend/src/i18n/legal/{es,en,types}.ts`, `frontend/src/i18n/messages/{es,en}.ts`: modify.
- `frontend/src/pages/LegalPage.test.tsx`: modify. `frontend/public/robots.txt`: modify.
- `deploy/configure-legal-env.py`: create. `.github/workflows/ci-deploy.yml`: modify.
- Docs per the next sections, plus `feature_list.json` and `PROGRESS.md`.

## Visual Design Impact

- UI involved: yes, with existing components only. Design source: `DESIGN.md`, following the current `LegalPage` and footer styling.
- States: privacy page published (es/en); notice pending card; footer with two links; register first layer without terms. The email is inline bold text that wraps at 390 px (`break-all`). No new design artifact is needed.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update.
  - Add a short "Legal pages" section: `GET /api/legal` contract with `privacy_published`/`notice_published`, owner nulling rules, the per-page gate and no `mailto:`.
  - Add `LEGAL_*` and `LOG_STACK`/`LOG_DAILY_DAYS`/`logs:prune` to `## Configuration`.
- `CONSTRAINTS.md`: update with a new "Legal Pages" section:
  - owner data comes only from config;
  - no field is serialized unless the page that needs it is published;
  - the privacy gate is name + valid email; the notice gate adds tax id + address;
  - the email MUST NOT be a `mailto:` link;
  - `/api/legal` MUST stay disallowed in `robots.txt`;
  - daily log files MUST be `0660` and pruned at `legal.server_log_retention_days`.
- `AGENTS.md`: not needed; no change to workflow or startup.
- `deploy/README.md`: update.
  - Step 3: `LEGAL_OWNER_NAME`, `LEGAL_CONTACT_EMAIL` and `LOG_*` are now set by `configure-legal-env.py`; NIF and address are set by hand only to publish the notice.
  - Step 4: list the script.
  - Replace the "borra la SQLite de rollback" paragraph with a dated record of the deletion.
  - Migration steps 6 and "Rollback manual": the SQLite rollback was retired on <date>, so there is no way back to SQLite.
  - `remediate-permissions.sh`: mark as historical (SQLite era).
  - Add a logs note: daily files `0660`, `logs:prune`, legacy `laravel.log` removed.
- `docs/risks-and-open-questions.md`: update.
  - Privacy entry (line 10): privacy published; notice pending NIF/address; review still pending; registration first layer without terms.
  - PostgreSQL entry (line 7): SQLite rollback deleted, no rollback to SQLite.
- `docs/specs/legal-compliance-eu.md`: update. Add a dated note that the gate was split by this spec, and mark the SQLite and log findings as resolved.
- `docs/specs/db-postgresql-migration.md`: add a one-line dated note that the 30-day SQLite rollback source was deleted early by owner decision.
- Not changed, with reasons:
  - `migrate-to-postgresql.sh` and `remediate-permissions.sh`: one-off historical scripts.
  - `setup-server.sh` `touch database.sqlite`: creates an empty file on a new server; harmless.

## Implementation Plan

1. API split gate and `LegalInfoTest`.
2. SPA types, gate logic, email rendering, footer, register variant, catalogs and messages; Vitest.
3. robots.txt, logging permission, `logs:prune` with its schedule and test.
4. `configure-legal-env.py` and the CI step; local verification against phpdotenv.
5. `updated_at`, durable docs, `.\init.ps1`, browser check, PR.
6. Orchestrator: pre-publication deletions with evidence, then merge and deploy, post-deploy checks, legacy `laravel.log` removal, and the PROGRESS and `feature_list.json` evidence.

## Implementation Tasks

- [x] Rewrite `LegalController::show` and `owner()` for the two flags and the per-flag owner fields; drop `published`.
- [x] Rewrite `LegalInfoTest` to cover:
  - nothing set;
  - name + email only;
  - name + email + tax id only (tax id absent from the body);
  - everything set, with and without registry;
  - missing or blank name or email while tax id and address are set (nothing leaks);
  - an invalid email;
  - trimming;
  - `assertJsonMissingPath('published')`;
  - the existing retention and public tests.
- [x] Update `LegalInfo`/`LegalOwner` in `api.ts`, the `LegalPage` gate and `placeholderValues` (plain-text email), `AppFooter` and `RegisterPage` (`registerPrivacyNoTerms`).
- [x] Remove the NIF and address items from both privacy catalogs; update the `types.ts` comment; add `auth.registerPrivacyNoTerms` in es and en.
- [x] Rework `LegalPage.test.tsx` around three fixtures: none, privacy-only, all. Assert scenarios 2-4 in es and en, including:
  - `document.querySelector('a[href^="mailto:"]') === null`;
  - no `{\w+}`;
  - no `NIF|Domicilio|Tax ID|Address` on privacy-only;
  - footer links per flag;
  - register links per flag.
- [x] Add `Disallow: /api/legal` to `frontend/public/robots.txt`.
- [x] Add `'permission' => 0660` to the `daily` channel; create `logs:prune`; schedule `prune-server-logs`.
- [x] Write `PruneServerLogsTest`:
  - use `Carbon::setTestNow`;
  - point `logging.channels.daily.path` at a temp dir;
  - files dated today-14 and older are deleted;
  - today-13 and today are kept;
  - `laravel.log` and `other.log` are untouched;
  - `schedule:list` contains `logs:prune`;
  - `config('logging.channels.daily.permission') === 0660`.
- [x] Create `deploy/configure-legal-env.py` and add the CI step after the SEC step.
- [x] Set `config/legal.php` `updated_at` and update the header comment.
- [x] Update the durable docs listed above. Leave the SQLite-deletion date as a placeholder for the orchestrator only where the deletion has not yet happened when the PR is written.

## Verification Plan

- `php artisan test --filter "LegalInfoTest|PruneServerLogsTest"`, then `npm --prefix frontend run test -- LegalPage`, then `.\init.ps1` (exit 0).
- CI passes on SQLite and on PostgreSQL. The test suite does not depend on the database driver.
- Built file: after `npm --prefix frontend run build`, `frontend/dist/robots.txt` contains `Disallow: /api/legal`.
- Deploy script, checked locally:
  - load the module with `importlib`;
  - run `build_env()` on a sample that has `LOG_STACK=single`, `LEGAL_OWNER_NAME=` and `LOG_CHANNEL=single`: each managed key appears exactly once with the pinned value, and other lines are kept;
  - write that output to a scratch file and parse it with `php -r 'require "vendor/autoload.php"; var_export(Dotenv\Dotenv::parse(file_get_contents($argv[1])));' <file>`;
  - `LEGAL_OWNER_NAME` must be exactly `Roberto Rodríguez` (UTF-8, no quotes), `LOG_STACK` must be `daily` and `LOG_DAILY_DAYS` must be `14`.
- Browser check: a local stack with `LEGAL_OWNER_NAME`/`LEGAL_CONTACT_EMAIL` set only in the uncommitted `.env`, at 1280 px and at 390 px.
  - `/privacidad` in es and en, footer included;
  - `/aviso-legal` shows pending;
  - `/register` first layer;
  - `/cookies` unchanged;
  - no horizontal overflow; the email is selectable text.
  - Screenshots go in the scratchpad.
- E2E: the repo has no persistent E2E command; Vitest plus the browser check cover the flow.
- Post-deploy, by the orchestrator:
  - `curl -s https://www.chartiko.com/api/legal`: `privacy_published:true`, `notice_published:false`, `owner.tax_id`/`address` null, no `published`;
  - `curl -s https://www.chartiko.com/robots.txt` shows the `Disallow` line;
  - `/privacidad` renders in a real browser with the name and the email and no `mailto:`; `/aviso-legal` is pending;
  - on the VPS as `deploy`: `php artisan config:show logging.channels.stack` (`daily`) and `stat -c '%a %U %G %n' shared/storage/logs/laravel-*.log`, expecting `660 … www-data`;
  - `php artisan schedule:list | grep logs:prune`;
  - read-only check that `/etc/logrotate.d/nginx` rotates daily and keeps at most 14. If it keeps more, report it to the owner before calling the claim true.

## Evidence To Capture

- Test counts from `.\init.ps1`, CI run id with both jobs green, and the phpdotenv parse output (values only for the legal and log keys).
- Browser screenshots (paths) and the result of the overflow check.
- SQLite inventory before and after (paths, sizes, dates, UTC time, operator), and confirmation that `database.default` was `pgsql`.
- Live `/api/legal` JSON, the `robots.txt` line, the `stat` of the first daily log, and the legacy `laravel.log` size and range before deletion.
- PROGRESS entry and `feature_list.json` evidence.

## Validator Checklist

- [ ] No `published` key and no unpublished or unset owner value in any API response; `LegalInfoTest` covers every combination.
- [ ] Privacy renders without NIF or address lines or placeholders, the email is not a link, and the footer and register follow their flags; es/en parity.
- [ ] Notice withheld in production; cookie policy unchanged.
- [ ] `robots.txt`, `configure-legal-env.py` (verified with phpdotenv), CI step, `daily` `0660`, and `logs:prune` scheduled and tested.
- [ ] SQLite deletion and legacy log deletion recorded with evidence; live checks recorded.
- [ ] Durable docs updated as listed; `.\init.ps1` and CI green; no scope beyond this spec.

## Implementation Findings (2026-10-18)

- **API shape.** `GET /api/legal` → `{privacy_published, notice_published, owner: null | {name, email, tax_id, address, registry}, updated_at, retention}`. Fields are trimmed and blank → null; `tax_id`, `address` and `registry` are non-null only when `notice_published`. `published` is gone; a stale SPA bundle reads it as undefined and withholds both pages (covered by a Vitest case).
- **English register variant.** `auth.registerPrivacyNoTerms` (en) says "Legal basis: performance of the contract" instead of the inherited "performance of the terms of use", so the sentence names no unpublished terms; this matches the Spanish "ejecución del contrato". `registerPrivacy` is unchanged.
- **Wording left as is (non-goal, for the legal review).** The privacy policy still says the contract-of-use legal basis is "accepted when registering" (es "que aceptas al registrarte"); with the terms unpublished the form no longer states that acceptance. Not changed because this spec forbids other text changes; listed in the risks file for the professional review.
- **`.env.example`.** Its `LEGAL_*` comment described the old single gate; it now describes the split gate and the deploy script. Values stay empty.
- **`logs:prune`.** Uses the date in the filename against `today(UTC) - days` (`max(1, days)`), only for `<base>-YYYY-MM-DD.<ext>` next to `logging.channels.daily.path`; impossible dates (e.g. `2026-02-31`) are skipped; exits 1 only if a matching file cannot be deleted. Monolog names daily files by the app timezone, which `CONSTRAINTS.md` pins to UTC.
- **Dotenv check.** `build_env()` on a sample with `LOG_CHANNEL=single`, `LOG_STACK=single`, empty `LEGAL_*`: each managed key exactly once, other lines (including empty `LEGAL_OWNER_TAX_ID/ADDRESS/REGISTRY`) kept, idempotent. `Dotenv\Dotenv::parse` gives `LEGAL_OWNER_NAME='Roberto Rodríguez'` (UTF-8 bytes `…72c3ad…`), `LEGAL_CONTACT_EMAIL='contacto@chartiko.com'`, `LOG_CHANNEL='stack'`, `LOG_STACK='daily'`, `LOG_DAILY_DAYS='14'`. Booting the real Laravel app on the generated file gives `logging.default=stack`, `stack.channels=["daily"]`, `daily.days=14`, `daily.permission=0660`, and the controller returns `privacy_published:true, notice_published:false` with the owner name and email and null tax id/address/registry; a daily `laravel-2026-10-18.log` was written.
- **Verification.** `.\init.ps1` exit 0: Laravel 455 passed / 6 skipped (3528 assertions; +10 = `LegalInfoTest` 10 and `PruneServerLogsTest` 5 replacing the old 5), Vitest 169 in 22 files (`LegalPage.test.tsx` 13, was 8), SPA lint + build (183 modules; `frontend/dist/robots.txt` has `Disallow: /api/legal`), engine 67 passed.
- **Browser check (Edge via Playwright MCP, local stack: Laravel 8000 + Vite 5173, throwaway SQLite in the scratchpad, name/email only in the uncommitted `.env`).** `/api/legal` through the proxy: privacy true, notice false, tax id/address/registry null. `/privacidad` es at 1280 and 390 px: "Responsable: Roberto Rodríguez", email in the controller block and in "Tus derechos" as plain `<span translate="no">` (selectable, `user-select: auto`), 0 `mailto:` links, no NIF/Domicilio, no `{placeholder}`, footer Privacidad + Cookies, "Última actualización: 18 oct 2026", 0 px overflow (the email wraps inside the column at 390 px). en at 390 px: "Controller: …", "by writing to contacto@chartiko.com", no Tax ID/Address, footer Privacy + Cookies. `/aviso-legal`: "Página en preparación", no owner data. `/register` es 1280 and en 390: controller named, one link (policy → `/privacidad`), no terms clause, 0 overflow. `/cookies` unchanged (120 minutos ×2, "Hasta que lo borres"). Only console errors: the expected visitor `401` on `/api/user`. Servers stopped, ports 8000/5173 free, QA database deleted.
- **Post-deploy steps for the orchestrator** (VPS, as `deploy` in `/var/www/alphapulse/current` unless noted):
  1. `curl -s https://www.chartiko.com/api/legal` → `privacy_published:true`, `notice_published:false`, `owner.tax_id`/`address`/`registry` null, no `published` key.
  2. `curl -s https://www.chartiko.com/robots.txt | grep 'Disallow: /api/legal'`.
  3. `php artisan config:show logging.channels.stack` (channels `daily`), `php artisan config:show legal.owner` (only name and email set), `php artisan schedule:list | grep logs:prune`.
  4. Wait for the first daily file (any logged event creates it), then `stat -c '%a %U %G %n' /var/www/alphapulse/shared/storage/logs/laravel-*.log` → `660 <deploy|www-data> www-data`.
  5. Legacy log, only once step 4 shows a daily file: `L=/var/www/alphapulse/shared/storage/logs/laravel.log; stat -c '%s bytes, modified %y' "$L"; head -c 4096 "$L" | grep -m1 -o '^\[[0-9-]* [0-9:]*\]'; tail -c 65536 "$L" | grep -o '^\[[0-9-]* [0-9:]*\]' | tail -1` (records size and first/last entry date without printing content), then `rm -- "$L"` (as `deploy`, or root if the owner differs) and `ls -l /var/www/alphapulse/shared/storage/logs/`.
  6. Read-only: `grep -E 'daily|rotate' /etc/logrotate.d/nginx` → daily, `rotate 14` or fewer; report to the owner if more.
  7. Real browser: `/privacidad` shows "Responsable: Roberto Rodríguez" and the email as text, no `mailto:`; `/aviso-legal` shows "Página en preparación".
