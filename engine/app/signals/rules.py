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

from app.models import Bar, Snapshot

RuleResult = tuple[bool, dict[str, float]] | None
Rule = Callable[[Snapshot | None, Snapshot, list[Bar], int], RuleResult]

PIVOT_LOOKBACK = 20
RVOL_MINIMUM = 2.0
RSI_OVERBOUGHT = 70.0
RSI_OVERSOLD = 30.0


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
)
