"""Near misses for the calibration review sample (chart-patterns-calibration).

A near miss of a type is an instance the production rules reject because of
exactly one rule: with that single rule relaxed (and every other rule at its
production value) the detector finds an instance, while the production rules
find none of that type on the same series. Relaxations are tried in the fixed
order below and the first hit is reported, so the result is deterministic.

This is a review aid only: it never changes what ``detect_patterns`` returns
and nothing here is persisted as a pattern. The engine stays stateless and
database-free; Laravel decides what to export.
"""

from dataclasses import replace

from app.indicators.core import rvol
from app.models import Bar, PatternNearMiss
from app.patterns.detect import MIN_BARS, RVOL_PERIOD, WINDOW
from app.patterns.rules import DEFAULT_PARAMS, DETECTORS, PatternParams

# (rule name, PatternParams field, relaxed value) per type. Numeric
# relaxations stay close to the production value, so a hit is a borderline
# case worth a human look; boolean ones switch one structural rule off.
RELAXATIONS: dict[str, tuple[tuple[str, str, float | int | bool], ...]] = {
    "double_top": (
        ("peak_tolerance", "double_peak_tolerance", 0.05),
        ("min_depth", "double_min_depth", 0.07),
        ("prior_move", "double_prior_move", 0.10),
        ("min_gap", "double_min_gap", 10),
        ("extremes", "double_extremes", False),
    ),
    "double_bottom": (
        ("peak_tolerance", "double_peak_tolerance", 0.05),
        ("min_depth", "double_min_depth", 0.07),
        ("prior_move", "double_prior_move", 0.10),
        ("min_gap", "double_min_gap", 10),
        ("extremes", "double_extremes", False),
    ),
    "cup_with_handle": (
        ("rim_tolerance", "cup_rim_tolerance", 0.08),
        ("min_depth", "cup_min_depth", 0.08),
        ("max_depth", "cup_max_depth", 0.45),
        ("low_position_min", "cup_low_position_min", 0.10),
        ("low_position_max", "cup_low_position_max", 0.90),
        ("handle_max_depth", "handle_max_depth", 0.18),
        ("handle_max_rise", "handle_max_rise", 0.06),
        ("handle_max_length", "handle_max_length", 35),
        ("breakout_rvol", "cup_breakout_rvol", 1.0),
        ("extremes", "cup_extremes", False),
    ),
    "bull_flag": (
        ("pole_min_rise", "pole_min_rise", 0.10),
        ("pole_max_length", "pole_max_length", 20),
        ("flag_max_retrace", "flag_max_retrace", 0.618),
        ("flag_max_length", "flag_max_length", 25),
        ("down_slope", "flag_down_slope", False),
        ("lower_volume", "flag_lower_volume", False),
        ("extremes", "flag_extremes", False),
    ),
}


def detect_near_misses(bars: list[Bar], params: PatternParams = DEFAULT_PARAMS) -> list[PatternNearMiss]:
    """At most one near miss per type that the production rules do not detect."""

    if len(bars) < MIN_BARS:
        return []

    volume_ratios = rvol([float(bar.volume) for bar in bars], RVOL_PERIOD)
    start = max(0, len(bars) - WINDOW)

    near_misses: list[PatternNearMiss] = []
    for pattern_type, detector in DETECTORS:
        if detector(bars, volume_ratios, start, params) is not None:
            continue
        for rule, field, relaxed in RELAXATIONS[pattern_type]:
            pattern = detector(bars, volume_ratios, start, replace(params, **{field: relaxed}))
            if pattern is not None:
                near_misses.append(
                    PatternNearMiss(
                        rule=rule,
                        parameter=field,
                        production_value=float(getattr(params, field)),
                        relaxed_value=float(relaxed),
                        pattern=pattern,
                    )
                )
                break
    return near_misses
