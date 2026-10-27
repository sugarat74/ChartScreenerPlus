"""Offline tests for deterministic signal detection.

Every rule is a pure predicate over the as-of bar (and, for crosses, the
previous bar). These tests use synthetic ``Snapshot`` values for exact
positive/negative/boundary/null cases, plus real indicator math over crafted
bars for end-to-end detection. No test touches the network.
"""

from datetime import date, timedelta
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from app import main as main_module
from app.models import Bar, Snapshot
from app.signals.detect import detect_signals
from app.signals.rules import (
    RULES,
    adx_trend_strength,
    bollinger_close_above_upper,
    bollinger_close_below_lower,
    bollinger_squeeze,
    death_cross,
    golden_cross,
    ma_alignment_bearish,
    ma_alignment_bullish,
    macd_bearish_cross,
    macd_bullish_cross,
    pivot_breakout_rvol,
    rsi_overbought,
    rsi_oversold,
    stoch_bearish_cross,
    stoch_bullish_cross,
)
from app.sources.stooq import parse_eod_csv

FIXTURES = Path(__file__).parent / "fixtures"
NVDA_FIXTURE = FIXTURES / "stooq_nvda.csv"

SIGNAL_TYPES = (
    "golden_cross",
    "death_cross",
    "ma_alignment_bullish",
    "ma_alignment_bearish",
    "pivot_breakout_rvol",
    "rsi_overbought",
    "rsi_oversold",
    "macd_bullish_cross",
    "macd_bearish_cross",
    "adx_trend_strength",
    "bollinger_squeeze",
    "bollinger_close_above_upper",
    "bollinger_close_below_lower",
    "stoch_bullish_cross",
    "stoch_bearish_cross",
)

ORIGINAL_SIGNAL_TYPES = SIGNAL_TYPES[:9]

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

START = date(2026, 1, 1)

client = TestClient(main_module.app)


def make_snapshot(day: date, **overrides: float | None) -> Snapshot:
    """A snapshot with every indicator null unless overridden."""

    values: dict[str, object] = dict.fromkeys(INDICATOR_FIELDS)
    values.update(overrides)

    return Snapshot(date=day, **values)


def flat_bars(count: int, price: float = 100.0, volume: int = 1000) -> list[Bar]:
    return [
        Bar(
            date=START + timedelta(days=index),
            open=price,
            high=price,
            low=price,
            close=price,
            volume=volume,
        )
        for index in range(count)
    ]


def bar_payload(bar: Bar) -> dict[str, object]:
    return {
        "date": bar.date.isoformat(),
        "open": bar.open,
        "high": bar.high,
        "low": bar.low,
        "close": bar.close,
        "volume": bar.volume,
    }


def test_the_registry_holds_exactly_the_fixed_vocabulary() -> None:
    assert tuple(signal_type for signal_type, _rule in RULES) == SIGNAL_TYPES


def test_golden_cross_fires_only_on_a_bullish_cross() -> None:
    previous = make_snapshot(START, sma50=99.0, sma200=100.0)
    current = make_snapshot(START + timedelta(days=1), sma50=101.0, sma200=100.0)

    fired, metadata = golden_cross(previous, current, [], 0)

    assert fired is True
    assert metadata == {"sma50": 101.0, "sma200": 100.0}

    # Already above before the as-of bar: not a cross.
    assert golden_cross(
        make_snapshot(START, sma50=101.0, sma200=100.0),
        make_snapshot(START, sma50=102.0, sma200=100.0),
        [],
        0,
    ) == (False, {})

    # Equality at the as-of bar is not a cross (strict inequality).
    assert golden_cross(
        previous,
        make_snapshot(START, sma50=100.0, sma200=100.0),
        [],
        0,
    ) == (False, {})

    # Null history never fires.
    assert golden_cross(make_snapshot(START), current, [], 0) is None
    assert golden_cross(None, current, [], 0) is None


