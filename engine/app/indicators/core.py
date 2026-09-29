"""Indicator math over a bar series (pure Python standard library only).

Every function returns a value-aligned list (same length as the input) where a
position without enough history is ``None``. No partial-window or placeholder
value is ever produced, so a caller can always distinguish "not enough history"
from a real number.

Periods are fixed by the feature spec: SMA 20/50/200, EMA 21/55, RSI 14,
MACD 12/26/9, ADX 14, Bollinger 20/2, RVOL against the previous 50 sessions.
"""


def sma(values: list[float], period: int) -> list[float | None]:
    """Simple moving average; ``None`` until ``period`` values are available."""

    result: list[float | None] = [None] * len(values)
    if period <= 0 or len(values) < period:
        return result

    window = sum(values[:period])
    result[period - 1] = window / period

    for index in range(period, len(values)):
        window += values[index] - values[index - period]
        result[index] = window / period

    return result


def ema(values: list[float], period: int) -> list[float | None]:
    """Exponential moving average seeded with the SMA of the first ``period`` values."""

    result: list[float | None] = [None] * len(values)
    if period <= 0 or len(values) < period:
        return result

    multiplier = 2.0 / (period + 1)
    previous = sum(values[:period]) / period
    result[period - 1] = previous

    for index in range(period, len(values)):
        previous = (values[index] - previous) * multiplier + previous
        result[index] = previous

    return result


def _rsi_value(average_gain: float, average_loss: float) -> float:
    if average_loss == 0.0:
        # No downside in the window: the standard convention is RSI 100. A flat
        # series has no loss either, so it also reads 100 (documented behavior).
        return 100.0

    relative_strength = average_gain / average_loss

    return 100.0 - 100.0 / (1.0 + relative_strength)


def rsi(values: list[float], period: int = 14) -> list[float | None]:
    """Wilder's Relative Strength Index; ``None`` until ``period`` changes exist."""

    result: list[float | None] = [None] * len(values)
    if period <= 0 or len(values) < period + 1:
        return result

    gains = 0.0
    losses = 0.0
    for index in range(1, period + 1):
        change = values[index] - values[index - 1]
        if change > 0:
            gains += change
        elif change < 0:
            losses -= change

    average_gain = gains / period
    average_loss = losses / period
    result[period] = _rsi_value(average_gain, average_loss)

    for index in range(period + 1, len(values)):
        change = values[index] - values[index - 1]
        gain = change if change > 0 else 0.0
        loss = -change if change < 0 else 0.0
        average_gain = (average_gain * (period - 1) + gain) / period
        average_loss = (average_loss * (period - 1) + loss) / period
        result[index] = _rsi_value(average_gain, average_loss)

    return result


def macd(
    values: list[float], fast: int = 12, slow: int = 26, signal: int = 9
) -> tuple[list[float | None], list[float | None], list[float | None]]:
    """MACD line, signal line and histogram.

    The MACD line is ``EMA(fast) - EMA(slow)`` and is available once both EMAs
    are seeded (index ``slow - 1``). The signal line is the EMA of the available
    MACD values, so it starts ``signal - 1`` positions later; the histogram is
    the MACD minus the signal.
    """

    empty: list[float | None] = [None] * len(values)
    if fast <= 0 or slow <= 0 or signal <= 0 or fast >= slow:
        return empty, list(empty), list(empty)

    fast_ema = ema(values, fast)
    slow_ema = ema(values, slow)
    macd_line: list[float | None] = [None] * len(values)
    for index in range(len(values)):
        fast_value = fast_ema[index]
        slow_value = slow_ema[index]
        if fast_value is not None and slow_value is not None:
            macd_line[index] = fast_value - slow_value

    # The MACD segment defined from ``slow - 1`` onwards has no gaps, so it maps
    # back onto the full series at that offset.
    offset = slow - 1
    defined_macd = [value for value in macd_line[offset:] if value is not None]
    signal_line: list[float | None] = [None] * len(values)
    for position, value in enumerate(ema(defined_macd, signal)):
        signal_line[offset + position] = value

    histogram: list[float | None] = [None] * len(values)
    for index in range(len(values)):
        macd_value = macd_line[index]
        signal_value = signal_line[index]
        if macd_value is not None and signal_value is not None:
            histogram[index] = macd_value - signal_value

    return macd_line, signal_line, histogram


