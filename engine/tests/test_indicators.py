"""Offline tests for indicator math and snapshot building.

The fixtures are committed and read from disk (via the Stooq CSV parser); these
tests never touch the network. Expected values are hand-derived and documented
per assertion:

- ``indicator_series.csv``: 60 monotonic bars (close 100..159, high = close + 1,
  low = close - 1, volume 1000).
- ``indicator_constant.csv``: 60 flat bars (OHLC 50, volume 1000).
- ``stooq_nvda.csv``: the real 252-bar NVDA fixture, used as a longer smoke.
"""

import json
from datetime import date, timedelta
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from app import main as main_module
from app.indicators.core import (
    adx,
    atr,
    bollinger,
    directional_indicators,
    ema,
    macd,
    rsi,
    rvol,
    sma,
    stochastic,
)
from app.indicators.snapshots import compute_snapshots
from app.models import Bar
from app.sources.stooq import parse_eod_csv

FIXTURES = Path(__file__).parent / "fixtures"
MONOTONIC_FIXTURE = FIXTURES / "indicator_series.csv"
CONSTANT_FIXTURE = FIXTURES / "indicator_constant.csv"
NVDA_FIXTURE = FIXTURES / "stooq_nvda.csv"
REGRESSION_FIXTURE = FIXTURES / "indicator_regression.json"
# Ramp fixture (high = close + 1, low = close - 1): the 14-bar window spans 15, so
# raw %K = 100 * 14 / 15 on every bar and the averages stay there.
RAMP_STOCH = 100.0 * 14.0 / 15.0

INDICATOR_FIELDS = (
    "sma20",
    "sma50",
    "sma200",
    "ema21",
    "ema55",
    "rsi14",
    "adx",
    "macd",
    "macd_signal",
    "macd_hist",
    "bb_upper",
    "bb_middle",
    "bb_lower",
    "rvol",
    "atr14",
    "plus_di",
    "minus_di",
    "stoch_k",
    "stoch_d",
)

client = TestClient(main_module.app)


def load_bars(path: Path) -> list[Bar]:
    return parse_eod_csv(path.read_text(encoding="utf-8"))


def constant_bars(count: int, price: float = 50.0, volume: int = 1000) -> list[Bar]:
    start = date(2026, 1, 1)

    return [
        Bar(
            date=start + timedelta(days=index),
            open=price,
            high=price,
            low=price,
            close=price,
            volume=volume,
        )
        for index in range(count)
    ]


def snapshot_payload(bar: Bar) -> dict[str, object]:
    return {
        "date": bar.date.isoformat(),
        "open": bar.open,
        "high": bar.high,
        "low": bar.low,
        "close": bar.close,
        "volume": bar.volume,
    }


def test_sma_returns_none_until_the_window_is_full() -> None:
    values = [float(value) for value in range(1, 6)]  # 1..5

    assert sma(values, 3) == [None, None, 2.0, 3.0, 4.0]
    assert sma(values, 6) == [None] * 5
    assert sma(values, 0) == [None] * 5


def test_ema_seeds_with_the_sma_and_then_smooths() -> None:
    values = [float(value) for value in range(100, 160)]  # 100..159

    series = ema(values, 21)

    assert series[:20] == [None] * 20
    assert series[20] == pytest.approx(110.0)  # seed = mean(100..120)
    # k = 2 / (21 + 1) = 1/11 -> 121 * (1/11) + 110 * (10/11) = 11 + 100
    assert series[21] == pytest.approx(111.0)


def test_rsi_is_100_for_an_uptrend_0_for_a_downtrend_and_100_when_flat() -> None:
    rising = [float(value) for value in range(100, 131)]
    rising_rsi = rsi(rising, 14)
    assert rising_rsi[:14] == [None] * 14
    assert all(value == pytest.approx(100.0) for value in rising_rsi[14:])

    falling = [float(value) for value in range(130, 99, -1)]
    falling_rsi = rsi(falling, 14)
    assert all(value == pytest.approx(0.0) for value in falling_rsi[14:])

    # No losses in a flat window: documented RSI 100 convention.
    flat_rsi = rsi([50.0] * 30, 14)
    assert all(value == pytest.approx(100.0) for value in flat_rsi[14:])

    assert rsi([float(value) for value in range(1, 15)], 14) == [None] * 14