def test_death_cross_fires_only_on_a_bearish_cross() -> None:
    previous = make_snapshot(START, sma50=101.0, sma200=100.0)
    current = make_snapshot(START + timedelta(days=1), sma50=99.0, sma200=100.0)

    fired, metadata = death_cross(previous, current, [], 0)

    assert fired is True
    assert metadata == {"sma50": 99.0, "sma200": 100.0}

    assert death_cross(
        make_snapshot(START, sma50=99.0, sma200=100.0),
        make_snapshot(START, sma50=98.0, sma200=100.0),
        [],
        0,
    ) == (False, {})

    # Equality at the as-of bar is not a cross.
    assert death_cross(
        previous,
        make_snapshot(START, sma50=100.0, sma200=100.0),
        [],
        0,
    ) == (False, {})

    assert death_cross(make_snapshot(START), current, [], 0) is None
    assert death_cross(None, current, [], 0) is None


def test_ma_alignment_bullish_requires_strict_ordering() -> None:
    fired, metadata = ma_alignment_bullish(
        None,
        make_snapshot(START, sma20=110.0, sma50=105.0, sma200=100.0),
        [],
        0,
    )

    assert fired is True
    assert metadata == {"sma20": 110.0, "sma50": 105.0, "sma200": 100.0}

    # Not strictly ordered.
    assert ma_alignment_bullish(
        None,
        make_snapshot(START, sma20=105.0, sma50=110.0, sma200=100.0),
        [],
        0,
    ) == (False, {})

    # Equality anywhere in the chain is not alignment.
    assert ma_alignment_bullish(
        None,
        make_snapshot(START, sma20=110.0, sma50=110.0, sma200=100.0),
        [],
        0,
    ) == (False, {})

    # The 200-window is not available yet.
    assert ma_alignment_bullish(
        None,
        make_snapshot(START, sma20=110.0, sma50=105.0),
        [],
        0,
    ) is None


def test_ma_alignment_bearish_requires_strict_ordering() -> None:
    fired, metadata = ma_alignment_bearish(
        None,
        make_snapshot(START, sma20=90.0, sma50=95.0, sma200=100.0),
        [],
        0,
    )

    assert fired is True
    assert metadata == {"sma20": 90.0, "sma50": 95.0, "sma200": 100.0}

    assert ma_alignment_bearish(
        None,
        make_snapshot(START, sma20=95.0, sma50=90.0, sma200=100.0),
        [],
        0,
    ) == (False, {})

    assert ma_alignment_bearish(
        None,
        make_snapshot(START, sma20=90.0, sma50=95.0),
        [],
        0,
    ) is None


def pivot_bars(prior_high: float = 100.0) -> list[Bar]:
    """20 prior flat bars plus the as-of bar (close 105, high 106)."""

    bars = flat_bars(20, price=prior_high)
    bars.append(
        Bar(
            date=START + timedelta(days=20),
            open=prior_high,
            high=prior_high + 1.0,
            low=prior_high - 1.0,
            close=prior_high + 5.0,
            volume=3000,
        )
    )

    return bars


def test_pivot_breakout_rvol_fires_above_the_prior_pivot_with_enough_rvol() -> None:
    bars = pivot_bars()
    current = make_snapshot(bars[-1].date, rvol=2.5)

    fired, metadata = pivot_breakout_rvol(None, current, bars, len(bars) - 1)

    assert fired is True
    assert metadata == {"pivot": 100.0, "close": 105.0, "rvol": 2.5}

    # Equality with the pivot is not a breakout (strict inequality).
    equal_bars = flat_bars(20, price=100.0)
    equal_bars.append(
        Bar(
            date=START + timedelta(days=20),
            open=100.0,
            high=100.0,
            low=100.0,
            close=100.0,
            volume=3000,
        )
    )
    assert pivot_breakout_rvol(
        None,
        make_snapshot(equal_bars[-1].date, rvol=3.0),
        equal_bars,
        len(equal_bars) - 1,
    ) == (False, {})

    # 2.0 exactly is enough (inclusive threshold).
    assert pivot_breakout_rvol(
        None, make_snapshot(bars[-1].date, rvol=2.0), bars, len(bars) - 1
    )[0] is True

    # Below the RVOL floor is not a breakout signal.
    assert pivot_breakout_rvol(
        None, make_snapshot(bars[-1].date, rvol=1.9), bars, len(bars) - 1
    ) == (False, {})

    # Null RVOL or too little history never fires.
    assert pivot_breakout_rvol(None, make_snapshot(bars[-1].date), bars, len(bars) - 1) is None
    short_bars = flat_bars(10)
    assert pivot_breakout_rvol(None, make_snapshot(short_bars[-1].date, rvol=3.0), short_bars, 9) is None


