"""One detector per chartist pattern type (fixed contract, initial parameters).

Each detector scans the evaluation window for the most recent qualifying
instance that is still active on the as-of bar ``i`` (the last bar) and returns
it, or ``None``. ``forming`` means the geometry is complete and not invalidated
with no breakout yet; ``confirmed`` means the breakout close happened within the
last ``CONFIRM_SESSIONS`` sessions. Older breakouts and invalidated instances
are not active. The detectors never read bars after ``i`` and never compute a
target: ``breakout_level`` is the only price line a pattern exposes.

The parameters below are the initial values of docs/specs/chart-patterns-detect.md;
calibrating them is later work and must come with tests.
"""

from collections.abc import Callable

from app.models import Bar, Pattern, PatternPoint
from app.patterns.swings import swing_highs, swing_lows

CONFIRM_SESSIONS = 10

# double_top / double_bottom
DOUBLE_MIN_GAP = 15
DOUBLE_MAX_GAP = 120
DOUBLE_PEAK_TOLERANCE = 0.03
DOUBLE_MIN_DEPTH = 0.10
DOUBLE_PRIOR_LOOKBACK = 60
DOUBLE_PRIOR_MOVE = 0.15
DOUBLE_MAX_AGE = 60
DOUBLE_INVALIDATION = 0.03

# cup_with_handle
CUP_MIN_LENGTH = 30
CUP_MAX_LENGTH = 325
CUP_MIN_DEPTH = 0.12
CUP_MAX_DEPTH = 0.35
CUP_RIM_TOLERANCE = 0.05
CUP_LOW_POSITION = (0.20, 0.80)
HANDLE_MIN_LENGTH = 5
HANDLE_MAX_LENGTH = 25
HANDLE_MAX_DEPTH = 0.12
CUP_BREAKOUT_RVOL = 1.5

# bull_flag
POLE_MAX_LENGTH = 15
POLE_MIN_RISE = 0.15
FLAG_MIN_LENGTH = 5
FLAG_MAX_LENGTH = 20
FLAG_MAX_RETRACE = 0.5

Detector = Callable[[list[Bar], list[float | None], int], Pattern | None]


def double_top(bars: list[Bar], rvol: list[float | None], start: int) -> Pattern | None:
    """Two similar swing highs with a ≥10% trough between them after a rise."""

    i = len(bars) - 1
    highs = [j for j in swing_highs(bars) if j >= start]

    for b in range(len(highs) - 1, -1, -1):
        t2 = highs[b]
        if i - t2 > DOUBLE_MAX_AGE:
            break
        for a in range(b - 1, -1, -1):
            t1 = highs[a]
            gap = t2 - t1
            if gap < DOUBLE_MIN_GAP:
                continue
            if gap > DOUBLE_MAX_GAP:
                break
            h1, h2 = bars[t1].high, bars[t2].high
            top = max(h1, h2)
            if abs(h2 - h1) / top > DOUBLE_PEAK_TOLERANCE:
                continue
            if bars[_argmax_high(bars, t1, t2)].high > top:
                continue
            trough_index = _argmin_low(bars, t1, t2)
            trough = bars[trough_index].low
            lower_peak = min(h1, h2)
            if (lower_peak - trough) / lower_peak < DOUBLE_MIN_DEPTH:
                continue
            if t1 == 0:
                continue
            prior_low = min(bar.low for bar in bars[max(0, t1 - DOUBLE_PRIOR_LOOKBACK) : t1])
            if h1 < (1 + DOUBLE_PRIOR_MOVE) * prior_low:
                continue

            breakout, invalid = None, False
            for j in range(t2 + 1, i + 1):
                close = bars[j].close
                if close > top * (1 + DOUBLE_INVALIDATION):
                    invalid = True
                    break
                if breakout is None and close < trough:
                    breakout = j
            if invalid:
                continue
            status = _status(breakout, i)
            if status is None:
                continue

            return _pattern(
                "double_top",
                status,
                bars,
                i,
                breakout_level=trough,
                points=[(t1, h1, "left_peak"), (trough_index, trough, "trough"), (t2, h2, "right_peak")],
                metadata={
                    "depth_pct": round((lower_peak - trough) / lower_peak * 100, 4),
                    "gap_sessions": float(gap),
                },
                breakout=breakout,
            )
    return None