def test_macd_is_zero_when_flat_and_histogram_is_macd_minus_signal() -> None:
    flat = [5.0] * 60

    macd_line, signal_line, histogram = macd(flat, 12, 26, 9)

    assert macd_line[:25] == [None] * 25
    assert signal_line[:33] == [None] * 33
    assert all(value == pytest.approx(0.0) for value in macd_line[25:])
    assert all(value == pytest.approx(0.0) for value in signal_line[33:])
    assert all(value == pytest.approx(0.0) for value in histogram[33:])

    rising = [float(value) for value in range(100, 160)]
    macd_line, signal_line, histogram = macd(rising, 12, 26, 9)

    assert macd_line[:25] == [None] * 25
    assert macd_line[25] is not None
    assert signal_line[:33] == [None] * 33
    assert signal_line[33] is not None
    for index in range(33, len(rising)):
        assert histogram[index] == pytest.approx(macd_line[index] - signal_line[index])


def test_adx_is_100_for_a_steady_uptrend_and_zero_when_flat() -> None:
    bars = load_bars(MONOTONIC_FIXTURE)
    series = adx(
        [bar.high for bar in bars],
        [bar.low for bar in bars],
        [bar.close for bar in bars],
        14,
    )

    # TR is a constant 2, +DM is a constant 1, -DM is 0 -> DI+ 50, DI- 0, DX 100.
    assert series[:27] == [None] * 27
    assert all(value == pytest.approx(100.0) for value in series[27:])

    flat = constant_bars(60)
    flat_series = adx(
        [bar.high for bar in flat],
        [bar.low for bar in flat],
        [bar.close for bar in flat],
        14,
    )

    assert flat_series[:27] == [None] * 27
    assert all(value == pytest.approx(0.0) for value in flat_series[27:])


def test_bollinger_uses_the_population_deviation_and_collapses_when_flat() -> None:
    upper, middle, lower = bollinger([5.0] * 30, 20, 2.0)

    assert middle[:19] == [None] * 19
    assert all(value == pytest.approx(5.0) for value in middle[19:])
    assert upper[29] == pytest.approx(5.0)
    assert lower[29] == pytest.approx(5.0)
    assert upper[29] - lower[29] == pytest.approx(0.0)

    values = [float(value) for value in range(100, 160)]
    upper, middle, lower = bollinger(values, 20, 2.0)

    # Population variance of 20 consecutive integers = (20^2 - 1) / 12 = 33.25.
    deviation = 33.25**0.5
    assert middle[19] == pytest.approx(109.5)  # mean(100..119)
    assert upper[19] == pytest.approx(109.5 + 2 * deviation)
    assert lower[19] == pytest.approx(109.5 - 2 * deviation)


def test_rvol_uses_the_previous_sessions_as_the_baseline() -> None:
    series = rvol([10.0] * 50 + [20.0], 50)

    assert series[:50] == [None] * 50
    assert series[50] == pytest.approx(2.0)

    assert rvol([1000.0] * 60, 50)[59] == pytest.approx(1.0)
    assert rvol([1000.0] * 50, 50) == [None] * 50


