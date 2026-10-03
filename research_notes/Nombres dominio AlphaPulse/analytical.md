# Analytical, trustworthy names for the EOD technical screener

## Which names are memorable, pronounceable, honest, and extensible?

### Takeaway

`ChartSieve.com` is the strongest overall candidate: it communicates chart-based filtering without implying real-time trading, brokerage, AI, or guaranteed returns. `TickerSieve.com` is the clearest runner-up, while `EquitySieve.com` has the most institutional tone and remains suitable when the Universe expands beyond the S&P 500.

### Cited Findings

- Product fit was evaluated against the actual brief: an EOD-only technical Screener that returns a ranked Candidate list from a Universe, initially the S&P 500 but potentially other universes later; it is an analytical tool rather than financial advice. - [Build brief](../../docs/build-brief.md); [domain glossary](../../CONTEXT.md)
- Scores use a 100-point rubric: product/semantic fit (30), memorability (20), pronunciation/international usability (15), room to expand beyond the S&P 500 (15), and preliminary collision risk (20). The collision component is based on the searches documented below, not a legal opinion.

| Rank | Candidate | Score | Scored rationale | Principal weakness |
|---:|---|---:|---|---|
| 1 | **ChartSieve.com** | **91** | Immediately evokes passing many charts through disciplined criteria; vivid, compact, product-specific, and market-neutral. | "Sieve" is pronounced "siv," so spelling may need reinforcement for some non-native speakers. |
| 2 | **TickerSieve.com** | **88** | Strong stock-screening metaphor; memorable alliteration; works for any ticker-based Universe. | "Ticker" can suggest live tape data unless the EOD positioning is explicit. |
| 3 | **EquitySieve.com** | **86** | Serious analytical tone; directly describes filtering equities; broad enough for multiple indices and countries. | More institutional and less visual than ChartSieve; "equity" can also mean ownership/private equity. |
| 4 | **TechnicalSift.com** | **82** | "Technical" anchors the analysis method and "sift" clearly promises narrowing rather than prediction. | Longer and less explicitly about markets or charts; may be mistaken for general technical/recruiting software. |
| 5 | **ChartCriterion.com** | **80** | Conveys rules-based, disciplined chart evaluation and avoids performance promises. | Singular "criterion" sounds slightly formal and less natural as a spoken brand. |
| 6 | **SignalCriterion.com** | **78** | Closely reflects deterministic Signals and rule-based filtering; analytically credible. | Long, formal, and not obviously a ranked stock Screener without a descriptor. |
| 7 | **ChartRoster.com** | **75** | Suggests a curated list of chart Candidates; short, pronounceable, and flexible across Universes. | "Roster" can imply staff/team scheduling rather than screening or ranking. |
| 8 | **EODLens.com** | **73** | Accurately signals the end-of-day focus and analytical inspection; very compact. | "EOD" is jargon and is awkward to say aloud; it permanently narrows the brand if cadence changes later. |

### Inferences

- **Recommended naming direction:** shortlist `ChartSieve`, `TickerSieve`, and `EquitySieve` for user testing. These have the clearest "large Universe to focused shortlist" metaphor while remaining honest about the product's role. - [Product workflow](../../docs/build-brief.md)
- **Best balance:** `ChartSieve` is more distinctive and visual than descriptive names such as `TechnicalSift`; a descriptor such as "End-of-day technical stock screener" can supply immediate category clarity. - [MVP scope](../../docs/build-brief.md)
- None of the eight names contains "trade," "broker," "alpha," "AI," "real-time," or a return claim, so none inherently promises excluded functionality or guaranteed performance. - [Product non-goals](../../docs/build-brief.md)

### Gaps

- Memorability and pronunciation have not been validated with target users. A short blind recall and read-aloud test with English and Spanish speakers would materially improve confidence.
- Naming scores are reasoned judgments, not survey measurements.

## Is each exact .com absent from the authoritative registry?

### Takeaway

All eight exact `.com` domains returned **HTTP 404 Not Found** from Verisign's `.com` RDAP endpoint on **2026-10-03**, so each appeared unregistered at the time checked. DNS also returned no SOA record for every candidate, but this was used only as secondary corroboration.

### Cited Findings

