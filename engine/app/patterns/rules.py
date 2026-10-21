"""One detector per chartist pattern type (fixed contract, documented parameters).

Each detector scans the evaluation window for the most recent qualifying
instance that is still active on the as-of bar ``i`` (the last bar) and returns
it, or ``None``. ``forming`` means the geometry is complete and not invalidated
with no breakout yet; ``confirmed`` means the breakout close happened within the
last ``CONFIRM_SESSIONS`` sessions and nothing invalidated it since. Older
breakouts and invalidated instances are not active. The detectors never read
bars after ``i`` and never compute a target: ``breakout_level`` is the only
price line a pattern exposes.

The parameters below are the values of docs/specs/chart-patterns-detect.md;
changing them needs a spec and tests (chart-patterns-calibration). They are
also gathered in ``PatternParams`` so the calibration near-miss scan
(``app.patterns.near_misses``) can relax one rule at a time without touching
the production values: production always runs with ``DEFAULT_PARAMS``.
"""

from collections.abc import Callable
from dataclasses import asdict, dataclass

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
DOUBLE_INVALIDATION = 0.0
# Calibration 2026-10-21 (docs/calibration/sample-2026-10-16): the first
# extreme ends a move (no bar of the previous ANCHOR_LOOKBACK sessions goes
# more than ANCHOR_TOLERANCE beyond it), the middle point is not glued to
# either extreme, and no key extreme is a lone wick far from the closes.
DOUBLE_ANCHOR_LOOKBACK = 40
DOUBLE_ANCHOR_TOLERANCE = 0.015
DOUBLE_MIDDLE_POSITION = (0.12, 0.85)
DOUBLE_MAX_WICK = 0.08
DOUBLE_WICK_BARS = 2

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
HANDLE_MAX_RISE = 0.03
CUP_BREAKOUT_RVOL = 1.5

# bull_flag
POLE_MAX_LENGTH = 20
POLE_MIN_RISE = 0.15
FLAG_MIN_LENGTH = 5
FLAG_MAX_LENGTH = 20
FLAG_MAX_RETRACE = 0.5


@dataclass(frozen=True)
class PatternParams:
    """Every rule parameter; the defaults are the production constants above.

    The boolean switches (``*_extremes``, ``flag_down_slope``,
    ``flag_lower_volume``) exist only so the calibration near-miss scan can
    relax one structural rule at a time.
    """

    confirm_sessions: int = CONFIRM_SESSIONS
    double_min_gap: int = DOUBLE_MIN_GAP
    double_max_gap: int = DOUBLE_MAX_GAP
    double_peak_tolerance: float = DOUBLE_PEAK_TOLERANCE
    double_min_depth: float = DOUBLE_MIN_DEPTH
    double_prior_lookback: int = DOUBLE_PRIOR_LOOKBACK
    double_prior_move: float = DOUBLE_PRIOR_MOVE
    double_max_age: int = DOUBLE_MAX_AGE
    double_invalidation: float = DOUBLE_INVALIDATION
    double_anchor_lookback: int = DOUBLE_ANCHOR_LOOKBACK
    double_anchor_tolerance: float = DOUBLE_ANCHOR_TOLERANCE
    double_middle_min: float = DOUBLE_MIDDLE_POSITION[0]
    double_middle_max: float = DOUBLE_MIDDLE_POSITION[1]
    double_max_wick: float = DOUBLE_MAX_WICK
    double_extremes: bool = True
    cup_min_length: int = CUP_MIN_LENGTH
    cup_max_length: int = CUP_MAX_LENGTH
    cup_min_depth: float = CUP_MIN_DEPTH
    cup_max_depth: float = CUP_MAX_DEPTH
    cup_rim_tolerance: float = CUP_RIM_TOLERANCE
    cup_low_position_min: float = CUP_LOW_POSITION[0]
    cup_low_position_max: float = CUP_LOW_POSITION[1]
    cup_extremes: bool = True
    handle_min_length: int = HANDLE_MIN_LENGTH
    handle_max_length: int = HANDLE_MAX_LENGTH
    handle_max_depth: float = HANDLE_MAX_DEPTH
    handle_max_rise: float = HANDLE_MAX_RISE
    cup_breakout_rvol: float = CUP_BREAKOUT_RVOL
    pole_max_length: int = POLE_MAX_LENGTH
    pole_min_rise: float = POLE_MIN_RISE
    flag_min_length: int = FLAG_MIN_LENGTH
    flag_max_length: int = FLAG_MAX_LENGTH
    flag_max_retrace: float = FLAG_MAX_RETRACE
    flag_down_slope: bool = True
    flag_lower_volume: bool = True
    flag_extremes: bool = True

    def as_dict(self) -> dict[str, float | int | bool]:
        return asdict(self)