def test_rsi_overbought_uses_an_inclusive_threshold() -> None:
    assert rsi_overbought(None, make_snapshot(START, rsi14=70.0), [], 0)[0] is True
    assert rsi_overbought(None, make_snapshot(START, rsi14=69.9), [], 0) == (False, {})
    assert rsi_overbought(None, make_snapshot(START), [], 0) is None


def test_rsi_oversold_uses_an_inclusive_threshold() -> None:
    assert rsi_oversold(None, make_snapshot(START, rsi14=30.0), [], 0)[0] is True
    assert rsi_oversold(None, make_snapshot(START, rsi14=30.1), [], 0) == (False, {})
    assert rsi_oversold(None, make_snapshot(START), [], 0) is None


def test_macd_bullish_cross_fires_only_when_the_line_crosses_up() -> None:
    previous = make_snapshot(START, macd=-0.5, macd_signal=-0.2)
    current = make_snapshot(START + timedelta(days=1), macd=0.5, macd_signal=0.2)

    assert macd_bullish_cross(previous, current, [], 0) == (
        True,
        {"macd": 0.5, "macd_signal": 0.2},
    )

    # Already above before the as-of bar.
    assert macd_bullish_cross(
        make_snapshot(START, macd=0.5, macd_signal=0.2),
        make_snapshot(START, macd=0.6, macd_signal=0.2),
        [],
        0,
    ) == (False, {})

    # Equality at the as-of bar is not a cross.
    assert macd_bullish_cross(
        previous,
        make_snapshot(START, macd=0.2, macd_signal=0.2),
        [],
        0,
    ) == (False, {})

    assert macd_bullish_cross(make_snapshot(START), current, [], 0) is None
    assert macd_bullish_cross(None, current, [], 0) is None


def test_macd_bearish_cross_fires_only_when_the_line_crosses_down() -> None:
    previous = make_snapshot(START, macd=0.5, macd_signal=0.2)
    current = make_snapshot(START + timedelta(days=1), macd=-0.5, macd_signal=-0.2)

    assert macd_bearish_cross(previous, current, [], 0) == (
        True,
        {"macd": -0.5, "macd_signal": -0.2},
    )

    assert macd_bearish_cross(
        make_snapshot(START, macd=-0.5, macd_signal=-0.2),
        make_snapshot(START, macd=-0.6, macd_signal=-0.2),
        [],
        0,
    ) == (False, {})

    assert macd_bearish_cross(
        previous,
        make_snapshot(START, macd=0.2, macd_signal=0.2),
        [],
        0,
    ) == (False, {})

    assert macd_bearish_cross(make_snapshot(START), current, [], 0) is None
    assert macd_bearish_cross(None, current, [], 0) is None


def test_detect_signals_returns_empty_for_fewer_than_two_bars() -> None:
    assert detect_signals([]) == []

    single = flat_bars(1)
    assert detect_signals(single) == []