def test_monotonic_fixture_matches_hand_derived_snapshot_values() -> None:
    bars = load_bars(MONOTONIC_FIXTURE)

    assert len(bars) == 60
    assert bars[0].close == 100.0
    assert bars[-1].close == 159.0

    snapshots = compute_snapshots(bars)

    assert len(snapshots) == 60
    assert [snapshot.date for snapshot in snapshots] == [bar.date for bar in bars]

    assert snapshots[18].sma20 is None
    assert snapshots[19].sma20 == pytest.approx(109.5)  # mean(100..119)
    assert snapshots[20].sma20 == pytest.approx(110.5)  # mean(101..120)
    assert snapshots[18].sma50 is None
    assert snapshots[49].sma50 == pytest.approx(124.5)  # mean(100..149)
    assert all(snapshot.sma200 is None for snapshot in snapshots)

    assert snapshots[19].ema21 is None
    assert snapshots[20].ema21 == pytest.approx(110.0)  # seed = mean(100..120)
    assert snapshots[21].ema21 == pytest.approx(111.0)  # k = 1/11
    assert snapshots[53].ema55 is None
    assert snapshots[54].ema55 == pytest.approx(127.0)  # seed = mean(100..154)

    assert snapshots[13].rsi14 is None
    assert all(snapshot.rsi14 == pytest.approx(100.0) for snapshot in snapshots[14:])

    assert snapshots[26].adx is None
    assert all(snapshot.adx == pytest.approx(100.0) for snapshot in snapshots[27:])

    assert snapshots[24].macd is None
    assert snapshots[25].macd is not None
    assert snapshots[32].macd_signal is None
    assert snapshots[33].macd_signal is not None

    # On a linear ramp the EMA lags by (period - 1) / 2 and the seed already
    # equals that steady state, so MACD = (26 - 1)/2 - (12 - 1)/2 = 7 for every
    # defined bar; a constant MACD makes the signal 7 and the histogram 0.
    assert all(snapshot.macd == pytest.approx(7.0) for snapshot in snapshots[25:])
    assert all(snapshot.macd_signal == pytest.approx(7.0) for snapshot in snapshots[33:])
    assert all(snapshot.macd_hist == pytest.approx(0.0) for snapshot in snapshots[33:])

    # bb_middle[index] = mean of the trailing 20 closes = 90.5 + index.
    assert all(
        snapshots[index].bb_middle == pytest.approx(90.5 + index)
        for index in range(19, 60)
    )

    assert snapshots[49].rvol is None
    assert all(snapshot.rvol == pytest.approx(1.0) for snapshot in snapshots[50:])


def test_constant_fixture_has_zero_band_width_and_flat_macd() -> None:
    bars = load_bars(CONSTANT_FIXTURE)

    assert len(bars) == 60

    last = compute_snapshots(bars)[-1]

    assert last.sma20 == pytest.approx(50.0)
    assert last.sma50 == pytest.approx(50.0)
    assert last.sma200 is None
    assert last.ema21 == pytest.approx(50.0)
    assert last.ema55 == pytest.approx(50.0)
    assert last.rsi14 == pytest.approx(100.0)
    assert last.adx == pytest.approx(0.0)
    assert last.macd == pytest.approx(0.0)
    assert last.macd_signal == pytest.approx(0.0)
    assert last.macd_hist == pytest.approx(0.0)
    assert last.bb_upper == pytest.approx(50.0)
    assert last.bb_middle == pytest.approx(50.0)
    assert last.bb_lower == pytest.approx(50.0)
    assert last.bb_upper - last.bb_lower == pytest.approx(0.0)
    assert last.rvol == pytest.approx(1.0)
    # No range at all: ATR and DI are 0, the stochastic reads the neutral 50.
    assert last.atr14 == pytest.approx(0.0)
    assert last.plus_di == pytest.approx(0.0)
    assert last.minus_di == pytest.approx(0.0)
    assert last.stoch_k == pytest.approx(50.0)
    assert last.stoch_d == pytest.approx(50.0)


def test_a_short_series_nulls_only_the_unavailable_indicators() -> None:
    bars = load_bars(MONOTONIC_FIXTURE)[:25]
    snapshots = compute_snapshots(bars)

    assert len(snapshots) == 25

    last = snapshots[-1]

    # Available with 25 bars.
    assert last.sma20 == pytest.approx(114.5)  # mean(105..124)
    assert last.ema21 is not None
    assert last.rsi14 == pytest.approx(100.0)
    assert last.bb_middle == pytest.approx(114.5)

    # Not yet available: null, never a partial or wrong value.
    assert last.sma50 is None
    assert last.sma200 is None
    assert last.ema55 is None
    assert last.adx is None
    assert last.macd is None
    assert last.macd_signal is None
    assert last.macd_hist is None
    assert last.rvol is None
    # 25 bars: ATR/DI (index 14) and %K/%D (indices 15/17) are available, ADX is not.
    assert last.atr14 == pytest.approx(2.0)
    assert last.plus_di is not None and last.minus_di is not None
    assert last.stoch_k == pytest.approx(RAMP_STOCH)
    assert last.stoch_d == pytest.approx(RAMP_STOCH)

    # 15 bars: ATR/DI just defined; 16 bars: %K defined, %D not yet.
    fifteen = compute_snapshots(load_bars(MONOTONIC_FIXTURE)[:15])[-1]
    assert fifteen.stoch_k is None  # first %K is index 15
    sixteen = compute_snapshots(load_bars(MONOTONIC_FIXTURE)[:16])[-1]
    assert fifteen.atr14 == pytest.approx(2.0)
    assert sixteen.stoch_k == pytest.approx(RAMP_STOCH)
    assert sixteen.stoch_d is None


