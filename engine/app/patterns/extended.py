"""The second batch of chartist patterns (docs/specs/chart-patterns-extended.md).

Six detectors that reuse the geometry and the parameters of the first four
(``rules.py``): triple top/bottom extend the doubles to a third extreme, the
inverse cup with handle and the bear flag mirror their bullish counterparts,
and the pennants put a converging consolidation on the flag pole. Mirrored
types read the *same* ``PatternParams`` fields as their counterparts, so a
calibrated value applies to both directions; only ``pennant_max_width_ratio`` is
new. As in ``rules.py``, each detector returns the most recent instance still
active on the as-of bar ``i`` or ``None``, never reads bars after ``i`` and
never computes a target.
"""

from app.models import Bar, Pattern
from app.patterns.rules import (
    DEFAULT_PARAMS,
    DETECTORS,
    Detector,
    PatternParams,
    _argmax_high,
    _argmin_low,
    _mean,
    _pattern,
    _slope,
    _status,
    _wick,
)
from app.patterns.swings import swing_highs, swing_lows

BULLISH = ("double_bottom", "triple_bottom", "cup_with_handle", "bull_flag", "bull_pennant")
BEARISH = ("double_top", "triple_top", "inverse_cup_with_handle", "bear_flag", "bear_pennant")


def triple_top(
    bars: list[Bar], rvol: list[float | None], start: int, params: PatternParams = DEFAULT_PARAMS
) -> Pattern | None:
    """Three similar swing highs with two ≥10% troughs between them after a rise."""

    i = len(bars) - 1
    highs = [j for j in swing_highs(bars) if j >= start]

    for c in range(len(highs) - 1, -1, -1):
        t3 = highs[c]
        if i - t3 > params.double_max_age:
            break
        for b in range(c - 1, -1, -1):
            t2 = highs[b]
            if t3 - t2 < params.double_min_gap:
                continue
            if t3 - t2 > params.double_max_gap:
                break
            for a in range(b - 1, -1, -1):
                t1 = highs[a]
                if t2 - t1 < params.double_min_gap:
                    continue
                if t2 - t1 > params.double_max_gap:
                    break
                found = _triple(bars, i, (t1, t2, t3), params, top=True)
                if found is not None:
                    return found
    return None


def triple_bottom(
    bars: list[Bar], rvol: list[float | None], start: int, params: PatternParams = DEFAULT_PARAMS
) -> Pattern | None:
    """Mirror of ``triple_top`` on swing lows after a decline."""

    i = len(bars) - 1
    lows = [j for j in swing_lows(bars) if j >= start]

    for c in range(len(lows) - 1, -1, -1):
        t3 = lows[c]
        if i - t3 > params.double_max_age:
            break
        for b in range(c - 1, -1, -1):
            t2 = lows[b]
            if t3 - t2 < params.double_min_gap:
                continue
            if t3 - t2 > params.double_max_gap:
                break
            for a in range(b - 1, -1, -1):
                t1 = lows[a]
                if t2 - t1 < params.double_min_gap:
                    continue
                if t2 - t1 > params.double_max_gap:
                    break
                found = _triple(bars, i, (t1, t2, t3), params, top=False)
                if found is not None:
                    return found
    return None


