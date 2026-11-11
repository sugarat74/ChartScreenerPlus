"""Conservative Head-and-Shoulders and triangle geometry detectors."""

from app.models import Bar, Pattern, PatternPoint
from app.patterns.rules import DEFAULT_PARAMS, Detector
from app.patterns.swings import swing_highs, swing_lows

# Named geometry limits. These are deterministic defaults, not empirically
# calibrated thresholds; docs/specs/chart-patterns-head-shoulders-triangles.md
# records the missing labeled real sample.
HS_MAX_AGE = 100
HS_MIN_SPACING = 5
HS_MAX_SPACING = 35
HS_SHOULDER_TOLERANCE = 0.08
HS_HEAD_CLEARANCE = 0.04
HS_INVALIDATION_BUFFER = 0.0

TRIANGLE_MIN_DURATION = 20
TRIANGLE_MAX_DURATION = 100
TRIANGLE_ACTIVE_AGE = 30
TRIANGLE_FLAT_TOLERANCE = 0.03  # total percentage move between the two pivots
TRIANGLE_MIN_CONVERGENCE = 0.20  # range shrinks by at least 20% across the formation
TRIANGLE_MAX_APEX_DISTANCE = 250
BREAKOUT_SESSIONS = 10
MIN_DETECTION_BARS = 60


def _line(a: int, av: float, b: int, bv: float, x: int) -> float:
    return av + (bv - av) * (x - a) / (b - a)


def _make(
    bars: list[Bar],
    *,
    typ: str,
    direction: str | None,
    status: str,
    start: int,
    end: int,
    level: float | None,
    points: list[tuple[int, float, str]],
    metadata: dict[str, float],
) -> Pattern:
    return Pattern(
        type=typ,
        direction=direction,
        status=status,
        as_of_date=bars[-1].date,
        start_date=bars[start].date,
        end_date=bars[end].date,
        breakout_level=level,
        points=[PatternPoint(date=bars[index].date, price=price, role=role) for index, price, role in points],
        metadata=metadata,
    )


def _head_shoulders(bars: list[Bar], highs_side: bool, start: int) -> Pattern | None:
    if len(bars) < MIN_DETECTION_BARS:
        return None

    pivots = [j for j in (swing_highs(bars) if highs_side else swing_lows(bars)) if j >= start]
    current = len(bars) - 1
    for offset in range(len(pivots) - 3, -1, -1):
        left, head, right = pivots[offset : offset + 3]
        if (
            current - right > HS_MAX_AGE
            or not HS_MIN_SPACING <= head - left <= HS_MAX_SPACING
            or not HS_MIN_SPACING <= right - head <= HS_MAX_SPACING
        ):
            continue

        field = "high" if highs_side else "low"
        extremes = [float(getattr(bars[index], field)) for index in (left, head, right)]
        shoulder_reference = max(abs(extremes[0]), abs(extremes[2]))
        if shoulder_reference <= 0 or abs(extremes[0] - extremes[2]) / shoulder_reference > HS_SHOULDER_TOLERANCE:
            continue
        if highs_side:
            if extremes[1] <= shoulder_reference * (1 + HS_HEAD_CLEARANCE):
                continue
            trough1 = min(range(left + 1, head), key=lambda x: bars[x].low)
            trough2 = min(range(head + 1, right), key=lambda x: bars[x].low)
            neck1, neck2 = float(bars[trough1].low), float(bars[trough2].low)
            direction = "bearish"
            invalidation_level = extremes[1] * (1 + HS_INVALIDATION_BUFFER)
            invalidates = lambda close: close > invalidation_level
            breaks = lambda close, boundary: close < boundary
            point_roles = ((left, extremes[0], "left_shoulder"), (head, extremes[1], "head"),
                           (right, extremes[2], "right_shoulder"), (trough1, neck1, "neckline_start"),
                           (trough2, neck2, "neckline_end"))
        else:
            if extremes[1] >= shoulder_reference * (1 - HS_HEAD_CLEARANCE):
                continue
            peak1 = max(range(left + 1, head), key=lambda x: bars[x].high)
            peak2 = max(range(head + 1, right), key=lambda x: bars[x].high)
            neck1, neck2 = float(bars[peak1].high), float(bars[peak2].high)
            direction = "bullish"
            invalidation_level = extremes[1] * (1 - HS_INVALIDATION_BUFFER)
            invalidates = lambda close: close < invalidation_level
            breaks = lambda close, boundary: close > boundary
            point_roles = ((left, extremes[0], "left_shoulder"), (head, extremes[1], "head"),
                           (right, extremes[2], "right_shoulder"), (peak1, neck1, "neckline_start"),
                           (peak2, neck2, "neckline_end"))

        neckline_start = trough1 if highs_side else peak1
        neckline_end = trough2 if highs_side else peak2
        breakout_index: int | None = None
        breakout_level: float | None = None
        invalidated = False
        # The first close beyond the neckline starts the fixed 10-session
        # confirmation window. Later closes beyond it cannot renew that age.
        for index in range(right + 1, current + 1):
            close = float(bars[index].close)
            boundary = _line(neckline_start, neck1, neckline_end, neck2, index)
            if invalidates(close):
                invalidated = True
                break
            if breakout_index is None and breaks(close, boundary):
                breakout_index = index
                breakout_level = boundary

        if invalidated:
            continue
        confirmed = breakout_index is not None and current - breakout_index < BREAKOUT_SESSIONS
        if breakout_index is not None and not confirmed:
            continue

        return _make(
            bars,
            typ="head_and_shoulders" if highs_side else "inverse_head_and_shoulders",
            direction=direction,
            status="confirmed" if confirmed else "forming",
            start=left,
            end=current,
            level=breakout_level if confirmed else None,
            points=list(point_roles),
            metadata={"neckline_slope": (neck2 - neck1) / (neckline_end - neckline_start)},
        )
    return None


