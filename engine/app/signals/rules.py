"""Deterministic signal rules (pure, standard library only).

Every rule is evaluated on the **as-of bar** (the last stored bar). A rule
returns ``(fired, metadata)`` when it could be evaluated, or ``None`` when a
required indicator is not available (insufficient history) — a missing value
never fires a signal.

Boundary conventions (fixed contract, see ``docs/specs/signals-detect.md``):

- a cross/alignment needs a **strict** inequality at the as-of bar (equality is
  "no signal");
- threshold comparisons are inclusive where stated: ``rvol >= 2.0``,
  ``rsi14 >= 70`` (overbought), ``rsi14 <= 30`` (oversold);
- the pivot for ``pivot_breakout_rvol`` is the highest high of the prior 20
  sessions, excluding the as-of bar itself.

Each rule shares the same signature so the ``RULES`` registry below is a single
auditable list of the whole vocabulary:

    (previous: Snapshot | None, current: Snapshot, bars: list[Bar], index: int)
        -> tuple[bool, dict[str, float]] | None

``index`` is the as-of index in ``bars``; ``previous`` is the snapshot one bar
earlier (``None`` for the first bar).
"""

from collections.abc import Callable

from app.indicators.core import bollinger
from app.models import Bar, Snapshot

RuleResult = tuple[bool, dict[str, float]] | None
Rule = Callable[[Snapshot | None, Snapshot, list[Bar], int], RuleResult]

PIVOT_LOOKBACK = 20
RVOL_MINIMUM = 2.0
RSI_OVERBOUGHT = 70.0
RSI_OVERSOLD = 30.0
ADX_TREND_THRESHOLD = 25.0
STOCH_OVERSOLD = 20.0
STOCH_OVERBOUGHT = 80.0
BOLLINGER_PERIOD = 20
BOLLINGER_DEVIATIONS = 2.0
SQUEEZE_LOOKBACK = 120
# Bandwidth first exists at bar index 19; a 120-session window of it (the as-of
# bar plus the previous 119) therefore needs 19 + 120 = 139 bars.
SQUEEZE_MIN_BARS = BOLLINGER_PERIOD - 1 + SQUEEZE_LOOKBACK


def golden_cross(
    previous: Snapshot | None, current: Snapshot, bars: list[Bar], index: int
) -> RuleResult:
    """SMA50 crosses above SMA200 on the as-of bar."""

    if previous is None:
        return None

    if (
        previous.sma50 is None
        or previous.sma200 is None
        or current.sma50 is None
        or current.sma200 is None
    ):
        return None

    if previous.sma50 <= previous.sma200 and current.sma50 > current.sma200:
        return True, {"sma50": current.sma50, "sma200": current.sma200}

    return False, {}


def death_cross(
    previous: Snapshot | None, current: Snapshot, bars: list[Bar], index: int
) -> RuleResult:
    """SMA50 crosses below SMA200 on the as-of bar."""

    if previous is None:
        return None

    if (
        previous.sma50 is None
        or previous.sma200 is None
        or current.sma50 is None
        or current.sma200 is None
    ):
        return None

    if previous.sma50 >= previous.sma200 and current.sma50 < current.sma200:
        return True, {"sma50": current.sma50, "sma200": current.sma200}

    return False, {}


def ma_alignment_bullish(
    previous: Snapshot | None, current: Snapshot, bars: list[Bar], index: int
) -> RuleResult:
    """SMA20 > SMA50 > SMA200 on the as-of bar."""

    if current.sma20 is None or current.sma50 is None or current.sma200 is None:
        return None

    if current.sma20 > current.sma50 > current.sma200:
        return True, {
            "sma20": current.sma20,
            "sma50": current.sma50,
            "sma200": current.sma200,
        }

    return False, {}


def ma_alignment_bearish(
    previous: Snapshot | None, current: Snapshot, bars: list[Bar], index: int
) -> RuleResult:
    """SMA20 < SMA50 < SMA200 on the as-of bar."""

    if current.sma20 is None or current.sma50 is None or current.sma200 is None:
        return None

    if current.sma20 < current.sma50 < current.sma200:
        return True, {
            "sma20": current.sma20,
            "sma50": current.sma50,
            "sma200": current.sma200,
        }

    return False, {}