def _triple(
    bars: list[Bar], i: int, points: tuple[int, int, int], params: PatternParams, *, top: bool
) -> Pattern | None:
    """Check one candidate triple; ``top`` picks highs (else lows).

    The rules are the double top/bottom rules applied to three extremes: the
    same tolerance, depth, prior move, anchor, middle-point position, wick,
    extreme and invalidation parameters.
    """

    t1, t2, t3 = points
    extreme = [bars[t].high if top else bars[t].low for t in points]
    outer = max(extreme) if top else min(extreme)
    if (max(extreme) - min(extreme)) / max(extreme) > params.double_peak_tolerance:
        return None
    if params.double_extremes:
        beyond = bars[_argmax_high(bars, t1, t3)].high > outer if top else bars[_argmin_low(bars, t1, t3)].low < outer
        if beyond:
            return None

    # The two middle points (troughs between tops, crests between bottoms).
    middles: list[int] = []
    for left, right, first, second in ((t1, t2, extreme[0], extreme[1]), (t2, t3, extreme[1], extreme[2])):
        index = _argmin_low(bars, left, right) if top else _argmax_high(bars, left, right)
        if top:
            near = min(first, second)
            depth = (near - bars[index].low) / near
        else:
            near = max(first, second)
            depth = (bars[index].high - near) / near
        if depth < params.double_min_depth:
            return None
        if not params.double_middle_min <= (index - left) / (right - left) <= params.double_middle_max:
            return None
        middles.append(index)

    if t1 == 0:
        return None
    lookback = bars[max(0, t1 - params.double_prior_lookback) : t1 + 1]
    anchor = bars[max(0, t1 - params.double_anchor_lookback) : t1]
    if top:
        if extreme[0] < (1 + params.double_prior_move) * min(bar.low for bar in lookback):
            return None
        if anchor and max(bar.high for bar in anchor) > extreme[0] * (1 + params.double_anchor_tolerance):
            return None
    else:
        if extreme[0] > (1 - params.double_prior_move) * max(bar.high for bar in lookback):
            return None
        if anchor and min(bar.low for bar in anchor) < extreme[0] * (1 - params.double_anchor_tolerance):
            return None
    if max(_wick(bars, t, i, high=top) for t in points) > params.double_max_wick:
        return None

    if top:
        neck_index = min(middles, key=lambda j: bars[j].low)
        level = bars[neck_index].low
    else:
        neck_index = max(middles, key=lambda j: bars[j].high)
        level = bars[neck_index].high

    breakout = None
    for j in range(t3 + 1, i + 1):
        close = bars[j].close
        if (top and close > outer * (1 + params.double_invalidation)) or (
            not top and close < outer * (1 - params.double_invalidation)
        ):
            return None
        if breakout is None and ((top and close < level) or (not top and close > level)):
            breakout = j
    status = _status(breakout, i, params)
    if status is None:
        return None

    m1, m2 = middles
    if top:
        roles = ("left_peak", "left_trough", "middle_peak", "right_trough", "third_peak")
        depths = [(min(extreme[k], extreme[k + 1]) - bars[m].low) / min(extreme[k], extreme[k + 1]) for k, m in enumerate(middles)]
        middle_prices = (bars[m1].low, bars[m2].low)
    else:
        roles = ("left_low", "left_crest", "middle_low", "right_crest", "third_low")
        depths = [(bars[m].high - max(extreme[k], extreme[k + 1])) / max(extreme[k], extreme[k + 1]) for k, m in enumerate(middles)]
        middle_prices = (bars[m1].high, bars[m2].high)

    return _pattern(
        "triple_top" if top else "triple_bottom",
        status,
        bars,
        i,
        breakout_level=level,
        points=[
            (t1, extreme[0], roles[0]),
            (m1, middle_prices[0], roles[1]),
            (t2, extreme[1], roles[2]),
            (m2, middle_prices[1], roles[3]),
            (t3, extreme[2], roles[4]),
        ],
        metadata={
            "depth_pct": round(min(depths) * 100, 4),
            "span_sessions": float(t3 - t1),
        },
        breakout=breakout,
    )


