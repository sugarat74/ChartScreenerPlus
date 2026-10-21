"""Offline tests for the second batch of chartist patterns (chart-patterns-extended).

Same synthetic series as ``test_patterns.py``: closes interpolated between
waypoints, highs/lows 0.2% around the close. Each new type has a textbook
positive case, near misses that break one rule, status transitions, and the
precedence rules (triple over double, pennant over flag).
"""

import pytest

from app.indicators.core import rvol
from app.patterns.detect import detect_patterns
from app.patterns.extended import BEARISH, BULLISH, bull_pennant
from app.patterns.rules import DETECTORS, bull_flag, double_bottom, double_top
from tests.test_patterns import FLAG, POLE_VOLUME, found, series


def run(detector, bars):
    """Run one detector alone (no precedence), as ``detect_patterns`` does."""

    return detector(bars, rvol([float(bar.volume) for bar in bars], 50), max(0, len(bars) - 330))


def test_vocabulary_order_and_direction():
    assert [name for name, _ in DETECTORS] == ["double_top", "double_bottom", "cup_with_handle", "bull_flag"]
    assert sorted(BULLISH + BEARISH) == sorted(
        [
            "double_top",
            "double_bottom",
            "cup_with_handle",
            "bull_flag",
            "triple_top",
            "triple_bottom",
            "inverse_cup_with_handle",
            "bear_flag",
            "bull_pennant",
            "bear_pennant",
        ]
    )


# --- triple_top / triple_bottom -------------------------------------------------

TRIPLE_TOP = [(0, 70), (80, 100), (105, 85), (130, 100.5), (155, 85.5), (180, 100.2)]
TRIPLE_BOTTOM = [(0, 130), (80, 100), (105, 115), (130, 99.5), (155, 114.5), (180, 99.8)]


def test_triple_top_forming_confirmed_gone_invalidated():
    forming = found(series(TRIPLE_TOP + [(190, 95)]), "triple_top")
    assert forming is not None and forming.status == "forming"
    assert [point.role for point in forming.points] == [
        "left_peak",
        "left_trough",
        "middle_peak",
        "right_trough",
        "right_peak",
    ]
    # The neckline is the lower of the two troughs.
    assert forming.breakout_level == pytest.approx(85 * 0.998)

    confirmed = found(series(TRIPLE_TOP + [(195, 80)]), "triple_top")
    assert confirmed is not None and confirmed.status == "confirmed"
    assert "breakout_close" in confirmed.metadata

    assert found(series(TRIPLE_TOP + [(215, 70)]), "triple_top") is None  # breakout too old
    assert found(series(TRIPLE_TOP + [(190, 95), (200, 101)]), "triple_top") is None  # close above


def test_triple_bottom_forming_confirmed_invalidated():
    forming = found(series(TRIPLE_BOTTOM + [(190, 105)]), "triple_bottom")
    assert forming is not None and forming.status == "forming"
    assert [point.role for point in forming.points] == [
        "left_low",
        "left_crest",
        "middle_low",
        "right_crest",
        "right_low",
    ]
    # The neckline is the higher of the two crests.
    assert forming.breakout_level == pytest.approx(115 * 1.002)

    confirmed = found(series(TRIPLE_BOTTOM + [(195, 120)]), "triple_bottom")
    assert confirmed is not None and confirmed.status == "confirmed"
    assert found(series(TRIPLE_BOTTOM + [(190, 105), (200, 99)]), "triple_bottom") is None


def test_triple_near_misses():
    # Third top 5% above the others.
    assert found(series(TRIPLE_TOP[:-1] + [(180, 105.5), (190, 100)]), "triple_top") is None
    # Second gap of 10 sessions.
    assert (
        found(series([(0, 70), (80, 100), (105, 85), (130, 100.5), (135, 85.5), (140, 100.2), (150, 95)]), "triple_top")
        is None
    )
    # A shallow (6%) second trough.
    assert found(series(TRIPLE_TOP[:-2] + [(155, 94.5), (180, 100.2), (190, 95)]), "triple_top") is None
    # A higher high between the tops.
    assert (
        found(series(TRIPLE_TOP[:3] + [(118, 104), (130, 100.5), (155, 85.5), (180, 100.2), (190, 95)]), "triple_top")
        is None
    )
    # No prior decline before a triple bottom.
    assert found(series([(0, 104)] + TRIPLE_BOTTOM[1:] + [(190, 105)]), "triple_bottom") is None