def pivot_breakout_rvol(
    previous: Snapshot | None, current: Snapshot, bars: list[Bar], index: int
) -> RuleResult:
    """Close breaks the prior 20-session high with at least 2x relative volume."""

    if index < PIVOT_LOOKBACK or current.rvol is None:
        return None

    pivot = max(bar.high for bar in bars[index - PIVOT_LOOKBACK : index])

    if bars[index].close > pivot and current.rvol >= RVOL_MINIMUM:
        return True, {
            "pivot": pivot,
            "close": bars[index].close,
            "rvol": current.rvol,
        }

    return False, {}


def rsi_overbought(
    previous: Snapshot | None, current: Snapshot, bars: list[Bar], index: int
) -> RuleResult:
    """RSI14 at or above 70 on the as-of bar."""

    if current.rsi14 is None:
        return None

    if current.rsi14 >= RSI_OVERBOUGHT:
        return True, {"rsi14": current.rsi14}

    return False, {}


def rsi_oversold(
    previous: Snapshot | None, current: Snapshot, bars: list[Bar], index: int
) -> RuleResult:
    """RSI14 at or below 30 on the as-of bar."""

    if current.rsi14 is None:
        return None

    if current.rsi14 <= RSI_OVERSOLD:
        return True, {"rsi14": current.rsi14}

    return False, {}


def macd_bullish_cross(
    previous: Snapshot | None, current: Snapshot, bars: list[Bar], index: int
) -> RuleResult:
    """MACD line crosses above its signal line on the as-of bar."""

    if previous is None:
        return None

    if (
        previous.macd is None
        or previous.macd_signal is None
        or current.macd is None
        or current.macd_signal is None
    ):
        return None

    if previous.macd <= previous.macd_signal and current.macd > current.macd_signal:
        return True, {"macd": current.macd, "macd_signal": current.macd_signal}

    return False, {}


def macd_bearish_cross(
    previous: Snapshot | None, current: Snapshot, bars: list[Bar], index: int
) -> RuleResult:
    """MACD line crosses below its signal line on the as-of bar."""

    if previous is None:
        return None

    if (
        previous.macd is None
        or previous.macd_signal is None
        or current.macd is None
        or current.macd_signal is None
    ):
        return None

    if previous.macd >= previous.macd_signal and current.macd < current.macd_signal:
        return True, {"macd": current.macd, "macd_signal": current.macd_signal}

    return False, {}


def adx_trend_strength(
    previous: Snapshot | None, current: Snapshot, bars: list[Bar], index: int
) -> RuleResult:
    """ADX crosses above 25 on the as-of bar (direction is read from +DI/-DI)."""

    if previous is None or previous.adx is None or current.adx is None:
        return None

    if previous.adx <= ADX_TREND_THRESHOLD and current.adx > ADX_TREND_THRESHOLD:
        metadata = {"adx": current.adx}
        if current.plus_di is not None:
            metadata["plus_di"] = current.plus_di
        if current.minus_di is not None:
            metadata["minus_di"] = current.minus_di
        return True, metadata

    return False, {}


def bollinger_squeeze(
    previous: Snapshot | None, current: Snapshot, bars: list[Bar], index: int
) -> RuleResult:
    """Bandwidth is at or below the minimum of the previous 119 sessions.

    Bandwidth is ``(upper - lower) / middle * 100`` recomputed from the closes
    through ``core.bollinger`` (the stored bands are untouched). It needs 139
    bars; a zero or missing middle band gives no bandwidth, so no signal.
    """

    if index + 1 < SQUEEZE_MIN_BARS or index >= len(bars):
        return None

    closes = [bar.close for bar in bars[index + 1 - SQUEEZE_MIN_BARS : index + 1]]
    upper, middle, lower = bollinger(closes, BOLLINGER_PERIOD, BOLLINGER_DEVIATIONS)

    widths: list[float] = []
    for position in range(len(closes) - SQUEEZE_LOOKBACK, len(closes)):
        up, mid, low = upper[position], middle[position], lower[position]
        if up is None or mid is None or low is None or mid == 0:
            return None
        widths.append((up - low) / mid * 100.0)

    bandwidth = widths[-1]
    lookback_min = min(widths[:-1])

    if bandwidth <= lookback_min:
        return True, {"bandwidth": bandwidth, "lookback_min": lookback_min}

    return False, {}