DEFAULT_PARAMS = PatternParams()

Detector = Callable[[list[Bar], list[float | None], int, PatternParams], Pattern | None]


def double_top(
    bars: list[Bar], rvol: list[float | None], start: int, params: PatternParams = DEFAULT_PARAMS
) -> Pattern | None:
    """Two similar swing highs with a ≥10% trough between them after a rise."""

    i = len(bars) - 1
    highs = [j for j in swing_highs(bars) if j >= start]

    for b in range(len(highs) - 1, -1, -1):
        t2 = highs[b]
        if i - t2 > params.double_max_age:
            break
        for a in range(b - 1, -1, -1):
            t1 = highs[a]
            gap = t2 - t1
            if gap < params.double_min_gap:
                continue
            if gap > params.double_max_gap:
                break
            h1, h2 = bars[t1].high, bars[t2].high
            top = max(h1, h2)
            if abs(h2 - h1) / top > params.double_peak_tolerance:
                continue
            if params.double_extremes and bars[_argmax_high(bars, t1, t2)].high > top:
                continue
            trough_index = _argmin_low(bars, t1, t2)
            trough = bars[trough_index].low
            lower_peak = min(h1, h2)
            if (lower_peak - trough) / lower_peak < params.double_min_depth:
                continue
            if t1 == 0:
                continue
            # Prior rise: the lookback includes bar t1 (``low[t1-60 .. t1]``).
            prior_low = min(bar.low for bar in bars[max(0, t1 - params.double_prior_lookback) : t1 + 1])
            if h1 < (1 + params.double_prior_move) * prior_low:
                continue
            anchor = bars[max(0, t1 - params.double_anchor_lookback) : t1]
            if anchor and max(bar.high for bar in anchor) > h1 * (1 + params.double_anchor_tolerance):
                continue
            if not params.double_middle_min <= (trough_index - t1) / gap <= params.double_middle_max:
                continue
            if max(_wick(bars, t1, i, high=True), _wick(bars, t2, i, high=True)) > params.double_max_wick:
                continue

            breakout, invalid = None, False
            for j in range(t2 + 1, i + 1):
                close = bars[j].close
                if close > top * (1 + params.double_invalidation):
                    invalid = True
                    break
                if breakout is None and close < trough:
                    breakout = j
            if invalid:
                continue
            status = _status(breakout, i, params)
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