def inverse_cup_with_handle(
    bars: list[Bar], rvol: list[float | None], start: int, params: PatternParams = DEFAULT_PARAMS
) -> Pattern | None:
    """Mirror of ``cup_with_handle``: a rounded top between two similar rim lows
    plus a shallow upward handle; breakout below the handle on volume.

    Before the breakout, a handle low more than ``handle_max_rise`` below the
    right rim, or any close below the right rim that is not a volume-confirmed
    breakout, invalidates the instance. After the breakout, a close above the
    handle high invalidates it.
    """

    i = len(bars) - 1
    lows = [j for j in swing_lows(bars) if j >= start]

    for c in range(len(lows) - 1, -1, -1):
        tc = lows[c]
        if i - tc < params.handle_min_length:
            continue
        if i - tc > params.handle_max_length + params.confirm_sessions:
            break
        rim_right = bars[tc].low
        for a in range(c - 1, -1, -1):
            ta = lows[a]
            span = tc - ta
            if span < params.cup_min_length:
                continue
            if span > params.cup_max_length:
                break
            rim_left = bars[ta].low
            tolerance = params.cup_rim_tolerance
            if not (1 - tolerance) * rim_left <= rim_right <= (1 + tolerance) * rim_left:
                continue
            # The rims are the lows of the inverse cup: nothing inside falls
            # below the lower rim, and no intermediate dip (a swing low)
            # below the higher one.
            if params.cup_extremes:
                if bars[_argmin_low(bars, ta, tc)].low < min(rim_left, rim_right):
                    continue
                if any(bars[j].low < max(rim_left, rim_right) for j in lows[a + 1 : c]):
                    continue
            tb = _argmax_high(bars, ta, tc)
            cup_top = bars[tb].high
            height = (cup_top - rim_left) / rim_left
            if not params.cup_min_depth <= height <= params.cup_max_depth:
                continue
            position = (tb - ta) / span
            if not params.cup_low_position_min <= position <= params.cup_low_position_max:
                continue
            midpoint = cup_top - (cup_top - rim_left) / 2

            pivot = rim_right
            handle_high, handle_high_index = float("-inf"), tc
            breakout, invalid = None, False
            for j in range(tc + 1, i + 1):
                bar = bars[j]
                if breakout is not None:
                    # Post-breakout invalidation: a close back above the handle high.
                    if bar.close > handle_high:
                        invalid = True
                        break
                    continue
                volume_ratio = rvol[j]
                if (
                    j - tc - 1 >= params.handle_min_length
                    and bar.close < pivot
                    and volume_ratio is not None
                    and volume_ratio >= params.cup_breakout_rvol
                ):
                    breakout = j
                    continue
                if bar.close < rim_right:
                    invalid = True
                    break
                if bar.low < rim_right * (1 - params.handle_max_rise):
                    invalid = True
                    break
                if bar.high > handle_high:
                    handle_high, handle_high_index = bar.high, j
                if handle_high > midpoint or (handle_high - rim_right) / rim_right > params.handle_max_depth:
                    invalid = True
                    break
                pivot = min(pivot, bar.low)
            handle_end = breakout - 1 if breakout is not None else i
            handle_length = handle_end - tc
            if invalid or not params.handle_min_length <= handle_length <= params.handle_max_length:
                continue
            status = _status(breakout, i, params)
            if status is None:
                continue

            return _pattern(
                "inverse_cup_with_handle",
                status,
                bars,
                i,
                breakout_level=pivot,
                points=[
                    (ta, rim_left, "rim_left_low"),
                    (tb, cup_top, "cup_top"),
                    (tc, rim_right, "rim_right_low"),
                    (handle_high_index, handle_high, "handle_high"),
                ],
                metadata={
                    "height_pct": round(height * 100, 4),
                    "cup_sessions": float(span),
                    "handle_sessions": float(handle_length),
                },
                breakout=breakout,
            )
    return None


def bear_flag(
    bars: list[Bar], rvol: list[float | None], start: int, params: PatternParams = DEFAULT_PARAMS
) -> Pattern | None:
    """Mirror of ``bull_flag``: a ≥15% drop, then a shallow rising flag on lower volume."""

    return _pole_pattern(bars, start, params, bullish=False, pennant=False)


def bull_pennant(
    bars: list[Bar], rvol: list[float | None], start: int, params: PatternParams = DEFAULT_PARAMS
) -> Pattern | None:
    """The ``bull_flag`` pole followed by a converging consolidation on lower volume."""

    return _pole_pattern(bars, start, params, bullish=True, pennant=True)


def bear_pennant(
    bars: list[Bar], rvol: list[float | None], start: int, params: PatternParams = DEFAULT_PARAMS
) -> Pattern | None:
    """The ``bear_flag`` pole followed by a converging consolidation on lower volume."""

    return _pole_pattern(bars, start, params, bullish=False, pennant=True)