def test_a_series_without_any_history_yields_only_nulls() -> None:
    snapshots = compute_snapshots(load_bars(MONOTONIC_FIXTURE)[:5])

    assert len(snapshots) == 5
    for snapshot in snapshots:
        assert all(getattr(snapshot, field) is None for field in INDICATOR_FIELDS)


def test_compute_endpoint_returns_one_snapshot_per_bar() -> None:
    bars = load_bars(MONOTONIC_FIXTURE)[:21]

    response = client.post(
        "/indicators/compute",
        json={"bars": [snapshot_payload(bar) for bar in bars]},
    )

    assert response.status_code == 200

    body = response.json()
    assert len(body["snapshots"]) == 21
    assert body["snapshots"][0]["date"] == "2026-01-01"
    assert set(body["snapshots"][0]) == {"date", *INDICATOR_FIELDS}
    assert body["snapshots"][0]["sma20"] is None
    assert body["snapshots"][19]["sma20"] == pytest.approx(109.5)
    assert body["snapshots"][20]["sma20"] == pytest.approx(110.5)
    assert body["snapshots"][20]["sma200"] is None


def test_compute_endpoint_accepts_an_empty_series() -> None:
    response = client.post("/indicators/compute", json={"bars": []})

    assert response.status_code == 200
    assert response.json() == {"snapshots": []}


def test_compute_endpoint_rejects_malformed_bodies() -> None:
    malformed_bar = client.post(
        "/indicators/compute",
        json={
            "bars": [
                {
                    "date": "not-a-date",
                    "open": 1.0,
                    "high": 2.0,
                    "low": 0.5,
                    "close": 1.5,
                    "volume": 10,
                }
            ]
        },
    )
    assert malformed_bar.status_code == 422

    missing_bars = client.post("/indicators/compute", json={"bars": "nope"})
    assert missing_bars.status_code == 422

    no_body = client.post("/indicators/compute", json={})
    assert no_body.status_code == 422


def test_nvda_fixture_fills_every_indicator_on_the_last_bar() -> None:
    bars = load_bars(NVDA_FIXTURE)

    assert len(bars) == 252

    snapshots = compute_snapshots(bars)

    assert len(snapshots) == 252

    last = snapshots[-1]

    for field in INDICATOR_FIELDS:
        assert getattr(last, field) is not None, f"{field} should be available on bar 252"

    assert last.bb_upper > last.bb_middle > last.bb_lower
    assert 0.0 <= last.rsi14 <= 100.0
    assert 0.0 <= last.adx <= 100.0


def ohlc(
    closes: list[float], spread: float = 1.0
) -> tuple[list[float], list[float], list[float]]:
    return (
        [close + spread for close in closes],
        [close - spread for close in closes],
        closes,
    )


def test_atr_is_2_for_a_constant_range_series_and_0_when_flat() -> None:
    highs, lows, closes = ohlc([10.0 + index for index in range(30)])

    series = atr(highs, lows, closes, 14)

    # TR = max(2, |c+1-(c-1)|=2, |c-1-(c-1)|=0) = 2 on every bar from index 1.
    assert series[:14] == [None] * 14
    assert all(value == pytest.approx(2.0) for value in series[14:])

    flat = [50.0] * 30
    assert atr(flat, flat, flat, 14)[14:] == [0.0] * 16
    assert atr(highs[:14], lows[:14], closes[:14], 14) == [None] * 14


