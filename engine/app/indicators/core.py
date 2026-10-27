"""Indicator math over a bar series (pure Python standard library only).

Every function returns a value-aligned list (same length as the input) where a
position without enough history is ``None``. No partial-window or placeholder
value is ever produced, so a caller can always distinguish "not enough history"
from a real number.

Periods are fixed by the feature spec: SMA 20/50/200, EMA 21/55, RSI 14,
MACD 12/26/9, ADX 14 (+DI/-DI 14), ATR 14, Stochastic 14/3/3,
Bollinger 20/2, RVOL against the previous 50 sessions.
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


def _wilder_sums(
    highs: list[float], lows: list[float], closes: list[float], period: int
) -> list[tuple[float, float, float] | None]:
    """Wilder-smoothed (true range, +DM, -DM) sums, ``None`` before ``period``.

    The first sum sits at index ``period`` (bars ``1..period``); every later one
    is ``previous - previous / period + current``. Shared by ``adx`` and the DI
    lines so both read the very same smoothing.
    """

    length = len(highs)
    sums: list[tuple[float, float, float] | None] = [None] * length
    if period <= 0 or length < period + 1:
        return sums

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
        true_ranges[index] = _true_range(highs, lows, closes, index)

    smoothed_range = sum(true_ranges[1 : period + 1])
    smoothed_plus = sum(plus_dm[1 : period + 1])
    smoothed_minus = sum(minus_dm[1 : period + 1])
    sums[period] = (smoothed_range, smoothed_plus, smoothed_minus)
    for index in range(period + 1, length):
        smoothed_range = smoothed_range - smoothed_range / period + true_ranges[index]
        smoothed_plus = smoothed_plus - smoothed_plus / period + plus_dm[index]
        smoothed_minus = smoothed_minus - smoothed_minus / period + minus_dm[index]
        sums[index] = (smoothed_range, smoothed_plus, smoothed_minus)

    return sums


def _true_range(
    highs: list[float], lows: list[float], closes: list[float], index: int
) -> float:
    return max(
        highs[index] - lows[index],
        abs(highs[index] - closes[index - 1]),
        abs(lows[index] - closes[index - 1]),
    )


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

    dx_values = [0.0] * length
    for index, current in enumerate(_wilder_sums(highs, lows, closes, period)):
        if current is not None:
            dx_values[index] = _dx(*current)

    first = 2 * period - 1
    previous = sum(dx_values[period : first + 1]) / period
    result[first] = previous
    for index in range(first + 1, length):
        previous = (previous * (period - 1) + dx_values[index]) / period
        result[index] = previous

    return result


def directional_indicators(
    highs: list[float], lows: list[float], closes: list[float], period: int = 14
) -> tuple[list[float | None], list[float | None]]:
    """Wilder's ``(+DI, -DI)``; first value at index ``period``.

    ``DI = 100 * smoothed(DM) / smoothed(TR)``; a zero smoothed true range (a
    flat series) gives 0 instead of a division error.
    """

    plus_di: list[float | None] = [None] * len(highs)
    minus_di: list[float | None] = [None] * len(highs)
    for index, current in enumerate(_wilder_sums(highs, lows, closes, period)):
        if current is None:
            continue
        true_range_sum, plus_sum, minus_sum = current
        if true_range_sum == 0.0:
            plus_di[index] = 0.0
            minus_di[index] = 0.0
        else:
            plus_di[index] = 100.0 * plus_sum / true_range_sum
            minus_di[index] = 100.0 * minus_sum / true_range_sum

    return plus_di, minus_di


def atr(
    highs: list[float], lows: list[float], closes: list[float], period: int = 14
) -> list[float | None]:
    """Wilder's Average True Range; first value at index ``period``.

    The seed is the mean of ``TR[1..period]`` (bar 0 has no true range), then
    ``ATR = (previous * (period - 1) + TR) / period``. A flat series gives 0.
    """

    length = len(highs)
    result: list[float | None] = [None] * length
    if period <= 0 or length < period + 1:
        return result

    true_ranges = [0.0] * length
    for index in range(1, length):
        true_ranges[index] = _true_range(highs, lows, closes, index)

    previous = sum(true_ranges[1 : period + 1]) / period
    result[period] = previous
    for index in range(period + 1, length):
        previous = (previous * (period - 1) + true_ranges[index]) / period
        result[index] = previous

    return result


def _clamp_percent(value: float | None) -> float | None:
    return None if value is None else min(100.0, max(0.0, value))


def stochastic(
    highs: list[float],
    lows: list[float],
    closes: list[float],
    period: int = 14,
    smooth_k: int = 3,
    smooth_d: int = 3,
) -> tuple[list[float | None], list[float | None]]:
    """Slow Stochastic ``(%K, %D)``.

    ``raw = 100 * (close - lowest low) / (highest high - lowest low)`` over the
    ``period`` sessions ending at the bar (first at ``period - 1``). A window
    with no range reads the neutral 50.0 so the next average gets contiguous
    input. ``%K = SMA(raw, smooth_k)`` and ``%D = SMA(%K, smooth_d)``.
    """

    length = len(highs)
    k_line: list[float | None] = [None] * length
    d_line: list[float | None] = [None] * length
    if period <= 0 or smooth_k <= 0 or smooth_d <= 0 or length < period:
        return k_line, d_line

    raw: list[float] = []
    for index in range(period - 1, length):
        highest = max(highs[index - period + 1 : index + 1])
        lowest = min(lows[index - period + 1 : index + 1])
        if highest == lowest:
            raw.append(50.0)
        else:
            raw.append(100.0 * (closes[index] - lowest) / (highest - lowest))

    k_offset = period - 1
    k_values = sma(raw, smooth_k)
    for position, value in enumerate(k_values):
        k_line[k_offset + position] = _clamp_percent(value)

    # %K is defined without gaps from ``k_offset + smooth_k - 1`` onwards.
    d_offset = k_offset + smooth_k - 1
    defined_k = [value for value in k_values if value is not None]
    for position, value in enumerate(sma(defined_k, smooth_d)):
        d_line[d_offset + position] = _clamp_percent(value)

    return k_line, d_line


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