def _dx(true_range_sum: float, plus_sum: float, minus_sum: float) -> float:
    if true_range_sum == 0.0:
        return 0.0

    plus_di = 100.0 * plus_sum / true_range_sum
    minus_di = 100.0 * minus_sum / true_range_sum
    total = plus_di + minus_di
    if total == 0.0:
        return 0.0

    return 100.0 * abs(plus_di - minus_di) / total


def adx(
    highs: list[float], lows: list[float], closes: list[float], period: int = 14
) -> list[float | None]:
    """Wilder's Average Directional Index.

    The first DX is measured at index ``period`` and ADX averages ``period`` DX
    values, so ``period`` must have at least ``2 * period`` bars. A window with
    no true range (a flat series) yields DX/ADX 0 instead of a division error.
    """

    length = len(highs)
    result: list[float | None] = [None] * length
    if period <= 0 or length < 2 * period:
        return result

    true_ranges = [0.0] * length
    plus_dm = [0.0] * length
    minus_dm = [0.0] * length
    for index in range(1, length):
        up_move = highs[index] - highs[index - 1]
        down_move = lows[index - 1] - lows[index]
        if up_move > down_move and up_move > 0.0:
            plus_dm[index] = up_move
        if down_move > up_move and down_move > 0.0:
            minus_dm[index] = down_move
        true_ranges[index] = max(
            highs[index] - lows[index],
            abs(highs[index] - closes[index - 1]),
            abs(lows[index] - closes[index - 1]),
        )

    smoothed_range = sum(true_ranges[1 : period + 1])
    smoothed_plus = sum(plus_dm[1 : period + 1])
    smoothed_minus = sum(minus_dm[1 : period + 1])

    dx_values = [0.0] * length
    dx_values[period] = _dx(smoothed_range, smoothed_plus, smoothed_minus)
    for index in range(period + 1, length):
        smoothed_range = smoothed_range - smoothed_range / period + true_ranges[index]
        smoothed_plus = smoothed_plus - smoothed_plus / period + plus_dm[index]
        smoothed_minus = smoothed_minus - smoothed_minus / period + minus_dm[index]
        dx_values[index] = _dx(smoothed_range, smoothed_plus, smoothed_minus)

    first = 2 * period - 1
    previous = sum(dx_values[period : first + 1]) / period
    result[first] = previous
    for index in range(first + 1, length):
        previous = (previous * (period - 1) + dx_values[index]) / period
        result[index] = previous

    return result


def bollinger(
    values: list[float], period: int = 20, deviations: float = 2.0
) -> tuple[list[float | None], list[float | None], list[float | None]]:
    """Bollinger Bands (upper, middle, lower) using the population std dev.

    A flat window has zero deviation, so all three bands collapse on the middle.
    """

    middle = sma(values, period)
    upper: list[float | None] = [None] * len(values)
    lower: list[float | None] = [None] * len(values)
    if period <= 0:
        return upper, middle, lower

    for index in range(period - 1, len(values)):
        average = middle[index]
        if average is None:
            continue
        window = values[index - period + 1 : index + 1]
        variance = sum((value - average) ** 2 for value in window) / period
        deviation = variance**0.5
        upper[index] = average + deviations * deviation
        lower[index] = average - deviations * deviation

    return upper, middle, lower


def rvol(volumes: list[float], period: int = 50) -> list[float | None]:
    """Relative volume: the current volume vs the previous ``period`` average.

    The baseline excludes the current bar, so the first value needs
    ``period + 1`` bars. A zero-volume baseline yields ``None`` (no ratio).
    """

    result: list[float | None] = [None] * len(volumes)
    if period <= 0 or len(volumes) < period + 1:
        return result

    baseline = sum(volumes[:period])
    for index in range(period, len(volumes)):
        if baseline > 0.0:
            result[index] = volumes[index] / (baseline / period)
        baseline += volumes[index] - volumes[index - period]

    return result
