Chartiko - chartist pattern calibration review sample
======================================================

Generated: 2026-10-16 10:55:16 by `php artisan patterns:review-sample`
Universe: sp500   --per-type=40   --negatives=40   --seed=20261016
Re-running with the same seed on the same data and rules gives the same sample.

Contents
--------
- sample.csv       one row per case: id, ticker, type, status, as_of, points, suggested_label, label, notes
- charts/<id>.svg  one chart per case (open in any browser)
- parameters.json  the production rule parameters used (booleans as 1/0)

Cases
-----
- double_top       detected: 27 forming, 13 confirmed; near misses: 10
- double_bottom    detected: 31 forming, 9 confirmed; near misses: 10
- cup_with_handle  detected: 14 forming, 1 confirmed; near misses: 10
- bull_flag        detected: 2 forming, 1 confirmed; near misses: 10

Detected cases (status `forming` or `confirmed`) are what Chartiko currently
shows. Near misses (status `near_miss:<rule>`, ids starting with NM-) are
instruments the rules reject because of exactly ONE rule: with only that
rule slightly relaxed the detector finds the drawn instance. They tell us
whether a rule is too strict. Rules: peak_tolerance 3%->5%, min_depth
10%->7% (cup 12%->8%), max_depth 35%->45%, prior_move 15%->10%, min_gap
15->10, rim_tolerance 5%->8%, low_position 20-80%->10-90%,
handle_max_depth 12%->18%, handle_max_rise 3%->6%, handle_max_length
25->35, breakout_rvol 1.5->1.0, pole_min_rise 15%->10%, pole_max_length
15->20, flag_max_retrace 50%->61.8%, flag_max_length 20->25; extremes,
down_slope and lower_volume switch that structural rule off.

How to label
------------
Open charts/<id>.svg for each row and write ONE value in the `label` column:

  Detected cases:
    clear          a textbook instance a technical analyst would name at once
    acceptable     recognisable, with some imperfection (proportions, noise)
    not_a_pattern  not this pattern (wrong geometry, points do not make sense)

  Near misses (NM-...):
    missed         a real instance of this type that the rules wrongly reject
    not_a_pattern  correctly rejected

Judge only the geometry of the named type (no "would it have made money").
Use `notes` for anything useful (e.g. "handle too deep", "points misplaced").
`suggested_label` may hold a machine pre-label; it is only a suggestion and
is never counted. Leave `label` empty for cases you did not review.

Chart legend: blue line = closes; grey bars = high-low range; grey strip =
volume; red dots = key points with their role and price; dashed orange =
breakout level (the only price line a pattern exposes; no targets).

Then run:  php artisan patterns:calibration-report <path>/sample.csv
