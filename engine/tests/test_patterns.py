"""Offline tests for chartist pattern detection.

Series are synthetic: closes are linearly interpolated between waypoints
``(index, close)``, highs/lows sit 0.2% above/below the close, so the only
swing points are the waypoints where the direction changes. Each pattern type
has a textbook positive case, near misses that break one rule, and status
transitions (forming -> confirmed -> gone). No test touches the network.
"""

from datetime import date, timedelta
from itertools import pairwise

import pytest
from fastapi.testclient import TestClient

from app import main as main_module
from app.models import Bar
from app.patterns.detect import detect_patterns
from app.patterns.near_misses import detect_near_misses
from app.patterns.swings import swing_highs, swing_lows

START = date(2024, 1, 1)


def series(waypoints: list[tuple[int, float]], volumes: dict[int, int] | None = None, base_volume: int = 1000) -> list[Bar]:
    """Bars interpolated through ``waypoints``; ``volumes`` overrides per index."""

    volumes = volumes or {}
    bars: list[Bar] = []
    for (i0, c0), (i1, c1) in pairwise(waypoints):
        for index in range(i0, i1):
            close = c0 + (c1 - c0) * (index - i0) / (i1 - i0)
            bars.append(_bar(index, close, volumes.get(index, base_volume)))
    last_index, last_close = waypoints[-1]
    bars.append(_bar(last_index, last_close, volumes.get(last_index, base_volume)))
    return bars


def _bar(index: int, close: float, volume: int) -> Bar:
    return Bar(
        date=START + timedelta(days=index),
        open=close,
        high=close * 1.002,
        low=close * 0.998,
        close=close,
        volume=volume,
    )


def found(bars: list[Bar], pattern_type: str):
    matches = [pattern for pattern in detect_patterns(bars) if pattern.type == pattern_type]
    assert len(matches) <= 1
    return matches[0] if matches else None


# --- swings -----------------------------------------------------------------


def test_swings_are_the_turning_waypoints_and_never_the_last_bars():
    bars = series([(0, 50), (20, 60), (40, 55), (60, 70), (63, 72)])
    assert swing_highs(bars) == [20]
    assert swing_lows(bars) == [40]


def test_ties_are_not_swings():
    bars = series([(0, 50), (20, 60), (40, 50)])
    flat = [bar.model_copy() for bar in bars]
    flat[21] = flat[20].model_copy(update={"date": flat[21].date})
    assert 20 not in swing_highs(flat)


# --- double_top ---------------------------------------------------------------

DOUBLE_TOP = [(0, 70), (80, 100), (110, 85), (140, 100.5)]


def test_double_top_forming():
    pattern = found(series(DOUBLE_TOP + [(150, 95)]), "double_top")
    assert pattern is not None
    assert pattern.status == "forming"
    assert [point.role for point in pattern.points] == ["left_peak", "trough", "right_peak"]
    assert pattern.breakout_level == pytest.approx(85 * 0.998)
    assert pattern.start_date == START + timedelta(days=80)
    assert pattern.end_date == START + timedelta(days=140)
    assert pattern.as_of_date == START + timedelta(days=150)


def test_double_top_confirmed_then_gone():
    confirmed = found(series(DOUBLE_TOP + [(165, 80)]), "double_top")
    assert confirmed is not None and confirmed.status == "confirmed"
    assert "breakout_close" in confirmed.metadata
    assert found(series(DOUBLE_TOP + [(185, 70)]), "double_top") is None


def test_double_top_invalidated_by_a_close_above_the_tops():
    assert found(series(DOUBLE_TOP + [(147, 96), (160, 104.5)]), "double_top") is None


def test_double_top_peaks_must_be_the_highest_of_their_span():
    # A higher high between the two peaks makes them not a double top.
    assert found(series([(0, 70), (80, 100), (95, 86), (105, 104), (120, 85), (140, 100.5), (150, 95)]), "double_top") is None


