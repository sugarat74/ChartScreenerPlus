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
    death_cross,
    golden_cross,
    ma_alignment_bearish,
    ma_alignment_bullish,
    macd_bearish_cross,
    macd_bullish_cross,
    pivot_breakout_rvol,
    rsi_overbought,
    rsi_oversold,
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
)

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
