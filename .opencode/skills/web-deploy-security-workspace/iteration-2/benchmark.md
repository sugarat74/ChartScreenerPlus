# Web-deploy-security — iteration 2 benchmark

Graded four existing offline reports against the **nine updated expectations per scenario** in `.opencode/skills/web-deploy-security/evals/evals.json`. Metadata copies the exact source IDs, prompts and expectations (under `assertions`); names retain the established eval-directory names because the source entries have no name field. The benchmark was assembled manually for the flat run layout using the standard schema's field names.

## Actual assertion scores

| Scenario | With skill | Without skill | Failed without-skill expectations |
|---|---:|---:|---|
| 1 — incomplete evidence | 9/9 (100%) | 7/9 (77.7778%) | 8: unsupported listener protocol; 9: counted full-family coverage |
| 2 — confirmed exposure | 9/9 (100%) | 7/9 (77.7778%) | 6: reporting structure; 9: counted full-family coverage |
| **Aggregate** | **18/18 (100%)** | **14/18 (77.7778%)** | **4 failed expectation instances** |

**Exact difference:** `2/9` in pass rate, or **22.2222 percentage points**. Each configuration has two scenario scores. Their means equal the pooled rates because each scenario has nine expectations. Sample standard deviation across the two scenario scores is `0` for both configurations; this does **not** estimate repeated-run stability.

## Independent counts and semantic-state verification

All 34 checklist parent IDs appear in each with-skill matrix. There are no missing, unexpected or duplicate IDs after expanding the specified subcontrols.

| With-skill report | Rows | Validated | Failed | Unverified | N/A | Split controls |
|---|---:|---:|---:|---:|---:|---|
| Eval 1 | 36 | 2 | 0 | 34 | 0 | FILE-02a/b, AUTH-03a/b |
| Eval 2 | 36 | 1 | 5 | 30 | 0 | FILE-01a/b, TLS-01a/b |

Family row counts, in NET / FILE / TLS / HTTP / AUTH / APP / SUPPLY / OPS order:

- Eval 1: **4 / 6 / 4 / 4 / 6 / 4 / 3 / 5**.
- Eval 2: **4 / 6 / 5 / 4 / 5 / 4 / 3 / 5**.

The pending tables cover exactly the 34 and 30 unverified IDs, respectively. These checks were computed from IDs/statuses independently transcribed from dedicated reads of the reports and checklist; no deployment inspection was performed.

Semantic checks:

- **Eval 1:** FILE-02a validates only absence of private content in the supplied SPA response. AUTH-03a validates only supplied session-cookie flags. Other file coverage, session adequacy/lifecycle and actual CSRF enforcement remain unverified. Listener identity/protocol, external exposure, TLS and runtime debug are not inferred.
- **Eval 2:** FILE-01a correctly **fails** the public-artifacts-only outcome; FILE-01b leaves root/routing/cause **unverified**. FILE-02 also fails. NET-02 and APP-03 fail for public unauthenticated calculation, and AUTH-01 fails for Admin authorization. Those five failed rows correctly consolidate to three findings: one critical and two high. Only the certificate subcontrol is validated; recovery and dependency auditing remain unverified.
- **Without skill:** neither report publishes control/status totals or a full-family matrix, so there is no numerical summary to reconcile. This is missing coverage rather than a demonstrated arithmetic error. Eval 2 still treats private-file serving as a confirmed failure while proposing later root/routing investigation; expectation 8 therefore passes semantically without requiring a named FILE-01 row.

## Remaining defects

1. **Eval 1, without skill:** `report.md:26` states `8090/TCP`, although the supplied listener evidence does not specify a transport protocol. The label is not hypothetical.
2. **Both without-skill reports:** no published control totals or complete family-level coverage matrix. In particular, HTTP-family controls such as CORS/cache are not represented. Eval 1's matrix also lacks SUPPLY/OPS rows, despite a later prose mention of dependencies and recovery.
3. **Eval 2, without skill:** the combined surface table is not the requested port/file inventories and developed coverage matrix. Prioritized remediation, closure tests and APP_KEY operational effects are present and received credit under the applicable expectations.
4. **Untested planning gap:** eval 2 without-skill closure tests for calculation and roles do not explicitly require isolated fixtures/synthetic data (`report.md:68-69`). Backup restoration does require isolation. No actual test execution is inferred.

No remaining substantive defect was identified in either with-skill report within the supplied evidence. This is an artifact assessment, not proof of real-system safety or execution compliance.

## Careful comparison with iteration 1

| Comparison basis | Iteration 1 with | Iteration 2 with | Iteration 1 without | Iteration 2 without |
|---|---:|---:|---:|---:|
| Original seven expectations per eval | 14/14 (100%) | 14/14 (100%) | 13/14 (92.8571%) | 13/14 (92.8571%) |
| Each iteration's complete rubric | 14/14 (100%) | 18/18 (100%) | 13/14 (92.8571%) | 14/18 (77.7778%) |

**The shared-rubric scores are unchanged.** The four added expectation instances contribute **4/4 with skill** and **1/4 without skill**. Raw complete-rubric rates have different denominators and measure additional outcomes; the lower baseline percentage is not evidence of regression. Iteration-1 grades were read for comparison but not changed or retrospectively rescored.

Qualitative improvements visible in the with-skill artifacts address the earlier defects: eval 1 no longer asserts FastAPI identity or protocol for the listener and no longer treats CSRF-cookie intent as established; eval 2 separates the failed serving outcome from its unknown cause and updates counts accordingly. These observations do not establish that the skill alone caused the improvements.

## Evaluation limitations

- **Only two scenarios.** They do not establish performance across other deployment-security tasks.
- **One run per scenario/configuration per iteration.** There are no independent repetitions for estimating repeatability.
- **Resumed agents retained previous-run context.** Iterations are not fresh, independent trials.
- **Reported expectation exposure:** the confirmed-exposure with-skill agent says it accidentally saw two expectation lines from eval 1 (`report.md:41`). The disclosure is recorded; its extent and claimed later avoidance cannot be independently checked.
- **No full tool transcripts.** Process statements such as no network access, no commands and which resources were read remain report assertions. Passing report-consistency expectations does not prove process compliance.
- **Not a rigorous blinded evaluation.** Context retention, criterion exposure and the tiny sample limit causal attribution and generalization.
- **Unknown durations, tokens, model identities and execution metrics are omitted**, not estimated or filled with zero. The JSON timestamp is benchmark-preparation time in UTC, not an executor timestamp.

## Artifacts

- `eval-1-incomplete-evidence/eval_metadata.json`
- `eval-2-confirmed-exposure/eval_metadata.json`
- `eval-1-incomplete-evidence/with_skill/grading.json`
- `eval-1-incomplete-evidence/without_skill/grading.json`
- `eval-2-confirmed-exposure/with_skill/grading.json`
- `eval-2-confirmed-exposure/without_skill/grading.json`
- `benchmark.json`
- `benchmark.md`

Each grading file preserves the exact expectation text, boolean verdicts, specific evidence, score summary, claim verification and evaluation feedback. Reports and skill files were not modified.
