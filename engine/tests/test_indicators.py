"""Offline tests for indicator math and snapshot building.

The fixtures are committed and read from disk (via the Stooq CSV parser); these
tests never touch the network. Expected values are hand-derived and documented
per assertion:

- ``indicator_series.csv``: 60 monotonic bars (close 100..159, high = close + 1,
  low = close - 1, volume 1000).
- ``indicator_constant.csv``: 60 flat bars (OHLC 50, volume 1000).
- ``stooq_nvda.csv``: the real 252-bar NVDA fixture, used as a longer smoke.
"""

from datetime import date, timedelta
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from app import main as main_module
from app.indicators.core import adx, bollinger, ema, macd, rsi, rvol, sma
from app.indicators.snapshots import compute_snapshots
from app.models import Bar
from app.sources.stooq import parse_eod_csv

FIXTURES = Path(__file__).parent / "fixtures"
MONOTONIC_FIXTURE = FIXTURES / "indicator_series.csv"
CONSTANT_FIXTURE = FIXTURES / "indicator_constant.csv"
NVDA_FIXTURE = FIXTURES / "stooq_nvda.csv"

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