def test_atr_and_di_follow_the_wilder_recursion_by_hand() -> None:
    # period 2; H = 11,12,14,13  L = 9,10,12,11  C = 10,11,13,12
    # TR1 = max(2, |12-10|, |10-10|) = 2; TR2 = max(2, |14-11|, |12-11|) = 3;
    # TR3 = max(2, |13-13|, |11-13|) = 2.
    # ATR[2] = (2 + 3) / 2 = 2.5; ATR[3] = (2.5 * 1 + 2) / 2 = 2.25.
    # +DM = 1, 2, 0 and -DM = 0, 0, 1 (up 1/down -1, up 2/down -2, up -1/down 1).
    # Sums at 2: TR 5, +DM 3, -DM 0 -> +DI 60, -DI 0.
    # Sums at 3: TR 5 - 2.5 + 2 = 4.5, +DM 3 - 1.5 + 0 = 1.5, -DM 0 - 0 + 1 = 1
    #   -> +DI 100 * 1.5 / 4.5 = 33.33.., -DI 100 / 4.5 = 22.22..
    highs = [11.0, 12.0, 14.0, 13.0]
    lows = [9.0, 10.0, 12.0, 11.0]
    closes = [10.0, 11.0, 13.0, 12.0]

    series = atr(highs, lows, closes, 2)
    plus_di, minus_di = directional_indicators(highs, lows, closes, 2)

    assert series[:2] == [None, None]
    assert series[2] == pytest.approx(2.5)
    assert series[3] == pytest.approx(2.25)
    assert plus_di[:2] == [None, None]
    assert minus_di[:2] == [None, None]
    assert plus_di[2] == pytest.approx(60.0)
    assert minus_di[2] == pytest.approx(0.0)
    assert plus_di[3] == pytest.approx(100.0 / 3.0)
    assert minus_di[3] == pytest.approx(100.0 / 4.5)


def test_directional_indicators_for_trends_and_a_flat_series() -> None:
    bars = load_bars(MONOTONIC_FIXTURE)
    highs = [bar.high for bar in bars]
    lows = [bar.low for bar in bars]
    closes = [bar.close for bar in bars]

    plus_di, minus_di = directional_indicators(highs, lows, closes, 14)

    # Strict uptrend: +DM 1 and TR 2 each bar -> +DI 50, -DI 0, first at index 14.
    assert plus_di[:14] == [None] * 14
    assert minus_di[:14] == [None] * 14
    assert all(value == pytest.approx(50.0) for value in plus_di[14:])
    assert all(value == pytest.approx(0.0) for value in minus_di[14:])

    falling = [200.0 - index for index in range(40)]
    f_highs, f_lows, f_closes = ohlc(falling)
    f_plus, f_minus = directional_indicators(f_highs, f_lows, f_closes, 14)
    assert all(f_minus[i] > f_plus[i] for i in range(14, 40))

    flat = [50.0] * 30
    flat_plus, flat_minus = directional_indicators(flat, flat, flat, 14)
    assert flat_plus[14:] == [0.0] * 16
    assert flat_minus[14:] == [0.0] * 16


def test_stochastic_edges_and_first_defined_indices() -> None:
    rising = [float(10 + index) for index in range(30)]
    k_line, d_line = stochastic(rising, rising, rising, 14, 3, 3)

    # high = low = close: raw = 100 from index 13, %K from 15, %D from 17.
    assert k_line[:15] == [None] * 15
    assert d_line[:17] == [None] * 17
    assert all(value == pytest.approx(100.0) for value in k_line[15:])
    assert all(value == pytest.approx(100.0) for value in d_line[17:])

    falling = [float(100 - index) for index in range(30)]
    k_line, d_line = stochastic(falling, falling, falling, 14, 3, 3)
    assert all(value == pytest.approx(0.0) for value in k_line[15:])
    assert all(value == pytest.approx(0.0) for value in d_line[17:])

    flat = [50.0] * 30
    k_line, d_line = stochastic(flat, flat, flat, 14, 3, 3)
    assert k_line[:15] == [None] * 15
    assert all(value == 50.0 for value in k_line[15:])
    assert all(value == 50.0 for value in d_line[17:])

    short = rising[:13]
    assert stochastic(short, short, short) == ([None] * 13, [None] * 13)