def double_bottom(bars: list[Bar], rvol: list[float | None], start: int) -> Pattern | None:
    """Mirror of ``double_top`` on swing lows after a decline."""

    i = len(bars) - 1
    lows = [j for j in swing_lows(bars) if j >= start]

    for b in range(len(lows) - 1, -1, -1):
        t2 = lows[b]
        if i - t2 > DOUBLE_MAX_AGE:
            break
        for a in range(b - 1, -1, -1):
            t1 = lows[a]
            gap = t2 - t1
            if gap < DOUBLE_MIN_GAP:
                continue
            if gap > DOUBLE_MAX_GAP:
                break
            l1, l2 = bars[t1].low, bars[t2].low
            bottom = min(l1, l2)
            if abs(l2 - l1) / max(l1, l2) > DOUBLE_PEAK_TOLERANCE:
                continue
            if bars[_argmin_low(bars, t1, t2)].low < bottom:
                continue
            peak_index = _argmax_high(bars, t1, t2)
            peak = bars[peak_index].high
            higher_low = max(l1, l2)
            if (peak - higher_low) / higher_low < DOUBLE_MIN_DEPTH:
                continue
            if t1 == 0:
                continue
            prior_high = max(bar.high for bar in bars[max(0, t1 - DOUBLE_PRIOR_LOOKBACK) : t1])
            if l1 > (1 - DOUBLE_PRIOR_MOVE) * prior_high:
                continue

            breakout, invalid = None, False
            for j in range(t2 + 1, i + 1):
                close = bars[j].close
                if close < bottom * (1 - DOUBLE_INVALIDATION):
                    invalid = True
                    break
                if breakout is None and close > peak:
                    breakout = j
            if invalid:
                continue
            status = _status(breakout, i)
            if status is None:
                continue

            return _pattern(
                "double_bottom",
                status,
                bars,
                i,
                breakout_level=peak,
                points=[(t1, l1, "left_low"), (peak_index, peak, "peak"), (t2, l2, "right_low")],
                metadata={
                    "rise_pct": round((peak - higher_low) / higher_low * 100, 4),
                    "gap_sessions": float(gap),
                },
                breakout=breakout,
            )
    return None


def cup_with_handle(bars: list[Bar], rvol: list[float | None], start: int) -> Pattern | None:
    """Rounded cup between two similar rims plus a shallow handle; breakout on volume."""

    i = len(bars) - 1
    highs = [j for j in swing_highs(bars) if j >= start]

    for c in range(len(highs) - 1, -1, -1):
        tc = highs[c]
        if i - tc < HANDLE_MIN_LENGTH:
            continue
        if i - tc > HANDLE_MAX_LENGTH + CONFIRM_SESSIONS:
            break
        rim_right = bars[tc].high
        for a in range(c - 1, -1, -1):
            ta = highs[a]
            span = tc - ta
            if span < CUP_MIN_LENGTH:
                continue
            if span > CUP_MAX_LENGTH:
                break
            rim_left = bars[ta].high
            if not (1 - CUP_RIM_TOLERANCE) * rim_left <= rim_right <= (1 + CUP_RIM_TOLERANCE) * rim_left:
                continue
            # The rims are the cup's highs: nothing inside rises above the
            # higher rim, and no intermediate hump (a swing high) above the
            # lower one.
            if bars[_argmax_high(bars, ta, tc)].high > max(rim_left, rim_right):
                continue
            if any(bars[j].high > min(rim_left, rim_right) for j in highs[a + 1 : c]):
                continue
            tb = _argmin_low(bars, ta, tc)
            cup_low = bars[tb].low
            depth = (rim_left - cup_low) / rim_left
            if not CUP_MIN_DEPTH <= depth <= CUP_MAX_DEPTH:
                continue
            position = (tb - ta) / span
            if not CUP_LOW_POSITION[0] <= position <= CUP_LOW_POSITION[1]:
                continue
            midpoint = cup_low + (rim_left - cup_low) / 2

            pivot = rim_right
            handle_low, handle_low_index = float("inf"), tc
            breakout, invalid = None, False
            for j in range(tc + 1, i + 1):
                bar = bars[j]
                volume_ratio = rvol[j]
                if (
                    j - tc > HANDLE_MIN_LENGTH
                    and bar.close > pivot
                    and volume_ratio is not None
                    and volume_ratio >= CUP_BREAKOUT_RVOL
                ):
                    breakout = j
                    break
                if bar.low < handle_low:
                    handle_low, handle_low_index = bar.low, j
                if handle_low < midpoint or (rim_right - handle_low) / rim_right > HANDLE_MAX_DEPTH:
                    invalid = True
                    break
                pivot = max(pivot, bar.high)
            handle_end = breakout if breakout is not None else i
            handle_length = handle_end - tc
            if invalid or handle_length > HANDLE_MAX_LENGTH or handle_length < HANDLE_MIN_LENGTH:
                continue
            status = _status(breakout, i)
            if status is None:
                continue

            return _pattern(
                "cup_with_handle",
                status,
                bars,
                i,
                breakout_level=pivot,
                points=[
                    (ta, rim_left, "rim_left"),
                    (tb, cup_low, "cup_low"),
                    (tc, rim_right, "rim_right"),
                    (handle_low_index, handle_low, "handle_low"),
                ],
                metadata={
                    "depth_pct": round(depth * 100, 4),
                    "cup_sessions": float(span),
                    "handle_sessions": float(handle_length),
                },
                breakout=breakout,
            )
    return None