- RDAP defines HTTP `404` as "not found" when the requested object does not exist; this is the relevant response distinction from a registered-domain response. - [RFC 9083, section 4.3](https://www.rfc-editor.org/rfc/rfc9083.html#section-4.3)
- **ChartSieve.com:** Verisign RDAP `404`; DNS no SOA/error. Checked 2026-10-03. - [Verisign RDAP request](https://rdap.verisign.com/com/v1/domain/CHARTSIEVE.COM)
- **TickerSieve.com:** Verisign RDAP `404`; DNS no SOA/error. Checked 2026-10-03. - [Verisign RDAP request](https://rdap.verisign.com/com/v1/domain/TICKERSIEVE.COM)
- **EquitySieve.com:** Verisign RDAP `404`; DNS no SOA/error. Checked 2026-10-03. - [Verisign RDAP request](https://rdap.verisign.com/com/v1/domain/EQUITYSIEVE.COM)
- **TechnicalSift.com:** Verisign RDAP `404`; DNS no SOA/error. Checked 2026-10-03. - [Verisign RDAP request](https://rdap.verisign.com/com/v1/domain/TECHNICALSIFT.COM)
- **ChartCriterion.com:** Verisign RDAP `404`; DNS no SOA/error. Checked 2026-10-03. - [Verisign RDAP request](https://rdap.verisign.com/com/v1/domain/CHARTCRITERION.COM)
- **SignalCriterion.com:** Verisign RDAP `404`; DNS no SOA/error. Checked 2026-10-03. - [Verisign RDAP request](https://rdap.verisign.com/com/v1/domain/SIGNALCRITERION.COM)
- **ChartRoster.com:** Verisign RDAP `404`; DNS no SOA/error. Checked 2026-10-03. - [Verisign RDAP request](https://rdap.verisign.com/com/v1/domain/CHARTROSTER.COM)
- **EODLens.com:** Verisign RDAP `404`; DNS no SOA/error. Checked 2026-10-03. - [Verisign RDAP request](https://rdap.verisign.com/com/v1/domain/EODLENS.COM)

### Inferences

- The authoritative RDAP result is sufficient to label these domains "appears unregistered," not "reserved" or "secured." Registration can occur at any moment, so the preferred name should be rechecked immediately before purchase.
- The matching DNS failures increase confidence but do not drive the conclusion; an unconfigured registered domain can also lack useful DNS records.

### Gaps

- No registrar checkout was attempted, so registry availability does not establish retail price, registrar eligibility, or successful registration.
- Availability is a point-in-time observation from 2026-10-03 and is not a reservation.

## Are there obvious finance/software brand or trademark collisions?

### Takeaway

No obvious exact-name finance/software brand appeared in the reviewed exact-phrase web results for any finalist, and no candidate appeared in the search engine's indexed pages from the USPTO search host. This is a useful knockout screen only: the USPTO application is JavaScript-driven and did not expose auditable result records to the available text fetcher, so a professional similarity/class clearance search remains necessary before adoption.

### Cited Findings

- Exact-phrase finance/software searches returned irrelevant or generic results rather than an evident exact-name operating brand for [ChartSieve](https://www.bing.com/search?q=%22ChartSieve%22+finance+software&setlang=en-US), [TickerSieve](https://www.bing.com/search?q=%22TickerSieve%22+finance+software&setlang=en-US), [EquitySieve](https://www.bing.com/search?q=%22EquitySieve%22+finance+software&setlang=en-US), and [TechnicalSift](https://www.bing.com/search?q=%22TechnicalSift%22+finance+software&setlang=en-US). Searches reviewed 2026-10-03.
- The same preliminary result applied to [ChartCriterion](https://www.bing.com/search?q=%22ChartCriterion%22+finance+software&setlang=en-US), [SignalCriterion](https://www.bing.com/search?q=%22SignalCriterion%22+finance+software&setlang=en-US), [ChartRoster](https://www.bing.com/search?q=%22ChartRoster%22+finance+software&setlang=en-US), and [EODLens](https://www.bing.com/search?q=%22EODLens%22+finance+software&setlang=en-US). Searches reviewed 2026-10-03.
- Searches restricted to the USPTO trademark-search host did not surface a candidate-name result for the [first four names](https://www.bing.com/search?q=site%3Atmsearch.uspto.gov+%22ChartSieve%22+OR+%22TickerSieve%22+OR+%22EquitySieve%22+OR+%22TechnicalSift%22&setlang=en-US) or the [second four names](https://www.bing.com/search?q=site%3Atmsearch.uspto.gov+%22ChartCriterion%22+OR+%22SignalCriterion%22+OR+%22ChartRoster%22+OR+%22EODLens%22&setlang=en-US). Search-engine indexing is incomplete and is not equivalent to a federal clearance search.
- USPTO advises searching for similar trademarks, not merely exact matches, because confusing similarity can involve appearance, sound, meaning, or commercial impression and related goods/services. - [USPTO: likelihood of confusion](https://www.uspto.gov/trademarks/search/likelihood-confusion)
- USPTO's official search page requires JavaScript in the available text-only retrieval environment, preventing reproducible extraction of live query result records here. - [USPTO Trademark Search](https://tmsearch.uspto.gov/); [USPTO search guidance](https://www.uspto.gov/trademarks/search)

### Inferences

- **Lowest preliminary collision concern:** `ChartSieve`, `TickerSieve`, and `EquitySieve`. Their exact compounds did not yield an evident finance/software brand in the reviewed results, while the unusual "Sieve" construction adds distinctiveness.
- **Moderate semantic/category concern, not a discovered exact collision:** `TechnicalSift` could overlap conceptually with general software/recruiting uses; `ChartRoster` with workforce or scheduling software; `EODLens` with non-financial meanings of EOD; and the "Criterion" names use a common analytical word. These should receive broader phonetic and related-class searches before launch. - [USPTO similarity guidance](https://www.uspto.gov/trademarks/search/likelihood-confusion)
- Domain availability does not imply trademark availability. The final choice should be searched for exact, plural, phonetic, and conceptually similar marks in relevant software and financial-information classes and in target countries. - [USPTO comprehensive clearance guidance](https://www.uspto.gov/trademarks/search/comprehensive-clearance-search-similar-trademarks)

### Gaps

- No attorney-reviewed U.S. or international trademark clearance was performed.
- The official USPTO result set could not be exported or cited record-by-record because its search interface required JavaScript; therefore the notes do **not** claim that no federal application or registration exists.
- Common-law use, company registries, app stores, social handles, and non-U.S. trademark databases were not exhaustively searched.