def test_detect_signals_emits_the_bullish_rule_set_on_the_as_of_bar(monkeypatch) -> None:
    bars = pivot_bars()
    snapshots = [make_snapshot(bar.date) for bar in bars]
    snapshots[-2] = make_snapshot(
        bars[-2].date, sma50=99.0, sma200=100.0, macd=-0.5, macd_signal=-0.2
    )
    snapshots[-1] = make_snapshot(
        bars[-1].date,
        sma20=110.0,
        sma50=105.0,
        sma200=100.0,
        rsi14=72.0,
        macd=0.5,
        macd_signal=0.2,
        rvol=2.5,
    )
    monkeypatch.setattr(
        "app.signals.detect.compute_snapshots", lambda _bars: snapshots
    )

    signals = detect_signals(bars)

    assert [signal.type for signal in signals] == [
        "golden_cross",
        "ma_alignment_bullish",
        "pivot_breakout_rvol",
        "rsi_overbought",
        "macd_bullish_cross",
    ]
    assert all(signal.date == bars[-1].date for signal in signals)
    assert {signal.type: signal.metadata for signal in signals}[
        "pivot_breakout_rvol"
    ] == {"pivot": 100.0, "close": 105.0, "rvol": 2.5}


def test_detect_signals_emits_the_bearish_rule_set_on_the_as_of_bar(monkeypatch) -> None:
    bars = pivot_bars()
    snapshots = [make_snapshot(bar.date) for bar in bars]
    snapshots[-2] = make_snapshot(
        bars[-2].date, sma50=101.0, sma200=100.0, macd=0.5, macd_signal=0.2
    )
    snapshots[-1] = make_snapshot(
        bars[-1].date,
        sma20=90.0,
        sma50=95.0,
        sma200=100.0,
        rsi14=25.0,
        macd=-0.5,
        macd_signal=-0.2,
        rvol=1.5,
    )
    monkeypatch.setattr(
        "app.signals.detect.compute_snapshots", lambda _bars: snapshots
    )

    signals = detect_signals(bars)

    assert [signal.type for signal in signals] == [
        "death_cross",
        "ma_alignment_bearish",
        "rsi_oversold",
        "macd_bearish_cross",
    ]
    assert all(signal.date == bars[-1].date for signal in signals)


def test_detect_signals_returns_nothing_when_no_rule_holds(monkeypatch) -> None:
    bars = flat_bars(21)
    snapshots = [make_snapshot(bar.date, sma20=100.0, sma50=100.0, sma200=100.0, rsi14=50.0) for bar in bars]
    monkeypatch.setattr(
        "app.signals.detect.compute_snapshots", lambda _bars: snapshots
    )

    assert detect_signals(bars) == []


def test_detect_signals_from_real_math_flags_the_pivot_breakout() -> None:
    bars = flat_bars(59)
    bars.append(
        Bar(
            date=START + timedelta(days=59),
            open=100.0,
            high=111.0,
            low=99.0,
            close=110.0,
            volume=3000,
        )
    )

    signals = detect_signals(bars)
    by_type = {signal.type: signal for signal in signals}

    assert "pivot_breakout_rvol" in by_type

    pivot_signal = by_type["pivot_breakout_rvol"]

    assert pivot_signal.date == bars[-1].date
    assert pivot_signal.metadata["pivot"] == 100.0
    assert pivot_signal.metadata["close"] == 110.0
    assert pivot_signal.metadata["rvol"] == pytest.approx(3.0)
    assert all(signal.date == bars[-1].date for signal in signals)


def test_detect_signals_over_the_nvda_fixture_only_dates_the_as_of_bar() -> None:
    bars = parse_eod_csv(NVDA_FIXTURE.read_text(encoding="utf-8"))

    signals = detect_signals(bars)

    assert all(signal.date == bars[-1].date for signal in signals)
    assert all(signal.type in SIGNAL_TYPES for signal in signals)
    assert all(
        isinstance(value, float)
        for signal in signals
        for value in signal.metadata.values()
    )


def test_detect_endpoint_returns_the_as_of_signals() -> None:
    bars = flat_bars(59)
    bars.append(
        Bar(
            date=START + timedelta(days=59),
            open=100.0,
            high=111.0,
            low=99.0,
            close=110.0,
            volume=3000,
        )
    )

    response = client.post(
        "/signals/detect", json={"bars": [bar_payload(bar) for bar in bars]}
    )

    assert response.status_code == 200

    body = response.json()
    types = [signal["type"] for signal in body["signals"]]

    assert "pivot_breakout_rvol" in types

    pivot_signal = next(
        signal for signal in body["signals"] if signal["type"] == "pivot_breakout_rvol"
    )

    assert pivot_signal["date"] == bars[-1].date.isoformat()
    assert pivot_signal["metadata"] == {"pivot": 100.0, "close": 110.0, "rvol": 3.0}