def test_double_top_prior_rise_lookback_includes_t1():
    # Prior lows (from index 20) are too high for a 15% rise; only the long
    # lower wick of the first peak's own bar (t1 = 80) satisfies it.
    waypoints = [(0, 90), (80, 100), (110, 85), (140, 100.5), (150, 95)]
    assert found(series(waypoints), "double_top") is None
    bars = series(waypoints)
    bars[80] = bars[80].model_copy(update={"low": 86.5})
    pattern = found(bars, "double_top")
    assert pattern is not None and pattern.points[0].role == "left_peak"
    assert pattern.points[0].date == START + timedelta(days=80)


def test_double_top_near_misses():
    assert found(series([(0, 70), (80, 100), (110, 85), (140, 105.5), (150, 100)]), "double_top") is None  # 5% apart
    assert found(series([(0, 70), (80, 100), (110, 93), (140, 100.5), (150, 97)]), "double_top") is None  # 7% trough
    assert found(series([(0, 95), (80, 100), (110, 85), (140, 100.5), (150, 95)]), "double_top") is None  # no prior rise
    assert found(series([(0, 70), (80, 100), (88, 85), (90, 100.5), (100, 95)]), "double_top") is None  # tops too close


# --- double_bottom ------------------------------------------------------------

DOUBLE_BOTTOM = [(0, 130), (80, 100), (110, 115), (140, 99.5)]


def test_double_bottom_forming_confirmed_gone_invalidated():
    forming = found(series(DOUBLE_BOTTOM + [(150, 105)]), "double_bottom")
    assert forming is not None and forming.status == "forming"
    assert [point.role for point in forming.points] == ["left_low", "peak", "right_low"]
    assert forming.breakout_level == pytest.approx(115 * 1.002)

    confirmed = found(series(DOUBLE_BOTTOM + [(165, 122)]), "double_bottom")
    assert confirmed is not None and confirmed.status == "confirmed"

    assert found(series(DOUBLE_BOTTOM + [(185, 135)]), "double_bottom") is None
    assert found(series(DOUBLE_BOTTOM + [(147, 104), (160, 95.5)]), "double_bottom") is None


def test_double_bottom_lows_must_be_the_lowest_of_their_span():
    # A deeper low between the two lows (a U, not a W) is not a double bottom.
    assert found(series([(0, 130), (80, 100), (95, 112), (105, 92), (120, 115), (140, 99.5), (150, 105)]), "double_bottom") is None


def test_double_bottom_near_miss_without_prior_decline():
    assert found(series([(0, 104), (80, 100), (110, 115), (140, 99.5), (150, 105)]), "double_bottom") is None


# --- cup_with_handle ----------------------------------------------------------

CUP = [(0, 70), (40, 100), (90, 75), (140, 99), (148, 94)]


def test_cup_with_handle_forming():
    pattern = found(series(CUP + [(152, 97)]), "cup_with_handle")
    assert pattern is not None and pattern.status == "forming"
    assert [point.role for point in pattern.points] == ["rim_left", "cup_low", "rim_right", "handle_low"]
    assert pattern.breakout_level == pytest.approx(99 * 1.002)


def test_cup_with_handle_confirmed_needs_volume():
    waypoints = CUP + [(152, 97), (156, 101)]
    with_volume = found(series(waypoints, volumes={155: 3000}), "cup_with_handle")
    assert with_volume is not None and with_volume.status == "confirmed"
    assert with_volume.metadata["breakout_close"] == pytest.approx(100)

    # calibration fix: a close above the rim without the volume confirmation
    # is not a handle any more (it used to stay ``forming``).
    assert found(series(waypoints), "cup_with_handle") is None


def test_cup_handle_high_capped_above_the_rim():
    rim = 99 * 1.002
    within_cap = series(CUP + [(152, 97)])
    within_cap[150] = within_cap[150].model_copy(update={"high": rim * 1.029})
    assert found(within_cap, "cup_with_handle") is not None

    over_cap = series(CUP + [(152, 97)])
    over_cap[150] = over_cap[150].model_copy(update={"high": rim * 1.031})
    assert found(over_cap, "cup_with_handle") is None


