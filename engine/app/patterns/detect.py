"""Detect the chartist patterns active on the as-of (latest) bar of a series.

Only the last ``WINDOW`` bars are scanned for pattern points (prior-trend checks
may read earlier bars). The engine owns the rules and never touches the
database: Laravel persists (replaces) the returned set.
"""

from app.indicators.core import rvol
from app.models import Bar, Pattern
from app.patterns.rules import DETECTORS

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
    for _pattern_type, detector in DETECTORS:
        pattern = detector(bars, volume_ratios, start)
        if pattern is not None:
            detected.append(pattern)
    return detected