def test_stochastic_matches_a_hand_computed_window() -> None:
    # period 2, smoothing 2/2. H = 4,6,5,7  L = 2,3,3,4  C = 3,5,4,7.
    # raw[1] = 100 * (5 - 2) / (6 - 2) = 75; raw[2] = 100 * (4 - 3) / (6 - 3) = 33.33..;
    # raw[3] = 100 * (7 - 4) / (7 - 4) = 100.
    # %K[2] = (75 + 33.33) / 2 = 54.1666..; %K[3] = (33.33 + 100) / 2 = 66.6666..;
    # %D[3] = (54.1666 + 66.6666) / 2 = 60.4166..
    highs = [4.0, 6.0, 5.0, 7.0]
    lows = [2.0, 3.0, 3.0, 4.0]
    closes = [3.0, 5.0, 4.0, 7.0]

    k_line, d_line = stochastic(highs, lows, closes, 2, 2, 2)

    assert k_line[:2] == [None, None]
    assert k_line[2] == pytest.approx((75.0 + 100.0 / 3.0) / 2.0)
    assert k_line[3] == pytest.approx((100.0 / 3.0 + 100.0) / 2.0)
    assert d_line[:3] == [None, None, None]
    assert d_line[3] == pytest.approx((k_line[2] + k_line[3]) / 2.0)


def test_stochastic_stays_within_0_and_100_on_real_data() -> None:
    snapshots = compute_snapshots(load_bars(NVDA_FIXTURE))

    k_values = [s.stoch_k for s in snapshots]
    d_values = [s.stoch_d for s in snapshots]
    assert k_values[:15] == [None] * 15
    assert d_values[:17] == [None] * 17
    assert k_values[15] is not None and d_values[17] is not None
    assert all(0.0 <= v <= 100.0 for v in k_values[15:])
    assert all(0.0 <= v <= 100.0 for v in d_values[17:])


def test_previous_indicator_values_are_unchanged_by_the_expansion() -> None:
    # Values captured from the engine before ATR/DI/Stochastic were added
    # (including the ADX refactor onto the shared Wilder helper). Compared to 1e-9:
    # the last float digit differs between platforms (Windows vs the Linux CI).
    expected = json.loads(REGRESSION_FIXTURE.read_text(encoding="utf-8"))
    fields = ("adx", "bb_upper", "bb_middle", "bb_lower", "sma20", "rsi14", "macd", "rvol")

    for name, path in (
        ("stooq_nvda", NVDA_FIXTURE),
        ("indicator_series", MONOTONIC_FIXTURE),
        ("indicator_constant", CONSTANT_FIXTURE),
    ):
        snapshots = compute_snapshots(load_bars(path))
        assert len(snapshots) == len(expected[name])
        for snapshot, row in zip(snapshots, expected[name], strict=True):
            actual = [getattr(snapshot, field) for field in fields]
            assert actual == [None if v is None else pytest.approx(v, rel=1e-9, abs=1e-9) for v in row]


def test_compute_endpoint_returns_the_new_indicator_keys() -> None:
    bars = load_bars(MONOTONIC_FIXTURE)[:20]

    response = client.post(
        "/indicators/compute",
        json={"bars": [snapshot_payload(bar) for bar in bars]},
    )

    assert response.status_code == 200
    snapshots = response.json()["snapshots"]
    assert snapshots[13]["atr14"] is None
    assert snapshots[13]["stoch_k"] is None
    assert snapshots[13]["stoch_d"] is None
    assert snapshots[14]["atr14"] == pytest.approx(2.0)
    assert snapshots[14]["plus_di"] == pytest.approx(50.0)
    assert snapshots[14]["minus_di"] == pytest.approx(0.0)
    assert snapshots[15]["stoch_k"] == pytest.approx(RAMP_STOCH)
    assert snapshots[16]["stoch_d"] is None
    assert snapshots[17]["stoch_d"] == pytest.approx(RAMP_STOCH)