def double_bottom(
    bars: list[Bar], rvol: list[float | None], start: int, params: PatternParams = DEFAULT_PARAMS
) -> Pattern | None:
    """Mirror of ``double_top`` on swing lows after a decline."""

    i = len(bars) - 1
    lows = [j for j in swing_lows(bars) if j >= start]

    for b in range(len(lows) - 1, -1, -1):
        t2 = lows[b]
        if i - t2 > params.double_max_age:
            break
        for a in range(b - 1, -1, -1):
            t1 = lows[a]
            gap = t2 - t1
            if gap < params.double_min_gap:
                continue
            if gap > params.double_max_gap:
                break
            l1, l2 = bars[t1].low, bars[t2].low
            bottom = min(l1, l2)
            if abs(l2 - l1) / max(l1, l2) > params.double_peak_tolerance:
                continue
            if params.double_extremes and bars[_argmin_low(bars, t1, t2)].low < bottom:
                continue
            peak_index = _argmax_high(bars, t1, t2)
            peak = bars[peak_index].high
            higher_low = max(l1, l2)
            if (peak - higher_low) / higher_low < params.double_min_depth:
                continue
            if t1 == 0:
                continue
            # Prior decline: the lookback includes bar t1 (``high[t1-60 .. t1]``).
            prior_high = max(bar.high for bar in bars[max(0, t1 - params.double_prior_lookback) : t1 + 1])
            if l1 > (1 - params.double_prior_move) * prior_high:
                continue
            anchor = bars[max(0, t1 - params.double_anchor_lookback) : t1]
            if anchor and min(bar.low for bar in anchor) < l1 * (1 - params.double_anchor_tolerance):
                continue
            if not params.double_middle_min <= (peak_index - t1) / gap <= params.double_middle_max:
                continue
            if max(_wick(bars, t1, i, high=False), _wick(bars, t2, i, high=False)) > params.double_max_wick:
                continue

            breakout, invalid = None, False
            for j in range(t2 + 1, i + 1):
                close = bars[j].close
                if close < bottom * (1 - params.double_invalidation):
                    invalid = True
                    break
                if breakout is None and close > peak:
                    breakout = j
            if invalid:
                continue
            status = _status(breakout, i, params)
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


def cup_with_handle(
    bars: list[Bar], rvol: list[float | None], start: int, params: PatternParams = DEFAULT_PARAMS
) -> Pattern | None:
    """Rounded cup between two similar rims plus a shallow handle; breakout on volume.

    Handle sessions are the bars after the right rim and before the breakout
    bar (or up to the as-of bar while forming). Before the breakout, a handle
    high more than ``HANDLE_MAX_RISE`` above the right rim, or any close above
    the right rim that is not a volume-confirmed breakout, invalidates the
    instance. After the breakout, a close below the handle low invalidates it.
    """

    i = len(bars) - 1
    highs = [j for j in swing_highs(bars) if j >= start]

    for c in range(len(highs) - 1, -1, -1):
        tc = highs[c]
        if i - tc < params.handle_min_length:
            continue
        if i - tc > params.handle_max_length + params.confirm_sessions:
            break
        rim_right = bars[tc].high
        for a in range(c - 1, -1, -1):
            ta = highs[a]
            span = tc - ta
            if span < params.cup_min_length:
                continue
            if span > params.cup_max_length:
                break
            rim_left = bars[ta].high
            tolerance = params.cup_rim_tolerance
            if not (1 - tolerance) * rim_left <= rim_right <= (1 + tolerance) * rim_left:
                continue
            # The rims are the cup's highs: nothing inside rises above the
            # higher rim, and no intermediate hump (a swing high) above the
            # lower one.
            if params.cup_extremes:
                if bars[_argmax_high(bars, ta, tc)].high > max(rim_left, rim_right):
                    continue
                if any(bars[j].high > min(rim_left, rim_right) for j in highs[a + 1 : c]):
                    continue
            tb = _argmin_low(bars, ta, tc)
            cup_low = bars[tb].low
            depth = (rim_left - cup_low) / rim_left
            if not params.cup_min_depth <= depth <= params.cup_max_depth:
                continue
            position = (tb - ta) / span
            if not params.cup_low_position_min <= position <= params.cup_low_position_max:
                continue
            midpoint = cup_low + (rim_left - cup_low) / 2

            pivot = rim_right
            handle_low, handle_low_index = float("inf"), tc
            breakout, invalid = None, False
            for j in range(tc + 1, i + 1):
                bar = bars[j]
                if breakout is not None:
                    # Post-breakout invalidation: a close back below the handle low.
                    if bar.close < handle_low:
                        invalid = True
                        break
                    continue
                volume_ratio = rvol[j]
                if (
                    j - tc - 1 >= params.handle_min_length
                    and bar.close > pivot
                    and volume_ratio is not None
                    and volume_ratio >= params.cup_breakout_rvol
                ):
                    breakout = j
                    continue
                if bar.close > rim_right:
                    # A close above the rim without the volume-confirmed
                    # breakout is not a handle.
                    invalid = True
                    break
                if bar.high > rim_right * (1 + params.handle_max_rise):
                    invalid = True
                    break
                if bar.low < handle_low:
                    handle_low, handle_low_index = bar.low, j
                if handle_low < midpoint or (rim_right - handle_low) / rim_right > params.handle_max_depth:
                    invalid = True
                    break
                pivot = max(pivot, bar.high)
            handle_end = breakout - 1 if breakout is not None else i
            handle_length = handle_end - tc
            if invalid or not params.handle_min_length <= handle_length <= params.handle_max_length:
                continue
            status = _status(breakout, i, params)
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