def test_triple_hides_the_double_on_two_of_its_extremes():
    bars = series(TRIPLE_TOP + [(190, 95)])
    assert run(double_top, bars) is not None  # the double rules alone also match
    types = [pattern.type for pattern in detect_patterns(bars)]
    assert "triple_top" in types and "double_top" not in types

    bars = series(TRIPLE_BOTTOM + [(190, 105)])
    assert run(double_bottom, bars) is not None
    types = [pattern.type for pattern in detect_patterns(bars)]
    assert "triple_bottom" in types and "double_bottom" not in types


# --- inverse_cup_with_handle ----------------------------------------------------

INVERSE_CUP = [(0, 130), (40, 100), (90, 125), (140, 101), (148, 106)]


def test_inverse_cup_forming():
    pattern = found(series(INVERSE_CUP + [(152, 103)]), "inverse_cup_with_handle")
    assert pattern is not None and pattern.status == "forming"
    assert [point.role for point in pattern.points] == ["rim_left_low", "cup_top", "rim_right_low", "handle_high"]
    assert pattern.breakout_level == pytest.approx(101 * 0.998)
    assert pattern.metadata["handle_sessions"] == 12.0


def test_inverse_cup_confirmed_needs_volume_and_is_invalidated_above_the_handle():
    waypoints = INVERSE_CUP + [(152, 103), (156, 99)]
    with_volume = found(series(waypoints, volumes={155: 3000}), "inverse_cup_with_handle")
    assert with_volume is not None and with_volume.status == "confirmed"
    assert with_volume.metadata["breakout_close"] == pytest.approx(100)

    # A close below the rim without the volume confirmation is not a handle.
    assert found(series(waypoints), "inverse_cup_with_handle") is None

    volumes = {155: 3000}
    still = found(series(waypoints + [(160, 104)], volumes=volumes), "inverse_cup_with_handle")
    assert still is not None and still.status == "confirmed"
    # The handle high is 106 * 1.002; a later close above it voids the breakout.
    assert found(series(waypoints + [(160, 107)], volumes=volumes), "inverse_cup_with_handle") is None


def test_inverse_cup_near_misses():
    # 40% tall.
    assert found(series([(0, 130), (40, 100), (90, 140), (140, 101), (148, 106), (152, 103)]), "inverse_cup_with_handle") is None
    # Rims 8% apart.
    assert found(series([(0, 130), (40, 100), (90, 125), (140, 108), (148, 113), (152, 110)]), "inverse_cup_with_handle") is None
    # A handle rising more than 12% above the right rim.
    assert found(series(INVERSE_CUP[:-1] + [(148, 114), (152, 112)]), "inverse_cup_with_handle") is None
    # A dip below the lower rim inside the cup breaks the big cup; only the
    # smaller cup that starts at the dip may remain.
    dip = series([(0, 130), (40, 100), (65, 120), (75, 97), (95, 125), (140, 101), (148, 106), (152, 103)])
    pattern = found(dip, "inverse_cup_with_handle")
    assert pattern is None or pattern.points[0].date == dip[75].date


# --- bear_flag ------------------------------------------------------------------

BEAR_FLAG = [(0, 70), (60, 60), (70, 63), (80, 50), (92, 54)]


def test_bear_flag_forming_confirmed_invalidated():
    forming = found(series(BEAR_FLAG, volumes=POLE_VOLUME), "bear_flag")
    assert forming is not None and forming.status == "forming"
    assert [point.role for point in forming.points] == ["pole_peak", "pole_bottom", "flag_high"]
    assert forming.metadata["flag_sessions"] == 12.0
    assert forming.metadata["pole_drop_pct"] > 15

    confirmed = found(series(BEAR_FLAG + [(97, 48)], volumes=POLE_VOLUME), "bear_flag")
    assert confirmed is not None and confirmed.status == "confirmed"
    # The breakout level is the flag low (just after the pole bottom).
    assert confirmed.breakout_level < 51

    # A later close above the flag high voids the breakout.
    assert found(series(BEAR_FLAG + [(97, 48), (103, 55)], volumes=POLE_VOLUME), "bear_flag") is None