def _pole_pattern(
    bars: list[Bar], start: int, params: PatternParams, *, bullish: bool, pennant: bool
) -> Pattern | None:
    """Pole + consolidation for the bear flag and both pennants.

    The pole and consolidation rules are those of ``bull_flag`` (mirrored for
    the bearish side). A flag needs closes drifting against the pole; a
    pennant needs a second half with a lower high, a higher low and a range of
    at most ``pennant_max_width_ratio`` of the first half's (``_width_ratio``). The consolidation sessions
    exclude the breakout bar; after the breakout, a close back beyond the
    opposite side of the consolidation invalidates the instance.
    """

    i = len(bars) - 1
    highs = [j for j in swing_highs(bars) if j >= start]
    lows = [j for j in swing_lows(bars) if j >= start]
    ends, starts = (highs, lows) if bullish else (lows, highs)

    for t in range(len(ends) - 1, -1, -1):
        tt = ends[t]
        if i - tt < params.flag_min_length:
            continue
        if i - tt > params.flag_max_length + params.confirm_sessions:
            break
        end = bars[tt].high if bullish else bars[tt].low
        for ts in reversed([j for j in starts if j < tt]):
            if tt - ts > params.pole_max_length:
                break
            origin = bars[ts].low if bullish else bars[ts].high
            move = (end - origin) / origin if bullish else (origin - end) / origin
            if move < params.pole_min_rise:
                continue
            # The pole ends at its own extreme: nothing in it goes beyond the end.
            if params.flag_extremes and (
                (bullish and bars[_argmax_high(bars, ts, tt)].high > end)
                or (not bullish and bars[_argmin_low(bars, ts, tt)].low < end)
            ):
                continue
            # The consolidation may retrace at most this far into the pole.
            limit = end - params.flag_max_retrace * (end - origin)

            upper, lower = float("-inf"), float("inf")
            upper_index, lower_index = tt, tt
            breakout, invalid = None, False
            for j in range(tt + 1, i + 1):
                bar = bars[j]
                if breakout is not None:
                    # Post-breakout invalidation: a close back beyond the
                    # consolidation's opposite side.
                    if (bullish and bar.close < lower) or (not bullish and bar.close > upper):
                        invalid = True
                        break
                    continue
                if j - tt - 1 >= params.flag_min_length and (
                    (bullish and bar.close > upper) or (not bullish and bar.close < lower)
                ):
                    breakout = j
                    continue
                if bar.high > upper:
                    upper, upper_index = bar.high, j
                if bar.low < lower:
                    lower, lower_index = bar.low, j
                if bullish:
                    broken = lower < limit or (params.flag_extremes and bar.high > end)
                else:
                    broken = upper > limit or (params.flag_extremes and bar.low < end)
                if broken:
                    invalid = True
                    break
            last = breakout - 1 if breakout is not None else i
            length = last - tt
            if invalid or not params.flag_min_length <= length <= params.flag_max_length:
                continue
            body = bars[tt + 1 : last + 1]
            if pennant:
                ratio = _width_ratio(body)
                if ratio is None or ratio > params.pennant_max_width_ratio:
                    continue
            elif params.flag_down_slope and _slope([bar.close for bar in body]) < 0:
                # A bear flag drifts up (or sideways) against the drop.
                continue
            pole = bars[ts : tt + 1]
            if params.flag_lower_volume and _mean([bar.volume for bar in body]) >= _mean([bar.volume for bar in pole]):
                continue
            status = _status(breakout, i, params)
            if status is None:
                continue

            if bullish:
                pattern_type = "bull_pennant"
                points = [(ts, origin, "pole_start"), (tt, end, "pole_top"), (lower_index, lower, "pennant_low")]
                level = upper
                move_key = "pole_rise_pct"
            else:
                pattern_type = "bear_pennant" if pennant else "bear_flag"
                key_point = (upper_index, upper, "pennant_high" if pennant else "flag_high")
                points = [(ts, origin, "pole_peak"), (tt, end, "pole_bottom"), key_point]
                level = lower
                move_key = "pole_drop_pct"
            metadata = {move_key: round(move * 100, 4)}
            if pennant:
                metadata["pennant_sessions"] = float(length)
                metadata["width_ratio"] = round(ratio, 4)
            else:
                metadata["flag_sessions"] = float(length)

            return _pattern(
                pattern_type,
                status,
                bars,
                i,
                breakout_level=level,
                points=points,
                metadata=metadata,
                breakout=breakout,
            )
    return None


def _width_ratio(body: list[Bar]) -> float | None:
    """Range of the second half of the consolidation over the range of the first.

    The consolidation is split in two halves (the first one gets the middle
    bar when the count is odd). It converges when the second half's highest
    high is lower and its lowest low is higher than the first half's; then the
    ratio of the two high-low ranges is returned. ``None`` means no pennant.
    """

    middle = (len(body) + 1) // 2
    first, second = body[:middle], body[middle:]
    upper_1, lower_1 = max(bar.high for bar in first), min(bar.low for bar in first)
    upper_2, lower_2 = max(bar.high for bar in second), min(bar.low for bar in second)
    if not (upper_2 < upper_1 and lower_2 > lower_1):
        return None
    return (upper_2 - lower_2) / (upper_1 - lower_1)


EXTENDED_DETECTORS: tuple[tuple[str, Detector], ...] = (
    ("triple_top", triple_top),
    ("triple_bottom", triple_bottom),
    ("inverse_cup_with_handle", inverse_cup_with_handle),
    ("bear_flag", bear_flag),
    ("bull_pennant", bull_pennant),
    ("bear_pennant", bear_pennant),
)

# Every detector in vocabulary order: the first four, then this batch.
ALL_DETECTORS: tuple[tuple[str, Detector], ...] = DETECTORS + EXTENDED_DETECTORS