def bollinger_close_above_upper(
    previous: Snapshot | None, current: Snapshot, bars: list[Bar], index: int
) -> RuleResult:
    """Close crosses above the upper Bollinger band on the as-of bar."""

    if (
        previous is None
        or previous.bb_upper is None
        or current.bb_upper is None
        or index < 1
        or index >= len(bars)
    ):
        return None

    close = bars[index].close
    if bars[index - 1].close <= previous.bb_upper and close > current.bb_upper:
        return True, {"close": close, "bb_upper": current.bb_upper}

    return False, {}


def bollinger_close_below_lower(
    previous: Snapshot | None, current: Snapshot, bars: list[Bar], index: int
) -> RuleResult:
    """Close crosses below the lower Bollinger band on the as-of bar."""

    if (
        previous is None
        or previous.bb_lower is None
        or current.bb_lower is None
        or index < 1
        or index >= len(bars)
    ):
        return None

    close = bars[index].close
    if bars[index - 1].close >= previous.bb_lower and close < current.bb_lower:
        return True, {"close": close, "bb_lower": current.bb_lower}

    return False, {}


def stoch_bullish_cross(
    previous: Snapshot | None, current: Snapshot, bars: list[Bar], index: int
) -> RuleResult:
    """%K crosses above %D while the previous %K was at or below 20."""

    if (
        previous is None
        or previous.stoch_k is None
        or previous.stoch_d is None
        or current.stoch_k is None
        or current.stoch_d is None
    ):
        return None

    if (
        previous.stoch_k <= previous.stoch_d
        and current.stoch_k > current.stoch_d
        and previous.stoch_k <= STOCH_OVERSOLD
    ):
        return True, {"stoch_k": current.stoch_k, "stoch_d": current.stoch_d}

    return False, {}


def stoch_bearish_cross(
    previous: Snapshot | None, current: Snapshot, bars: list[Bar], index: int
) -> RuleResult:
    """%K crosses below %D while the previous %K was at or above 80."""

    if (
        previous is None
        or previous.stoch_k is None
        or previous.stoch_d is None
        or current.stoch_k is None
        or current.stoch_d is None
    ):
        return None

    if (
        previous.stoch_k >= previous.stoch_d
        and current.stoch_k < current.stoch_d
        and previous.stoch_k >= STOCH_OVERBOUGHT
    ):
        return True, {"stoch_k": current.stoch_k, "stoch_d": current.stoch_d}

    return False, {}


# The full, fixed signal vocabulary: the type string is the shared contract with
# Laravel (`database/factories/SignalFactory.php`) and the database.
RULES: tuple[tuple[str, Rule], ...] = (
    ("golden_cross", golden_cross),
    ("death_cross", death_cross),
    ("ma_alignment_bullish", ma_alignment_bullish),
    ("ma_alignment_bearish", ma_alignment_bearish),
    ("pivot_breakout_rvol", pivot_breakout_rvol),
    ("rsi_overbought", rsi_overbought),
    ("rsi_oversold", rsi_oversold),
    ("macd_bullish_cross", macd_bullish_cross),
    ("macd_bearish_cross", macd_bearish_cross),
    ("adx_trend_strength", adx_trend_strength),
    ("bollinger_squeeze", bollinger_squeeze),
    ("bollinger_close_above_upper", bollinger_close_above_upper),
    ("bollinger_close_below_lower", bollinger_close_below_lower),
    ("stoch_bullish_cross", stoch_bullish_cross),
    ("stoch_bearish_cross", stoch_bearish_cross),
)
