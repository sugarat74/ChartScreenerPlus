# Feature Implementation Spec: More chartist patterns (first batch)

## Source Feature

- `id`: `chart-patterns-extended`
- `area`: `engine`
- `depends_on`: `chart-patterns-detect`, `chart-patterns-ui`, `alerts-patterns`, `ai-screener-natural-language`, `how-it-works-manual` (all accepted)
- `status`: implemented 2026-10-21 (planned the same day at the user's explicit request: "realiza la spec de la primera tanda (las 4 faciles)"; implementation requested with "implementa las 4 figuras que decias eran faciles"). See Implementation Findings for deviations.
- `source`: `feature_list.json`

## Goal

Extend the closed Chartist Pattern vocabulary with the four "easy" families: they reuse the geometry and the parameters of the existing detectors, so they add coverage without new kinds of rules (no trendline fitting over swing points). The six new types are:

| Family | New types | Reuses |
| --- | --- | --- |
| Bear flag | `bear_flag` | `bull_flag`, mirrored |
| Pennants | `bull_pennant`, `bear_pennant` | the flag pole and a converging consolidation |
| Triple top / bottom | `triple_top`, `triple_bottom` | `double_top` / `double_bottom` with a third extreme |
| Inverse cup with handle | `inverse_cup_with_handle` | `cup_with_handle`, mirrored |

They go through the whole existing path with no new tables: engine detection, `chart_patterns` rows, the Screener filter, the chart overlay and the text panel, Saved Screeners, Watchlist pattern alerts, the natural-language Screener and the How it works manual, in Spanish and English.

## Non-Goals

- No triangles, wedges, rectangles, head-and-shoulders, rounding bottoms or candlestick patterns (later batches; they need trendline or neckline fitting).
- No price targets, measured moves, stops or probabilities. Each pattern still exposes one horizontal breakout level.
- No change to the four existing types' rules or parameters. The only change to their output is the precedence rule (decision 6), which removes double counting. Tuning stays in `chart-patterns-calibration`.
- No sloped breakout lines (pennant trendlines are not drawn) and no new chart drawing primitives.
- No new alert kinds, no email digest changes, no new Screener filter keys (the `pattern` list just accepts more values).
- No machine learning, no pandas/numpy, no new Python dependencies.

## Job Story

When I screen the S&P 500 for chart formations,
I want bearish continuations, pennants, triple tops/bottoms and inverse cups next to the current four,
so I can find both bullish and bearish setups with the same explainable rules.

## Users And Permissions

Unchanged from `chart-patterns-ui` and `alerts-patterns`:

- Visitors filter by pattern and see patterns on the chart.
- Registered Users also save pattern filters, create Watchlist pattern alerts and use the natural-language Screener.
- Developer/Operator: `patterns:detect`.

## Domain Decisions

1. **Vocabulary.** The new strings are appended after the current four, so the canonical order of stored lists (Saved Screeners, alerts) does not change. The order is: `double_top`, `double_bottom`, `cup_with_handle`, `bull_flag`, `triple_top`, `triple_bottom`, `inverse_cup_with_handle`, `bear_flag`, `bull_pennant`, `bear_pennant`. The longest name has 23 characters, so the `type` column (`string 32`) needs no migration.
2. **Direction.** Every type is either *bullish* (it confirms with a close above the breakout level) or *bearish* (it confirms with a close below it).
   - Bullish: `double_bottom`, `triple_bottom`, `cup_with_handle`, `bull_flag`, `bull_pennant`.
   - Bearish: `double_top`, `triple_top`, `inverse_cup_with_handle`, `bear_flag`, `bear_pennant`.

   The direction is fixed per type. It is defined once in the engine and once in the SPA (`PATTERN_DIRECTION`). It is used only for wording ("above"/"below") and the order of the filter chips, never as advice.
3. **Statuses, window and lifecycle are unchanged:**
   - `forming` or `confirmed`, with a breakout close within the last 10 sessions.
   - One instance per type per instrument, the most recent one.
   - Replace semantics.
   - The 330-bar window, with 60 bars as the minimum.
   - Any `null` input means a pattern cannot confirm; it never produces a false pattern.
4. **Shared parameters.** Each mirrored type uses the *same* named constants as its counterpart (`DOUBLE_*`, `CUP_*`, `HANDLE_*`, `POLE_*`, `FLAG_*`), so the calibration spec tunes both directions at once. Pennants add only `PENNANT_MAX_WIDTH_RATIO` (see Findings 1).
5. **Extreme rule applies to every new type.** A pattern's own extremes are the extremes of its span, as in `chart-patterns-detect` Findings 1:
   - No higher high between the triple-top peaks, and no lower low between the triple-bottom lows.
   - Nothing inside the inverse cup below the lower rim, and no intermediate swing low below the higher rim.
   - No bear-flag or bear-pennant low under the pole bottom before the breakout.
6. **Precedence (no double counting of one formation).** It is applied in `detect.py` after the detectors run:
   - When a `triple_top` is returned, a `double_top` whose two peaks are both among the triple's three peaks is dropped. The same applies to `triple_bottom` and `double_bottom`.
   - When a pennant and a flag of the same direction share the same pole top (or pole bottom), only the pennant is kept, because it is the more specific shape.
   - In both cases the instrument may still carry a different, older instance of the dropped type only if the detector returned it. Detectors still return one instance per type, so the dropped type simply does not appear.
7. **Point roles must be unique per side.** The chart places a marker above the bar for a high role and below it for a low role (`HIGH_ROLES` in `chartData.ts`). New role names are therefore never reused with the opposite side. The new roles are:

   | Role | Side | es (long / short) | en (long / short) |
   | --- | --- | --- | --- |
   | `middle_peak` | high | Techo central / T2 | Middle peak / P2 |
   | `left_trough` | low | Primer valle / V1 | First trough / V1 |
   | `right_trough` | low | Segundo valle / V2 | Second trough / V2 |
   | `middle_low` | low | Suelo central / S2 | Middle low / L2 |
   | `left_crest` | high | Primera cresta / C1 | First crest / C1 |
   | `right_crest` | high | Segunda cresta / C2 | Second crest / C2 |
   | `rim_left_low` | low | Borde izquierdo / B1 | Left rim / R1 |
   | `cup_top` | high | Techo de la taza / T | Cup top / T |
   | `rim_right_low` | low | Borde derecho / B2 | Right rim / R2 |
   | `handle_high` | high | Máximo del asa / A | Handle high / H |
   | `pole_peak` | high | Inicio del mástil / M1 | Pole start / P1 |
   | `pole_bottom` | low | Suelo del mástil / M2 | Pole bottom / P2 |
   | `flag_high` | high | Máximo de la bandera / B | Flag high / F |
   | `pennant_low` | low | Mínimo del banderín / D | Pennant low / N |
   | `pennant_high` | high | Máximo del banderín / D | Pennant high / N |

   In a triple top, the third peak keeps the existing `right_peak` role and the first keeps `left_peak`. Triple bottoms use `left_low`, `middle_low` and `right_low`. The bull pennant reuses `pole_start` and `pole_top`. Short labels follow the style of the existing `rolesShort`; the implementer may adjust them if two labels collide on the same pattern.

### Pattern rule definitions (fixed contract, initial parameters)

All percentages are relative to the referenced price, and `i` is the as-of index. "Confirmed" means within the last 10 sessions, as for the existing types.

| Type | Geometry | Breakout level | Confirmed when | Invalidated when |
| --- | --- | --- | --- | --- |
| `triple_top` | Swing highs `H1` (t1) < `H2` (t2) < `H3` (t3); each gap `t2-t1`, `t3-t2` in `[DOUBLE_MIN_GAP, DOUBLE_MAX_GAP]` (15–120); `(max(H)-min(H))/max(H) <= DOUBLE_PEAK_TOLERANCE` (3%); troughs `L1 = min(low[t1..t2])`, `L2 = min(low[t2..t3])`, each at least `DOUBLE_MIN_DEPTH` (10%) below the lower of its two adjacent peaks; prior rise `H1 >= 1.15 * min(low[t1-60 .. t1])`; the calibrated double rules for the first extreme (anchor), each middle point (12–85% of its gap) and every extreme (wick ≤ 8%); `i - t3 <= DOUBLE_MAX_AGE` (60); no high in `[t1, t3]` above `max(H)` | `min(L1, L2)` (lowest trough, horizontal neckline) | a close `< min(L1, L2)` after `t3` | any close `> max(H) * (1 + DOUBLE_INVALIDATION)` after `t3` (0%, calibrated) |
| `triple_bottom` | Mirror on swing lows `L1 < L2 < L3` in index; crests `C1 = max(high[t1..t2])`, `C2 = max(high[t2..t3])`, each at least 10% above the higher of its two adjacent lows; prior decline `L1 <= 0.85 * max(high[t1-60 .. t1])`; same anchor, middle-point and wick rules; no low in `[t1, t3]` below `min(L)` | `max(C1, C2)` | a close `> max(C1, C2)` after `t3` | any close `< min(L) * (1 - DOUBLE_INVALIDATION)` after `t3` |
| `inverse_cup_with_handle` | Left rim swing low `A` (ta), cup top `B = max(high[ta..tc])` at tb, right rim swing low `C` (tc): length `30 <= tc-ta <= 325`; height `12% <= (B-A)/A <= 35%`; `0.95A <= C <= 1.05A`; `tb` in the middle 20–80% of `[ta, tc]`; no low inside below the lower rim and no intermediate swing low below the higher rim. Handle: it lasts 5–25 sessions after `tc` (up to the breakout), its high `D <= A + (B-A)/2`, and `(D-C)/C <= 12%` | pivot `P = min(low[tc..breakout-1])` (C when no handle low is lower) | a close `< P` with `rvol >= 1.5`, after at least 5 handle sessions | handle high rises above `A + (B-A)/2` or more than 12% above `C`, or the handle exceeds 25 sessions without a breakout |
| `bear_flag` | Pole from swing high `S` (ts) to swing low `T` (tt): `tt-ts <= POLE_MAX_LENGTH` (20, calibrated), no low inside the pole under `T`, drop `(S-T)/S >= 15%`; flag of 5–20 sessions after `tt`; flag high `<= T + 0.5*(S-T)`; least-squares slope of the flag closes `>= 0`; mean flag volume `<` mean pole volume; no flag low under `T` before the breakout | `F = min(low` over the flag`)` | a close `< F` after at least 5 flag sessions | flag high `> T + 0.5*(S-T)`, a low under `T`, or the flag lasts more than 20 sessions without a breakout |
| `bull_pennant` | Same pole as `bull_flag` (swing low `S` to swing high `T`, `<= 20` sessions, `>= +15%`, no high inside the pole above `T`); consolidation of 5–20 sessions after `tt` (the pennant bars, up to the breakout) whose second half has a lower highest high **and** a higher lowest low than the first half (converging), with a high-low range of at most `PENNANT_MAX_WIDTH_RATIO` (70%) of the first half's; pennant low `>= T - 0.5*(T-S)`; no pennant high above `T`; mean pennant volume `<` mean pole volume | `max(high` over the pennant`)` | a close `>` it after at least 5 pennant sessions | pennant low under the 50% floor, a high above `T`, or the pennant lasts more than 20 sessions without a breakout |
| `bear_pennant` | Mirror of `bull_pennant` on the `bear_flag` pole (swing high to swing low, `>= 15%` drop): highs slope `< 0`, lows slope `> 0`, the same width rule, pennant high `<= T + 0.5*(S-T)`, no low under `T`, volume lower than the pole | `min(low` over the pennant`)` | a close `<` it after at least 5 pennant sessions | pennant high above the 50% ceiling, a low under `T`, or longer than 20 sessions without a breakout |

**Pennant width (as built).** The pennant bars are split in two halves (the first gets the middle bar when the count is odd). With `U1, L1` the highest high and lowest low of the first half and `U2, L2` those of the second, the pennant is valid only when `U2 < U1`, `L2 > L1` and `(U2 - L2) / (U1 - L1) <= PENNANT_MAX_WIDTH_RATIO` (0.7). The originally planned least-squares lines were dropped (Findings 1).

**Metadata.** Numeric only, as today:
- Triples: `depth_pct` (the shallower of the two), `span_sessions`.
- Inverse cup: `height_pct`, `cup_sessions`, `handle_sessions`.
- Bear flag: `pole_drop_pct`, `flag_sessions`.
- Pennants: `pole_rise_pct` or `pole_drop_pct`, plus `pennant_sessions` and `end_width_ratio`.
- Every type: `breakout_close` when the pattern is confirmed.

## Acceptance Scenarios

### Scenario 1: Each new type is detected from a synthetic series
Given a committed synthetic fixture that draws a textbook instance of each of the six types,
When the engine detects patterns,
Then that type is returned with `status`, `start_date`, `end_date`, `breakout_level` and its key points with the roles listed above,
and the breakout is below the pattern for bearish types and above it for bullish types.

### Scenario 2: Near misses are rejected
Given fixtures that each violate one rule, for example:
- triple peaks 5% apart;
- a triple gap of 10 sessions;
- an inverse cup 40% tall;
- a bear pole of only -10%;
- a bear flag retracing 60%;
- a pennant with parallel (non-converging) lines;
- a pennant whose second half is still more than 70% as wide as its first half;
When the engine detects patterns,
Then that type is absent.

### Scenario 3: Status and invalidation for each new type
Given a forming instance of each new type:
- when a close beyond the breakout level arrives (with `rvol >= 1.5` for the inverse cup), it becomes `confirmed`;
- 11 sessions later it disappears;
- an invalidation close removes it at once.

### Scenario 4: Precedence
Given a series with three equal peaks that also satisfies `double_top` on two of them,
When the engine detects patterns,
Then `triple_top` is returned and that `double_top` is not.
And given a pole followed by a consolidation that satisfies both `bull_flag` and `bull_pennant`, only `bull_pennant` is returned.
The same holds for the bottoms and for the bearish pair.

### Scenario 5: Existing types are unchanged
Given the existing engine fixtures for `double_top`, `double_bottom`, `cup_with_handle` and `bull_flag`,
When the engine detects patterns,
Then every existing test passes unchanged, apart from tests that deliberately check the new precedence rule.

### Scenario 6: Screener, Saved Screener and API
Given stored rows of the new types,
When `GET /api/screener?pattern=bear_flag,triple_top&pattern_status=confirmed` is called,
Then only instruments with a confirmed `bear_flag` or `triple_top` are returned.
A Saved Screener with the new values saves and reloads.
An unknown type still returns 422 with the localized message.

### Scenario 7: Chart and panel
Given an instrument with an active `bear_flag` and a `triple_bottom`,
When a user opens its chart,
Then both are drawn with key-point markers on the correct side of the bar (highs above, lows below) and one breakout line each,
and the text panel lists them with localized type, status, roles and level, in es and en, light and dark themes, at 1280 and 390 px.

### Scenario 8: Filters and alerts UI
Given the Screener filter panel and the Watchlist pattern alert form,
When they render,
Then they offer all ten types, bullish first and then bearish, each group in canonical order.
The chips wrap with no horizontal overflow at 390 px.
A Watchlist alert for `inverse_cup_with_handle` saves and later notifies with the localized label.

### Scenario 9: Natural-language Screener
Given the AI Screener prompt and its strict schema,
When the schema is built,
Then the `pattern` enum lists all ten types and `MAX_PATTERNS` equals the vocabulary length.
The es and en prompts name every type, for example `bear_flag = bandera bajista`, `inverse_cup_with_handle = taza con asa invertida`.

### Scenario 10: How it works
Given `/como-funciona`,
When it renders,
Then the patterns section has an entry for each new type with its rule summary, breakout and invalidation details and a themed schematic illustration,
and the status paragraph no longer singles out the double top. It explains that bearish patterns break below.

## Repository Research

### Files Inspected

- `engine/app/patterns/rules.py`: one detector per type, named parameter constants, `DETECTORS` registry, `_status`, `_pattern`, `_slope`, extreme rules.
- `engine/app/patterns/detect.py`: window, `rvol`, registry loop. This is where the precedence post-filter goes.
- `app/Models/ChartPattern.php` (`TYPES`), which is the single PHP vocabulary. `ScreenerFilterParser::PATTERN_TYPES`, `AlertController`, `SavedScreenerController`, `AlertEvaluator`, `DetectPatterns` and `ScreenerQuerySchema` all derive from it.
- `app/Services/Screener/Ai/ScreenerQueryPrompt.php`: lists the types by name in both languages. `ScreenerQuerySchema::MAX_PATTERNS = 4`.
- Frontend:
  - `frontend/src/lib/api.ts` (`ScreenerPatternType`).
  - `lib/screenerFilters.ts` (canonical order).
  - `lib/chartData.ts` (`HIGH_ROLES`).
  - `components/chart/{InteractiveChart,PatternPanel}.tsx`.
  - `components/screener/ScreenerFilterPanel.tsx`.
  - `components/portal/WatchlistPatternAlertForm.tsx`.
  - `i18n/messages/{es,en}.ts` (`patterns.types`, `roles`, `rolesShort`).
  - `i18n/guide/{es,en,types}.ts`.
  - `components/TechnicalExample.tsx` (illustration kinds, themed with `currentColor` and `var(--color-*)`).
- Docs: `docs/specs/chart-patterns-{detect,ui,calibration}.md`, `alerts-patterns.md`, `CONSTRAINTS.md` ("The vocabulary MUST stay …"), `ARCHITECTURE.md` (Patterns Path), `AGENTS.md` (MVP scope guard).

### Existing Patterns To Follow

- A detector returns the latest qualifying instance or `None`. It scans backwards over swing points with distance windows (no O(n³) scans), finds the breakout and invalidation in one forward loop, and calls `_status` and `_pattern`.
- Offline pytest with synthetic fixture builders; positive, near-miss and invalidation tests per type.
- One PHP constant feeds every validator. The SPA keeps a canonical order and normalizes URL lists.

### Current Gaps

- Only bullish continuation (flag, cup) and the two doubles exist. There is nothing bearish beyond the double top, no pennant and no triple.
- `CONSTRAINTS.md` freezes the vocabulary at four, so the rule must be updated, not bypassed.
- The guide's status paragraph hardcodes "por debajo en el doble techo".

## Technical Approach

1. **Engine (`rules.py`).**
   - Add `triple_top`, `triple_bottom`, `inverse_cup_with_handle`, `bear_flag`, `bull_pennant` and `bear_pennant` as separate functions, reusing the existing constants. The only new constant is `PENNANT_MAX_WIDTH_RATIO = 0.7` (Findings 1).
   - Add a `_width_ratio(body)` helper for the pennant halves (Findings 1).
   - Append the six detectors to `DETECTORS` in vocabulary order.
   - Add `DIRECTION: dict[str, Literal["bullish", "bearish"]]` for documentation and tests. It is not sent over the API.
   - Shared pole and flag loops may be factored into private helpers only if the existing `bull_flag` tests stay unchanged.
2. **Engine (`detect.py`).** After the loop, apply `_apply_precedence(detected)`: triple beats double when they share peaks or lows, and pennant beats flag when they share the pole extreme (compared by the point dates of the shared roles).
3. **Laravel.**
   - Append the six strings to `ChartPattern::TYPES`.
   - Set `ScreenerQuerySchema::MAX_PATTERNS = count(ChartPattern::TYPES)`.
   - Name the new types in both prompt languages in `ScreenerQueryPrompt`.
   - No migration. `DetectPatterns` already validates against `TYPES`.
4. **SPA.**
   - Extend `ScreenerPatternType` and the canonical list in `screenerFilters.ts`.
   - Add `PATTERN_DIRECTION` and a display order (bullish group, then bearish) used by `ScreenerFilterPanel` and `WatchlistPatternAlertForm`. The URL and storage order stay canonical.
   - Extend `HIGH_ROLES` with `middle_peak`, `left_crest`, `right_crest`, `cup_top`, `handle_high`, `pole_peak`, `flag_high` and `pennant_high`.
   - Add i18n `types`, `roles` and `rolesShort` for es and en.
   - The panel wording for the breakout ("por encima"/"por debajo") follows the direction if the panel shows it. Otherwise the panel is unchanged.
5. **How it works.**
   - Add six guide entries in es and en with summaries derived from the rule table.
   - Add six `TechnicalExample` kinds (`tripleTop`, `tripleBottom`, `inverseCupWithHandle`, `bearFlag`, `bullPennant`, `bearPennant`), drawn like the existing schematics with theme tokens.
   - Generalise the status paragraph.

## Expected File Changes

- Engine:
  - `engine/app/patterns/rules.py` and `detect.py` (modify).
  - `engine/tests/test_patterns.py` and the fixture builders (modify or extend). Optionally split into a new `engine/tests/test_patterns_extended.py`.
- Laravel:
  - `app/Models/ChartPattern.php`, `app/Services/Screener/Ai/ScreenerQuerySchema.php` and `ScreenerQueryPrompt.php` (modify).
  - Tests: `tests/Feature/ChartPatternsApiTest.php`, `DetectPatternsCommandTest.php`, `AlertPatternEvaluationTest.php` and `AlertsApiTest.php` (one new-type case each); `tests/Unit/ScreenerQuerySchemaTest.php`; and a Screener filter test for a new type.
- SPA:
  - `frontend/src/lib/{api,screenerFilters,chartData}.ts`.
  - `components/chart/PatternPanel.tsx`, `components/screener/ScreenerFilterPanel.tsx`, `components/portal/WatchlistPatternAlertForm.tsx` and `components/TechnicalExample.tsx`.
  - `i18n/messages/{es,en}.ts` and `i18n/guide/{es,en,types}.ts`.
  - Tests: `patterns.test.ts`, `PatternPanel.test.tsx`, `WatchlistPatternAlertForm.test.tsx` and `TechnicalExample.test.tsx`.
- `deploy/` is not affected. The next daily pipeline (or a manual `patterns:detect` followed by `screener:refresh`) fills the new types.

## Visual Design Impact

- UI involved: yes. The new chips, markers, panel rows and six schematics appear on existing surfaces with the existing components and tokens (`DESIGN.md`).
- Check light and dark themes, es and en, at 1280 and 390 px. Watch for the chip wrap in the filter panel and the alert form.

## Durable Documentation Impact

- `CONSTRAINTS.md`: update the vocabulary rule to the ten types and the bullish/bearish split, the precedence rule, and the shared parameters.
- `ARCHITECTURE.md`: update the Patterns Path (detector list, precedence post-filter).
- `CONTEXT.md`: update the glossary for Chartist Pattern (new types, direction).
- `AGENTS.md`: extend the MVP scope guard line for chartist patterns with this batch (explicit user decision, 2026-10-21).
- `docs/specs/chart-patterns-detect.md`: add a pointer to this spec for the extended vocabulary.
- `docs/specs/chart-patterns-calibration.md`: the review sample and report cover all ten types. Shared constants mean a tuned value applies to both directions.

## Implementation Plan

1. Fixture builders and engine detectors, one family at a time (bear flag, then pennants, then triples, then inverse cup), each with positive, near-miss, invalidation and status tests.
2. Precedence post-filter and tests. Check that the existing tests are green.
3. Laravel vocabulary, AI schema and prompt, and PHP tests.
4. SPA vocabulary, direction, roles and i18n, filter and alert chips, and tests.
5. How it works entries and schematics, with tests.
6. Local smoke: engine plus `patterns:detect --universe=sp500` on the dev DB. Record counts per type and status, and plot one instance per new type to check it by eye.
7. Docs, `.\init.ps1`, evidence, then the validator.

## Implementation Tasks

- [x] `bear_flag`, `bull_pennant` and `bear_pennant` detectors with the pole, retracement, slope, width and volume rules.
- [x] `triple_top` and `triple_bottom` detectors with the gap, tolerance, depth, prior move, age and extreme rules.
- [x] `inverse_cup_with_handle` detector with the handle, the `rvol >= 1.5` breakout and the extreme rules.
- [x] `PENNANT_MAX_WIDTH_RATIO` and `_width_ratio`; `ALL_DETECTORS` in vocabulary order.
- [x] Precedence post-filter in `detect.py` with tests (triple over double, pennant over flag, both directions).
- [x] `ChartPattern::TYPES` extended; `MAX_PATTERNS` derived; AI prompt in es and en.
- [x] Backend tests per surface (screener filter, saved screener, alerts, detect command, AI schema).
- [x] SPA vocabulary, `PATTERN_DIRECTION`, display order, `HIGH_ROLES`, and i18n types, roles and rolesShort in es and en.
- [x] How it works: six entries in es and en, six themed schematics, status paragraph generalised.
- [x] Docs listed above; `PROGRESS.md`, `feature_list.json`.

## Verification Plan

- `engine\.venv\Scripts\python.exe -m pytest -q` and `-m ruff check .`: new tests pass offline and the existing pattern tests are unchanged.
- `php artisan test`: the new-type cases above, with `ScreenerApiTest` and the alert tests green. CI `test-pgsql` passes.
- `npm run lint`, `npm test`, `npm run build` (inside `.\init.ps1`).
- Local smoke on the dev DB (as-of date recorded): counts per type and status before and after, and one plotted instance per new type checked by eye. If a new type exceeds 40% of all detections, record it as input for calibration and do not tune it silently.
- Browser QA (Playwright with Edge, local stack):
  - Filter chips with the new types, at 390 and 1280 px, es and en, light and dark.
  - A chart with a bearish and a bullish new pattern: markers on the correct side and one breakout line each.
  - The alert form with the new chips.
  - How it works with the six new schematics in both themes.
- `.\init.ps1` exits 0.

## Evidence To Capture

- Engine test names per new type (positive, near miss, invalidation, status, precedence).
- PHP and Vitest counts.
- Smoke counts per type and status.
- Browser QA notes.
- `init.ps1` exit 0.

## Key Implementation Risks

- **Calibration debt.** The current parameters are still the initial ones (`chart-patterns-calibration` is `not_started`; its old branch `feat/chart-patterns-calibration` predates many features and should be rebased or redone). The new types inherit the same noise, especially the triples from the frequent double tops. Mitigations: shared constants, precedence, and the smoke counts recorded for calibration. Recommended order: calibrate before or right after this feature. This is not a hard dependency, so the user can choose.
- **Pennant on few bars.** A 5-bar regression is noisy. The width rule and the "highs fall and lows rise" condition must both hold; near-miss tests cover parallel and widening shapes.
- **Flag/pennant overlap.** It is resolved only through the precedence rule. Without it, the Screener would list the same pole twice.
- **Downside cup volume.** The `rvol >= 1.5` requirement mirrors the cup for symmetry. Classic literature stresses volume less on downside breaks; calibration may revisit it.
- **UI density.** Ten chips in two groups must still wrap cleanly at 390 px in both languages.
- **Look-ahead.** The rules are the same as before: swing points need 5 future bars, and nothing reads bars after `i`.
- **Scope.** No targets, no new filter keys, no new alert kinds.

## Validator Checklist

- [ ] Exactly six new types, with the strings and canonical order as specified. No migration. Existing stored lists keep their order.
- [ ] Rules and parameters match the table. Mirrored types reuse the shared constants. Only `PENNANT_MAX_WIDTH_RATIO` is new. No targets.
- [ ] Each new type has positive, near-miss, invalidation and status tests. The extreme rules hold. There is no look-ahead.
- [ ] Precedence is tested both ways. The existing four types' tests are unchanged.
- [ ] Screener, Saved Screener, alerts, the AI Screener (enum and `MAX_PATTERNS`), chart markers and sides, panel, filters and alert chips, and How it works all work with the new types in es and en and in light and dark.
- [ ] Docs updated (`CONSTRAINTS.md` vocabulary rule, `ARCHITECTURE.md`, `CONTEXT.md`, `AGENTS.md`, the calibration spec). Smoke counts recorded. `init.ps1` exits 0. `feature_list.json` and `PROGRESS.md` updated.

## Implementation Findings

Recorded 2026-10-21. Built on top of `chart-patterns-calibration` phase 2, so the mirrored types inherit the calibrated values: invalidation on any close beyond the extreme, the anchor, middle-point and wick rules for the triples, and a 20-session pole.

1. **Pennant convergence rule changed.** The planned least-squares lines on every pennant high and low cannot detect convergence. Daily highs and lows follow the closes, so both fitted lines track the centre of the price and always get the same slope. The detector instead compares the two halves of the consolidation: a lower highest high, a higher lowest low, and a range of at most 70% (`PENNANT_MAX_WIDTH_RATIO`). A perfect triangle that reaches its apex scores about 0.5; one that breaks out at two thirds to three quarters of the way scores about 0.6–0.7. Tests cover parallel channels, widening shapes and a 0.72 near miss.
2. **A pole must end at its own extreme.** A pole running from a low to a later, lower rally top was accepted, with the real top (higher) left inside the pole. The 20-session pole from calibration made this possible for `bull_flag` too. The rule now applies to every pole, `bull_flag` included, behind the `flag_extremes` switch. Test: `test_a_pole_ends_at_its_own_extreme`. Every existing bull flag test still passes, and the universe count of bull flags did not change (4 / 1).
3. **Module layout.** The six detectors live in `engine/app/patterns/extended.py` (`ALL_DETECTORS` = first batch + second batch), so `rules.py` keeps the first four untouched. `detect.py` applies `apply_precedence`. `near_misses.py` scans every type but has relaxations only for the first four.
4. **SPA canonical order.** `SCREENER_PATTERN_TYPES` is now the bullish group, then the bearish one, so a URL lists `cup_with_handle,double_top` in that order. The API and the stored lists keep the backend order of `ChartPattern::TYPES`, and the SPA normalizes both. One assertion in `patterns.test.ts` was updated.
5. **Local smoke** (dev SQLite, 503 instruments, as-of 2026-10-02, about 3.5 s):

| Type | Forming | Confirmed |
| --- | ---: | ---: |
| double_top | 35 | 7 |
| double_bottom | 22 | 5 |
| cup_with_handle | 14 | 1 |
| bull_flag | 3 | 1 |
| triple_top | 12 | 1 |
| triple_bottom | 8 | 0 |
| inverse_cup_with_handle | 51 | 8 |
| bear_flag | 3 | 1 |
| bull_pennant | 1 | 1 |
| bear_pennant | 1 | 1 |
| **total** | | **176** |

   - The triples absorbed 13 double tops and 7 double bottoms through the precedence rule.
   - **The inverse cup with handle is 34% of all detections.** Six random instances were plotted. All are geometrically valid mirrors (a rise and a return to the starting level, with a small upward handle), and some are broad hills rather than rounded tops. The count fits the current regime, a market that rolled over: double tops 42 against double bottoms 27, and only 15 bullish cups. Following the calibration policy, it is not tuned by eye. It is documented as **unlabelled, low confidence** and is the first candidate for the next labelling sample.
   - In some triples the breakout level (the lowest trough or highest crest) lies far from the price, because one middle point was a one-day collapse or spike. This is recorded for the next labelling round.
6. **Persistent E2E.** None exists. The flow is covered by engine, Laravel and Vitest tests plus the browser QA recorded in `PROGRESS.md`.