def bull_flag(
    bars: list[Bar], rvol: list[float | None], start: int, params: PatternParams = DEFAULT_PARAMS
) -> Pattern | None:
    """A ≥15% pole in ≤20 sessions, then a shallow down-sloping flag on lower volume.

    Flag sessions are the bars after the pole top and before the breakout bar
    (or up to the as-of bar while forming). After the breakout, a close below
    the flag low invalidates the instance.
    """

    i = len(bars) - 1
    highs = [j for j in swing_highs(bars) if j >= start]
    lows = [j for j in swing_lows(bars) if j >= start]

    for t in range(len(highs) - 1, -1, -1):
        tt = highs[t]
        if i - tt < params.flag_min_length:
            continue
        if i - tt > params.flag_max_length + params.confirm_sessions:
            break
        top = bars[tt].high
        for ts in reversed([j for j in lows if j < tt]):
            if tt - ts > params.pole_max_length:
                break
            base = bars[ts].low
            if (top - base) / base < params.pole_min_rise:
                continue
            floor = top - params.flag_max_retrace * (top - base)

            flag_high = float("-inf")
            flag_low, flag_low_index = float("inf"), tt
            breakout, invalid = None, False
            for j in range(tt + 1, i + 1):
                bar = bars[j]
                if breakout is not None:
                    # Post-breakout invalidation: a close back below the flag low.
                    if bar.close < flag_low:
                        invalid = True
                        break
                    continue
                if j - tt - 1 >= params.flag_min_length and bar.close > flag_high:
                    breakout = j
                    continue
                if bar.low < flag_low:
                    flag_low, flag_low_index = bar.low, j
                if flag_low < floor or (params.flag_extremes and bar.high > top):
                    invalid = True
                    break
                flag_high = max(flag_high, bar.high)
            flag_end = breakout - 1 if breakout is not None else i
            flag_length = flag_end - tt
            if invalid or not params.flag_min_length <= flag_length <= params.flag_max_length:
                continue
            flag_bars = bars[tt + 1 : flag_end + 1]
            if params.flag_down_slope and _slope([bar.close for bar in flag_bars]) > 0:
                continue
            pole_bars = bars[ts : tt + 1]
            if params.flag_lower_volume and _mean([bar.volume for bar in flag_bars]) >= _mean(
                [bar.volume for bar in pole_bars]
            ):
                continue
            status = _status(breakout, i, params)
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


def _status(breakout: int | None, i: int, params: PatternParams) -> str | None:
    if breakout is None:
        return "forming"
    if i - breakout <= params.confirm_sessions - 1:
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


def _wick(bars: list[Bar], t: int, i: int, *, high: bool) -> float:
    """How far the extreme of bar ``t`` lies from the nearest closes (fraction).

    Compares the high (or low) with the highest (lowest) close within
    ``DOUBLE_WICK_BARS`` sessions on each side, never reading past ``i``.
    """

    closes = [bar.close for bar in bars[max(0, t - DOUBLE_WICK_BARS) : min(i, t + DOUBLE_WICK_BARS) + 1]]
    if high:
        return (bars[t].high - max(closes)) / bars[t].high
    return (min(closes) - bars[t].low) / bars[t].low


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