def _triangle(bars: list[Bar], start: int, pattern_type: str) -> Pattern | None:
    if len(bars) < MIN_DETECTION_BARS:
        return None

    current = len(bars) - 1
    highs = [j for j in swing_highs(bars) if j >= start]
    lows = [j for j in swing_lows(bars) if j >= start]
    if len(highs) < 2 or len(lows) < 2:
        return None

    high1, high2 = highs[-2:]
    low1, low2 = lows[-2:]
    ordered_roles = [role for _, role in sorted(((high1, "high"), (high2, "high"), (low1, "low"), (low2, "low")))]
    if ordered_roles not in (["high", "low", "high", "low"], ["low", "high", "low", "high"]):
        return None

    first, last = min(high1, low1), max(high2, low2)
    duration = last - first
    if not TRIANGLE_MIN_DURATION <= duration <= TRIANGLE_MAX_DURATION or current - last > TRIANGLE_ACTIVE_AGE:
        return None

    high_value1, high_value2 = float(bars[high1].high), float(bars[high2].high)
    low_value1, low_value2 = float(bars[low1].low), float(bars[low2].low)
    upper_slope = (high_value2 - high_value1) / (high2 - high1)
    lower_slope = (low_value2 - low_value1) / (low2 - low1)
    high_reference = max(abs(high_value1), abs(high_value2))
    low_reference = max(abs(low_value1), abs(low_value2))
    high_change = (high_value2 - high_value1) / high_reference if high_reference else 0.0
    low_change = (low_value2 - low_value1) / low_reference if low_reference else 0.0

    if pattern_type == "ascending_triangle":
        if abs(high_change) > TRIANGLE_FLAT_TOLERANCE or low_change <= 0:
            return None
    elif pattern_type == "descending_triangle":
        if abs(low_change) > TRIANGLE_FLAT_TOLERANCE or high_change >= 0:
            return None
    elif pattern_type == "symmetrical_triangle":
        if high_change >= 0 or low_change <= 0:
            return None
    else:
        return None

    upper_first = _line(high1, high_value1, high2, high_value2, first)
    lower_first = _line(low1, low_value1, low2, low_value2, first)
    upper_last = _line(high1, high_value1, high2, high_value2, last)
    lower_last = _line(low1, low_value1, low2, low_value2, last)
    starting_range, ending_range = upper_first - lower_first, upper_last - lower_last
    if starting_range <= 0 or ending_range <= 0 or ending_range > starting_range * (1 - TRIANGLE_MIN_CONVERGENCE):
        return None
    if lower_slope <= upper_slope:
        return None
    apex_distance = ending_range / (lower_slope - upper_slope)
    if not 0 < apex_distance <= TRIANGLE_MAX_APEX_DISTANCE:
        return None

    breakout: tuple[int, str, float] | None = None
    for index in range(last, current + 1):
        upper = _line(high1, high_value1, high2, high_value2, index)
        lower = _line(low1, low_value1, low2, low_value2, index)
        close = float(bars[index].close)
        if close > upper:
            breakout = (index, "bullish", upper)
            break
        if close < lower:
            breakout = (index, "bearish", lower)
            break

    confirmed = breakout is not None and current - breakout[0] < BREAKOUT_SESSIONS
    if breakout is not None:
        breakout_index, direction, breakout_level = breakout
        for index in range(breakout_index + 1, current + 1):
            upper = _line(high1, high_value1, high2, high_value2, index)
            lower = _line(low1, low_value1, low2, low_value2, index)
            close = float(bars[index].close)
            if (direction == "bullish" and close < lower) or (direction == "bearish" and close > upper):
                return None
        if not confirmed:
            return None
    else:
        direction = None
        breakout_level = None

    return _make(
        bars,
        typ=pattern_type,
        direction=direction if confirmed else None,
        status="confirmed" if confirmed else "forming",
        start=first,
        end=current,
        level=breakout_level if confirmed else None,
        points=[(high1, high_value1, "upper_start"), (high2, high_value2, "upper_end"),
                (low1, low_value1, "lower_start"), (low2, low_value2, "lower_end")],
        metadata={"upper_slope": upper_slope, "lower_slope": lower_slope},
    )


def head_and_shoulders(bars, _rvol, start, _params=DEFAULT_PARAMS):
    return _head_shoulders(bars, True, start)


def inverse_head_and_shoulders(bars, _rvol, start, _params=DEFAULT_PARAMS):
    return _head_shoulders(bars, False, start)


def ascending_triangle(bars, _rvol, start, _params=DEFAULT_PARAMS):
    return _triangle(bars, start, "ascending_triangle")


def descending_triangle(bars, _rvol, start, _params=DEFAULT_PARAMS):
    return _triangle(bars, start, "descending_triangle")


def symmetrical_triangle(bars, _rvol, start, _params=DEFAULT_PARAMS):
    return _triangle(bars, start, "symmetrical_triangle")


DETECTORS: tuple[tuple[str, Detector], ...] = (
    ("head_and_shoulders", head_and_shoulders),
    ("inverse_head_and_shoulders", inverse_head_and_shoulders),
    ("ascending_triangle", ascending_triangle),
    ("descending_triangle", descending_triangle),
    ("symmetrical_triangle", symmetrical_triangle),
)