def test_cup_invalidated_by_a_close_below_the_handle_low_after_the_breakout():
    volumes = {155: 3000}
    still_confirmed = found(series(CUP + [(152, 97), (156, 101), (160, 96)], volumes=volumes), "cup_with_handle")
    assert still_confirmed is not None and still_confirmed.status == "confirmed"

    # The handle low is 94 * 0.998; a later close below it voids the breakout.
    assert found(series(CUP + [(152, 97), (156, 101), (160, 93)], volumes=volumes), "cup_with_handle") is None


def test_handle_sessions_exclude_the_breakout_bar():
    forming = found(series(CUP + [(152, 97)]), "cup_with_handle")
    assert forming is not None and forming.metadata["handle_sessions"] == 12.0  # bars 141..152

    confirmed = found(series(CUP + [(152, 97), (156, 101)], volumes={155: 3000}), "cup_with_handle")
    assert confirmed is not None and confirmed.metadata["handle_sessions"] == 14.0  # bars 141..154, breakout 155


def test_cup_rims_must_be_the_highest_of_the_cup():
    # A hump above the rims inside the cup is not a cup.
    assert found(series([(0, 70), (40, 100), (70, 78), (90, 106), (115, 80), (140, 99), (148, 94), (152, 97)]), "cup_with_handle") is None
    # A hump above the lower rim (99) but below the higher one (100) breaks the
    # big cup; only the smaller, valid cup that starts at the hump remains.
    smaller = found(series([(0, 70), (40, 100), (70, 78), (90, 99.7), (115, 80), (140, 99), (148, 94), (152, 97)]), "cup_with_handle")
    assert smaller is not None
    assert smaller.points[0].role == "rim_left"
    assert smaller.points[0].date == START + timedelta(days=90)


def test_cup_with_handle_near_misses():
    assert found(series([(0, 70), (40, 100), (90, 60), (140, 99), (148, 94), (152, 97)]), "cup_with_handle") is None  # 40% deep
    assert found(series([(0, 70), (40, 100), (90, 75), (140, 99), (148, 84), (152, 88)]), "cup_with_handle") is None  # handle too deep
    assert found(series([(0, 70), (40, 100), (50, 75), (140, 99), (148, 94), (152, 97)]), "cup_with_handle") is None  # V on the left
    assert found(series([(0, 70), (40, 100), (90, 75), (140, 99), (148, 94), (170, 96)]), "cup_with_handle") is None  # handle too long


# --- bull_flag ----------------------------------------------------------------

FLAG = [(0, 50), (60, 60), (70, 57), (80, 70), (92, 66)]
POLE_VOLUME = {index: 3000 for index in range(70, 81)}


def test_bull_flag_forming_then_confirmed():
    forming = found(series(FLAG, volumes=POLE_VOLUME), "bull_flag")
    assert forming is not None and forming.status == "forming"
    assert [point.role for point in forming.points] == ["pole_start", "pole_top", "flag_low"]

    confirmed = found(series(FLAG + [(97, 72)], volumes=POLE_VOLUME), "bull_flag")
    assert confirmed is not None and confirmed.status == "confirmed"


def test_flag_sessions_exclude_the_breakout_bar():
    forming = found(series(FLAG, volumes=POLE_VOLUME), "bull_flag")
    assert forming is not None and forming.metadata["flag_sessions"] == 12.0  # bars 81..92

    confirmed = found(series(FLAG + [(97, 72)], volumes=POLE_VOLUME), "bull_flag")
    assert confirmed is not None and confirmed.metadata["flag_sessions"] == 15.0  # bars 81..95, breakout 96


def test_bull_flag_invalidated_by_a_close_below_the_flag_low_after_the_breakout():
    still_confirmed = found(series(FLAG + [(97, 72), (101, 68)], volumes=POLE_VOLUME), "bull_flag")
    assert still_confirmed is not None and still_confirmed.status == "confirmed"

    # The flag low is 66 * 0.998; a later close below it voids the breakout.
    assert found(series(FLAG + [(97, 72), (103, 65)], volumes=POLE_VOLUME), "bull_flag") is None


