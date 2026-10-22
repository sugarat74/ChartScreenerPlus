# FRED test fixtures (macro-series-fred)

Offline FRED API responses for `tests/Concerns/FakesFred.php`, in the documented
API JSON shape (`seriess[]` for `/fred/series`, `observations[]{realtime_start,
realtime_end, date, value}` for `/fred/series/observations`). No API key was used
to build them, and none appears in them.

- **Real values (public domain, "Public Domain: Citation Requested").**
  `DGS3MO`, `DGS2`, `DGS10`, `DFEDTARL`, `DFEDTARU` (2026-09-15 to 2026-10-22),
  `WALCL`, `WDTGAL`, `WLRRAOL`, `ICSA` (2026-07-01 to 2026-10-22) and `PERMIT`
  (2025-10-01 to 2026-10-22). Observations were copied on 2026-10-22 from the
  keyless website download `https://fred.stlouisfed.org/graph/fredgraph.csv?id=<CODE>`
  (an empty CSV value, e.g. the 2026-10-12 Columbus Day holiday in the Treasury
  yields, is FRED's `"."`). Source: Board of Governors of the Federal Reserve
  System (H.15, FOMC, H.4.1), U.S. Employment and Training Administration,
  U.S. Census Bureau / HUD, via FRED®.
- **Synthetic values (third-party copyright, nothing real is committed).**
  `ECBDFR` (constant 2.50), `USALOLITOAASTSAM` (100.00 + 0.05 per month), `NFCI`
  (-0.50 + 0.01 per week) and `VIXCLS` (15.0 + 0.1 per session).
- **Series metadata** (`series_<CODE>.json`): title, units and frequency as shown
  on each FRED series page on 2026-10-22; `last_updated` values are illustrative.
- **Edge cases (synthetic):** `series_units_changed.json` (a liquidity series in
  billions), `observations_bad_value.json` (a value that is neither numeric nor
  `"."`), `error_400_api_key.json` and `error_400_bad_request.json` (FRED error
  bodies).

This product uses the FRED® API but is not endorsed or certified by the Federal
Reserve Bank of St. Louis.