def bull_flag(bars: list[Bar], rvol: list[float | None], start: int) -> Pattern | None:
    """A ≥15% pole in ≤15 sessions, then a shallow down-sloping flag on lower volume."""

    i = len(bars) - 1
    highs = [j for j in swing_highs(bars) if j >= start]
    lows = [j for j in swing_lows(bars) if j >= start]

    for t in range(len(highs) - 1, -1, -1):
        tt = highs[t]
        if i - tt < FLAG_MIN_LENGTH:
            continue
        if i - tt > FLAG_MAX_LENGTH + CONFIRM_SESSIONS:
            break
        top = bars[tt].high
        for ts in reversed([j for j in lows if j < tt]):
            if tt - ts > POLE_MAX_LENGTH:
                break
            base = bars[ts].low
            if (top - base) / base < POLE_MIN_RISE:
                continue
            floor = top - FLAG_MAX_RETRACE * (top - base)

            flag_high = float("-inf")
            flag_low, flag_low_index = float("inf"), tt
            breakout, invalid = None, False
            for j in range(tt + 1, i + 1):
                bar = bars[j]
                if j - tt - 1 >= FLAG_MIN_LENGTH and bar.close > flag_high:
                    breakout = j
                    break
                if bar.low < flag_low:
                    flag_low, flag_low_index = bar.low, j
                if flag_low < floor or bar.high > top:
                    invalid = True
                    break
                flag_high = max(flag_high, bar.high)
            flag_end = breakout - 1 if breakout is not None else i
            flag_length = flag_end - tt
            if invalid or not FLAG_MIN_LENGTH <= flag_length <= FLAG_MAX_LENGTH:
                continue
            flag_bars = bars[tt + 1 : flag_end + 1]
            if _slope([bar.close for bar in flag_bars]) > 0:
                continue
            pole_bars = bars[ts : tt + 1]
            if _mean([bar.volume for bar in flag_bars]) >= _mean([bar.volume for bar in pole_bars]):
                continue
            status = _status(breakout, i)
            if status is None:
                continue

            return _pattern(
                "bull_flag",
                status,
                bars,
                i,
                breakout_level=flag_high,
                points=[
                    (ts, base, "pole_start"),
                    (tt, top, "pole_top"),
                    (flag_low_index, flag_low, "flag_low"),
                ],
                metadata={
                    "pole_rise_pct": round((top - base) / base * 100, 4),
                    "flag_sessions": float(flag_length),
                },
                breakout=breakout,
            )
    return None


DETECTORS: tuple[tuple[str, Detector], ...] = (
    ("double_top", double_top),
    ("double_bottom", double_bottom),
    ("cup_with_handle", cup_with_handle),
    ("bull_flag", bull_flag),
)


def _status(breakout: int | None, i: int) -> str | None:
    if breakout is None:
        return "forming"
    if i - breakout <= CONFIRM_SESSIONS - 1:
        return "confirmed"
    return None


def _pattern(
    pattern_type: str,
    status: str,
    bars: list[Bar],
    i: int,
    *,
    breakout_level: float,
    points: list[tuple[int, float, str]],
    metadata: dict[str, float],
    breakout: int | None,
) -> Pattern:
    ordered = sorted(points, key=lambda point: point[0])
    if breakout is not None:
        metadata = {**metadata, "breakout_close": bars[breakout].close}
    return Pattern(
        type=pattern_type,
        status=status,
        as_of_date=bars[i].date,
        start_date=bars[ordered[0][0]].date,
        end_date=bars[ordered[-1][0]].date,
        breakout_level=breakout_level,
        points=[PatternPoint(date=bars[index].date, price=price, role=role) for index, price, role in ordered],
        metadata=metadata,
    )


def _argmin_low(bars: list[Bar], first: int, last: int) -> int:
    return min(range(first, last + 1), key=lambda j: bars[j].low)


def _argmax_high(bars: list[Bar], first: int, last: int) -> int:
    return max(range(first, last + 1), key=lambda j: bars[j].high)


def _mean(values: list[float]) -> float:
    return sum(values) / len(values) if values else 0.0


def _slope(values: list[float]) -> float:
    """Least-squares slope of ``values`` against their index."""

    n = len(values)
    if n < 2:
        return 0.0
    x_mean = (n - 1) / 2
    y_mean = sum(values) / n
    numerator = sum((x - x_mean) * (y - y_mean) for x, y in enumerate(values))
    denominator = sum((x - x_mean) ** 2 for x in range(n))
    return numerator / denominator
