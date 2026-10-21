"""Detect the chartist patterns active on the as-of (latest) bar of a series.

Only the last ``WINDOW`` bars are scanned for pattern points (prior-trend checks
may read earlier bars). The engine owns the rules and never touches the
database: Laravel persists (replaces) the returned set.
"""

from app.indicators.core import rvol
from app.models import Bar, Pattern
from app.patterns.extended import ALL_DETECTORS

WINDOW = 330
MIN_BARS = 60
RVOL_PERIOD = 50


def detect_patterns(bars: list[Bar]) -> list[Pattern]:
    """Return at most one active pattern per type, in vocabulary order."""

    if len(bars) < MIN_BARS:
        return []

    volume_ratios = rvol([float(bar.volume) for bar in bars], RVOL_PERIOD)
    start = max(0, len(bars) - WINDOW)

    detected: list[Pattern] = []
    for _pattern_type, detector in ALL_DETECTORS:
        pattern = detector(bars, volume_ratios, start)
        if pattern is not None:
            detected.append(pattern)
    return apply_precedence(detected)


# (more specific type, type it hides, roles that must share dates). A triple
# hides the double whose two extremes are among its three; a pennant hides
# the flag of the same direction on the same pole (chart-patterns-extended).
PRECEDENCE: tuple[tuple[str, str, tuple[str, ...], tuple[str, ...]], ...] = (
    ("triple_top", "double_top", ("left_peak", "middle_peak", "third_peak"), ("left_peak", "right_peak")),
    ("triple_bottom", "double_bottom", ("left_low", "middle_low", "third_low"), ("left_low", "right_low")),
    ("bull_pennant", "bull_flag", ("pole_top",), ("pole_top",)),
    ("bear_pennant", "bear_flag", ("pole_bottom",), ("pole_bottom",)),
)


def apply_precedence(patterns: list[Pattern]) -> list[Pattern]:
    """Drop a pattern that only restates part of a more specific one."""

    by_type = {pattern.type: pattern for pattern in patterns}
    hidden: set[str] = set()
    for winner, loser, winner_roles, loser_roles in PRECEDENCE:
        if winner not in by_type or loser not in by_type:
            continue
        winner_dates = {point.date for point in by_type[winner].points if point.role in winner_roles}
        loser_dates = {point.date for point in by_type[loser].points if point.role in loser_roles}
        if loser_dates and loser_dates <= winner_dates:
            hidden.add(loser)
    return [pattern for pattern in patterns if pattern.type not in hidden]
