# Offline skill evaluation — iteration 1

| Configuration | Eval 1 | Eval 2 | Total |
|---|---:|---:|---:|
| With skill | 7/7 | 7/7 | 14/14 |
| Without skill | 7/7 | 6/7 | 13/14 |

One run per scenario/configuration. No timing or token metrics were provided.
Thirteen assertions also pass without the skill; the measured difference concerns
report structure. This small sample does not demonstrate general detection gains.

Independent grading found issues outside the initial assertions: unknown root cause
was confused with an unverified protection outcome, and a service identity was
inferred from its port. These are follow-ups for the next draft.
