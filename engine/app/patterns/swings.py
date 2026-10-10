"""Swing highs and lows: the turning points every pattern rule is built on.

A bar ``j`` is a swing high when its high is the *strict* maximum of the highs
from ``j - k`` to ``j + k``; a swing low mirrors it on the lows. Ties are not
swings, and the last ``k`` bars can never be swings, so a swing never depends
on bars after the as-of bar.
"""

from app.models import Bar

SWING_K = 5


def swing_highs(bars: list[Bar], k: int = SWING_K) -> list[int]:
    """Indices of swing highs in ascending order."""

    highs = [bar.high for bar in bars]
    return [j for j in range(k, len(bars) - k) if _is_strict_extreme(highs, j, k, higher=True)]


def swing_lows(bars: list[Bar], k: int = SWING_K) -> list[int]:
    """Indices of swing lows in ascending order."""

    lows = [bar.low for bar in bars]
    return [j for j in range(k, len(bars) - k) if _is_strict_extreme(lows, j, k, higher=False)]


def _is_strict_extreme(values: list[float], j: int, k: int, *, higher: bool) -> bool:
    centre = values[j]
    for offset in range(-k, k + 1):
        if offset == 0:
            continue
        other = values[j + offset]
        if (higher and other >= centre) or (not higher and other <= centre):
            return False
    return True