def test_flag_never_rises_above_the_pole_top():
    bars = series(FLAG, volumes=POLE_VOLUME)
    assert found(bars, "bull_flag") is not None
    # An intraday spike above the pole top inside the flag (close still lower).
    bars[86] = bars[86].model_copy(update={"high": 71.0})
    assert found(bars, "bull_flag") is None


def test_bull_flag_near_misses():
    assert found(series([(0, 50), (60, 60), (70, 57), (80, 62.6), (92, 60)], volumes=POLE_VOLUME), "bull_flag") is None  # pole +10%
    assert found(series([(0, 50), (60, 60), (70, 57), (80, 70), (92, 61)], volumes=POLE_VOLUME), "bull_flag") is None  # retrace > 50%
    assert found(series(FLAG), "bull_flag") is None  # flag volume not lower than the pole
    assert found(series([(0, 50), (60, 60), (70, 57), (80, 70), (85, 66), (92, 69.5)], volumes=POLE_VOLUME), "bull_flag") is None  # rising flag


# --- detector + endpoint ------------------------------------------------------


def test_too_few_bars_detect_nothing():
    assert detect_patterns(series([(0, 50), (30, 60)])) == []


def test_patterns_endpoint_returns_the_active_set():
    client = TestClient(main_module.app)
    bars = series(DOUBLE_TOP + [(150, 95)])
    response = client.post("/patterns/detect", json={"bars": [bar.model_dump(mode="json") for bar in bars]})
    assert response.status_code == 200
    types = [pattern["type"] for pattern in response.json()["patterns"]]
    assert "double_top" in types


def test_patterns_endpoint_rejects_malformed_input():
    client = TestClient(main_module.app)
    assert client.post("/patterns/detect", json={"bars": [{"date": "nope"}]}).status_code == 422


# --- near misses (calibration review aid) -------------------------------------


def test_near_miss_reports_the_single_rule_that_rejects_an_instance():
    # Tops 3.8% apart: rejected only by the 3% peak tolerance.
    bars = series([(0, 70), (80, 100), (110, 85), (140, 104), (150, 98)])
    assert found(bars, "double_top") is None
    misses = {miss.pattern.type: miss for miss in detect_near_misses(bars)}
    assert misses["double_top"].rule == "peak_tolerance"
    assert misses["double_top"].production_value == pytest.approx(0.03)
    assert misses["double_top"].relaxed_value == pytest.approx(0.05)
    assert [point.role for point in misses["double_top"].pattern.points] == ["left_peak", "trough", "right_peak"]


def test_near_miss_flag_without_lower_volume_and_none_for_detected_types():
    bars = series(FLAG)  # flag volume equals the pole volume
    misses = {miss.pattern.type: miss for miss in detect_near_misses(bars)}
    assert misses["bull_flag"].rule == "lower_volume"

    detected = series(DOUBLE_TOP + [(150, 95)])
    assert found(detected, "double_top") is not None
    assert "double_top" not in {miss.pattern.type for miss in detect_near_misses(detected)}


def test_near_misses_never_change_the_detected_set():
    bars = series([(0, 70), (80, 100), (110, 85), (140, 104), (150, 98)])
    before = detect_patterns(bars)
    detect_near_misses(bars)
    assert detect_patterns(bars) == before
    assert detect_near_misses(series([(0, 50), (30, 60)])) == []


def test_near_misses_and_parameters_endpoints():
    client = TestClient(main_module.app)
    bars = series([(0, 70), (80, 100), (110, 85), (140, 104), (150, 98)])
    response = client.post("/patterns/near-misses", json={"bars": [bar.model_dump(mode="json") for bar in bars]})
    assert response.status_code == 200
    rules = {item["pattern"]["type"]: item["rule"] for item in response.json()["near_misses"]}
    assert rules["double_top"] == "peak_tolerance"
    assert client.post("/patterns/near-misses", json={"bars": [{"date": "nope"}]}).status_code == 422

    parameters = client.get("/patterns/parameters").json()["parameters"]
    assert parameters["double_peak_tolerance"] == pytest.approx(0.03)
    assert parameters["handle_max_rise"] == pytest.approx(0.03)
    assert parameters["cup_extremes"] == 1.0