def test_bear_flag_near_misses():
    # Pole of only -10%.
    assert found(series([(0, 70), (60, 60), (70, 62), (80, 55.8), (92, 58)], volumes=POLE_VOLUME), "bear_flag") is None
    # The flag retraces more than half of the pole.
    assert found(series(BEAR_FLAG[:-1] + [(92, 57.5)], volumes=POLE_VOLUME), "bear_flag") is None
    # Closes drifting down with the pole instead of against it.
    assert found(series(BEAR_FLAG[:-1] + [(83, 53), (92, 50.5)], volumes=POLE_VOLUME), "bear_flag") is None
    # Flag volume not lower than the pole's.
    assert found(series(BEAR_FLAG), "bear_flag") is None


# --- bull_pennant / bear_pennant ------------------------------------------------

PENNANT_BODY = [(81, 68.9), (84, 66), (87, 68.5), (90, 66.8), (93, 68), (96, 67.2), (97, 67.3)]
BULL_PENNANT = FLAG[:-1] + PENNANT_BODY
BEAR_PENNANT = BEAR_FLAG[:-1] + [(81, 51.1), (84, 54), (87, 51.5), (90, 53.2), (93, 52), (96, 52.6), (97, 52.3)]


def test_bull_pennant_forming_confirmed_and_hides_the_flag():
    bars = series(BULL_PENNANT, volumes=POLE_VOLUME)
    forming = found(bars, "bull_pennant")
    assert forming is not None and forming.status == "forming"
    assert [point.role for point in forming.points] == ["pole_start", "pole_top", "pennant_low"]
    assert forming.metadata["width_ratio"] <= 0.7
    assert forming.metadata["pennant_sessions"] == 17.0

    # The flag rules alone also match the same pole; the pennant wins.
    flag = run(bull_flag, bars)
    assert flag is not None
    assert found(bars, "bull_flag") is None

    confirmed = found(series(BULL_PENNANT + [(98, 72)], volumes=POLE_VOLUME), "bull_pennant")
    assert confirmed is not None and confirmed.status == "confirmed"


def test_bear_pennant_forming_confirmed():
    forming = found(series(BEAR_PENNANT, volumes=POLE_VOLUME), "bear_pennant")
    assert forming is not None and forming.status == "forming"
    assert [point.role for point in forming.points] == ["pole_peak", "pole_bottom", "pennant_high"]
    confirmed = found(series(BEAR_PENNANT + [(98, 48)], volumes=POLE_VOLUME), "bear_pennant")
    assert confirmed is not None and confirmed.status == "confirmed"
    assert found(series(BEAR_PENNANT, volumes=POLE_VOLUME), "bear_flag") is None


def test_pennant_needs_converging_lines():
    # A parallel, down-sloping channel: a bull flag, not a pennant.
    channel = FLAG[:-1] + [(83, 66), (86, 68), (89, 65), (92, 67), (95, 64), (97, 66)]
    bars = series(channel, volumes=POLE_VOLUME)
    assert run(bull_pennant, bars) is None
    assert found(bars, "bull_flag") is not None
    # A widening consolidation.
    widening = FLAG[:-1] + [(83, 67.5), (86, 68), (89, 66.5), (92, 69), (95, 65.5), (97, 67)]
    assert run(bull_pennant, series(widening, volumes=POLE_VOLUME)) is None


def test_pennant_end_width_limit():
    # Converging, but the second half is still more than 70% as wide as the first.
    slow = FLAG[:-1] + [(81, 68.9), (84, 66), (87, 68.7), (90, 66.2), (93, 68.5), (96, 66.4), (97, 67)]
    pattern = run(bull_pennant, series(slow, volumes=POLE_VOLUME))
    assert pattern is None


def test_a_pole_ends_at_its_own_extreme():
    # From the low at bar 70 to the rally top at 87 is +21% in 17 sessions,
    # but the pole would pass the higher top at bar 80: not a pole.
    rally = FLAG[:-1] + [(81, 68.9), (84, 66), (87, 68.7), (90, 66.2), (93, 68.5), (96, 66.4), (97, 67)]
    bars = series(rally, volumes=POLE_VOLUME)
    for pattern in [run(bull_pennant, bars), run(bull_flag, bars)]:
        if pattern is not None:
            top = next(point for point in pattern.points if point.role == "pole_top")
            assert top.date == bars[80].date