def test_detect_endpoint_accepts_an_empty_series() -> None:
    response = client.post("/signals/detect", json={"bars": []})

    assert response.status_code == 200
    assert response.json() == {"signals": []}


def test_detect_endpoint_rejects_malformed_bodies() -> None:
    malformed_bar = client.post(
        "/signals/detect",
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

    missing_bars = client.post("/signals/detect", json={"bars": "nope"})
    assert missing_bars.status_code == 422

    no_body = client.post("/signals/detect", json={})
    assert no_body.status_code == 422


# --- signals-expansion: ADX, Bollinger and Stochastic -------------------------


def close_bars(closes: list[float]) -> list[Bar]:
    """Bars whose OHLC all equal the close (so the bands depend on closes only)."""

    return [
        Bar(
            date=START + timedelta(days=index),
            open=close,
            high=close,
            low=close,
            close=close,
            volume=1000,
        )
        for index, close in enumerate(closes)
    ]


def test_the_original_nine_rules_keep_their_order_at_the_head_of_the_registry() -> None:
    assert tuple(signal_type for signal_type, _rule in RULES)[:9] == ORIGINAL_SIGNAL_TYPES


def test_adx_trend_strength_fires_only_when_adx_crosses_above_25() -> None:
    previous = make_snapshot(START, adx=24.0)
    current = make_snapshot(
        START + timedelta(days=1), adx=26.5, plus_di=30.0, minus_di=12.0
    )

    assert adx_trend_strength(previous, current, [], 0) == (
        True,
        {"adx": 26.5, "plus_di": 30.0, "minus_di": 12.0},
    )

    # Previous exactly 25 still crosses; current exactly 25 does not.
    assert adx_trend_strength(make_snapshot(START, adx=25.0), current, [], 0)[0] is True
    assert adx_trend_strength(previous, make_snapshot(START, adx=25.0), [], 0) == (
        False,
        {},
    )

    # Already above: not a cross.
    assert adx_trend_strength(make_snapshot(START, adx=26.0), current, [], 0) == (
        False,
        {},
    )

    # Missing DI values do not block the cross; missing ADX or history returns None.
    assert adx_trend_strength(previous, make_snapshot(START, adx=26.0), [], 0) == (
        True,
        {"adx": 26.0},
    )
    assert adx_trend_strength(make_snapshot(START), current, [], 0) is None
    assert adx_trend_strength(previous, make_snapshot(START), [], 0) is None
    assert adx_trend_strength(None, current, [], 0) is None


def squeeze_closes(amplitudes: list[float]) -> list[float]:
    return [
        100.0 + (amplitude if index % 2 == 0 else -amplitude)
        for index, amplitude in enumerate(amplitudes)
    ]


def test_bollinger_squeeze_fires_at_a_120_session_bandwidth_low() -> None:
    # Strictly shrinking alternation: today's bandwidth is the lowest of 120.
    amplitudes = [5.0 - 0.01 * index for index in range(160)]
    bars = close_bars(squeeze_closes(amplitudes))

    result = bollinger_squeeze(None, make_snapshot(START), bars, len(bars) - 1)

    assert result is not None
    fired, metadata = result
    assert fired is True
    assert set(metadata) == {"bandwidth", "lookback_min"}
    assert metadata["bandwidth"] <= metadata["lookback_min"]

    # A wide bar today breaks the low.
    wide = close_bars(squeeze_closes([*amplitudes[:-1], 9.0]))

    assert bollinger_squeeze(None, make_snapshot(START), wide, len(wide) - 1) == (
        False,
        {},
    )


def test_bollinger_squeeze_boundary_is_inclusive_and_needs_139_bars() -> None:
    flat = close_bars([100.0] * 139)

    # A tie with the previous minimum (all zero) is a 120-session low.
    assert bollinger_squeeze(None, make_snapshot(START), flat, 138) == (
        True,
        {"bandwidth": 0.0, "lookback_min": 0.0},
    )

    # One bar fewer: the 120-session window is incomplete.
    assert bollinger_squeeze(None, make_snapshot(START), flat[:138], 137) is None
    assert bollinger_squeeze(None, make_snapshot(START), [], 0) is None

    # A zero middle band has no bandwidth: never fires.
    zero = close_bars([0.0] * 139)

    assert bollinger_squeeze(None, make_snapshot(START), zero, 138) is None


def test_bollinger_close_above_upper_fires_only_on_a_strict_upward_cross() -> None:
    bars = close_bars([100.0, 105.0])
    previous = make_snapshot(START, bb_upper=106.0)
    current = make_snapshot(START + timedelta(days=1), bb_upper=104.0)

    assert bollinger_close_above_upper(previous, current, bars, 1) == (
        True,
        {"close": 105.0, "bb_upper": 104.0},
    )

    # Close exactly on the upper band is not above it.
    assert bollinger_close_above_upper(
        previous, make_snapshot(START, bb_upper=105.0), bars, 1
    ) == (False, {})

    # Already above the previous band: not a cross.
    assert bollinger_close_above_upper(
        make_snapshot(START, bb_upper=99.0), current, bars, 1
    ) == (False, {})

    # Previous close exactly on the band still counts as "at or below".
    assert bollinger_close_above_upper(
        make_snapshot(START, bb_upper=100.0), current, bars, 1
    )[0] is True

    assert bollinger_close_above_upper(make_snapshot(START), current, bars, 1) is None
    assert bollinger_close_above_upper(previous, make_snapshot(START), bars, 1) is None
    assert bollinger_close_above_upper(None, current, bars, 1) is None


def test_bollinger_close_below_lower_fires_only_on_a_strict_downward_cross() -> None:
    bars = close_bars([100.0, 95.0])
    previous = make_snapshot(START, bb_lower=94.0)
    current = make_snapshot(START + timedelta(days=1), bb_lower=96.0)

    assert bollinger_close_below_lower(previous, current, bars, 1) == (
        True,
        {"close": 95.0, "bb_lower": 96.0},
    )

    assert bollinger_close_below_lower(
        previous, make_snapshot(START, bb_lower=95.0), bars, 1
    ) == (False, {})
    assert bollinger_close_below_lower(
        make_snapshot(START, bb_lower=101.0), current, bars, 1
    ) == (False, {})
    assert bollinger_close_below_lower(
        make_snapshot(START, bb_lower=100.0), current, bars, 1
    )[0] is True

    assert bollinger_close_below_lower(make_snapshot(START), current, bars, 1) is None
    assert bollinger_close_below_lower(previous, make_snapshot(START), bars, 1) is None
    assert bollinger_close_below_lower(None, current, bars, 1) is None


def test_stoch_bullish_cross_needs_a_cross_out_of_the_oversold_zone() -> None:
    previous = make_snapshot(START, stoch_k=15.0, stoch_d=18.0)
    current = make_snapshot(START, stoch_k=22.0, stoch_d=19.0)

    assert stoch_bullish_cross(previous, current, [], 0) == (
        True,
        {"stoch_k": 22.0, "stoch_d": 19.0},
    )

    # Previous %K exactly 20 and %K == %D still count as the starting side.
    assert stoch_bullish_cross(
        make_snapshot(START, stoch_k=20.0, stoch_d=20.0), current, [], 0
    )[0] is True

    # Previous %K above 20: not from the zone.
    assert stoch_bullish_cross(
        make_snapshot(START, stoch_k=20.5, stoch_d=21.0), current, [], 0
    ) == (False, {})
    # Equal at the as-of bar is not a cross.
    assert stoch_bullish_cross(
        previous, make_snapshot(START, stoch_k=19.0, stoch_d=19.0), [], 0
    ) == (False, {})
    # Already above %D before.
    assert stoch_bullish_cross(
        make_snapshot(START, stoch_k=15.0, stoch_d=14.0), current, [], 0
    ) == (False, {})

    assert stoch_bullish_cross(make_snapshot(START), current, [], 0) is None
    assert stoch_bullish_cross(previous, make_snapshot(START, stoch_k=22.0), [], 0) is None
    assert stoch_bullish_cross(None, current, [], 0) is None


def test_stoch_bearish_cross_needs_a_cross_out_of_the_overbought_zone() -> None:
    previous = make_snapshot(START, stoch_k=85.0, stoch_d=82.0)
    current = make_snapshot(START, stoch_k=78.0, stoch_d=81.0)

    assert stoch_bearish_cross(previous, current, [], 0) == (
        True,
        {"stoch_k": 78.0, "stoch_d": 81.0},
    )

    assert stoch_bearish_cross(
        make_snapshot(START, stoch_k=80.0, stoch_d=80.0), current, [], 0
    )[0] is True
    assert stoch_bearish_cross(
        make_snapshot(START, stoch_k=79.5, stoch_d=79.0), current, [], 0
    ) == (False, {})
    assert stoch_bearish_cross(
        previous, make_snapshot(START, stoch_k=81.0, stoch_d=81.0), [], 0
    ) == (False, {})
    assert stoch_bearish_cross(
        make_snapshot(START, stoch_k=85.0, stoch_d=86.0), current, [], 0
    ) == (False, {})

    assert stoch_bearish_cross(make_snapshot(START), current, [], 0) is None
    assert stoch_bearish_cross(previous, make_snapshot(START, stoch_k=78.0), [], 0) is None
    assert stoch_bearish_cross(None, current, [], 0) is None


def test_detect_signals_flags_a_close_above_the_upper_band_from_real_math() -> None:
    bars = close_bars([100.0] * 30 + [110.0])

    by_type = {signal.type: signal for signal in detect_signals(bars)}

    assert "bollinger_close_above_upper" in by_type
    assert "bollinger_close_below_lower" not in by_type
    assert by_type["bollinger_close_above_upper"].metadata["close"] == 110.0
    assert by_type["bollinger_close_above_upper"].metadata["bb_upper"] < 110.0


def test_detect_signals_flags_a_close_below_the_lower_band_from_real_math() -> None:
    bars = close_bars([100.0] * 30 + [90.0])

    by_type = {signal.type: signal for signal in detect_signals(bars)}

    assert "bollinger_close_below_lower" in by_type
    assert "bollinger_close_above_upper" not in by_type


def test_detect_signals_flags_a_squeeze_on_a_long_flat_series() -> None:
    types = [signal.type for signal in detect_signals(close_bars([100.0] * 160))]

    assert "bollinger_squeeze" in types
    assert len(types) == len(set(types))


def test_the_original_nine_signals_are_unchanged_on_the_nvda_fixture() -> None:
    bars = parse_eod_csv(NVDA_FIXTURE.read_text(encoding="utf-8"))

    signals = [
        signal
        for signal in detect_signals(bars)
        if signal.type in ORIGINAL_SIGNAL_TYPES
    ]

    assert [signal.type for signal in signals] == ["ma_alignment_bullish"]
    assert signals[0].date == bars[-1].date
    assert signals[0].metadata == pytest.approx(
        {
            "sma20": 222.68900000000016,
            "sma50": 216.99559999999997,
            "sma200": 199.91460000000015,
        }
    )


def test_each_signal_type_appears_at_most_once_over_the_nvda_fixture() -> None:
    bars = parse_eod_csv(NVDA_FIXTURE.read_text(encoding="utf-8"))
    types = [signal.type for signal in detect_signals(bars)]

    assert len(types) == len(set(types))
    assert all(signal_type in SIGNAL_TYPES for signal_type in types)
