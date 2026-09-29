"""Detect deterministic signals on the as-of (latest) bar of a series.

The engine computes indicators for the whole series but emits signals only for
the last bar; a rule that needs a crossing reads the previous bar's snapshot.
The engine owns the rules and never touches the database: Laravel (the database
owner) persists (replaces) the returned set.
"""

from app.indicators.snapshots import compute_snapshots
from app.models import Bar, Signal
from app.signals.rules import RULES


def detect_signals(bars: list[Bar]) -> list[Signal]:
    """Return the signals that hold on the last bar of ``bars``.

    Fewer than two bars cannot form a cross or an as-of evaluation, so the
    result is empty. The result carries the as-of bar's date; each type appears
    at most once.
    """

    if len(bars) < 2:
        return []

    snapshots = compute_snapshots(bars)
    index = len(bars) - 1
    previous = snapshots[index - 1]
    current = snapshots[index]

    detected: list[Signal] = []

    for signal_type, rule in RULES:
        result = rule(previous, current, bars, index)

        if result is None or not result[0]:
            continue

        detected.append(
            Signal(date=current.date, type=signal_type, metadata=result[1])
        )

    return detected
