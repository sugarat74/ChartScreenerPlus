"""Build one indicator snapshot per bar from a full EOD bar series.

The engine computes from the bars it is given and never touches the database;
Laravel (the database owner) persists the returned snapshots.
"""

from app.indicators.core import adx, bollinger, ema, macd, rsi, rvol, sma
from app.models import Bar, Snapshot

SMA_PERIODS = (20, 50, 200)
EMA_PERIODS = (21, 55)
RSI_PERIOD = 14
MACD_PERIODS = (12, 26, 9)
ADX_PERIOD = 14
BOLLINGER_PERIOD = 20
BOLLINGER_DEVIATIONS = 2.0
RVOL_PERIOD = 50


def compute_snapshots(bars: list[Bar]) -> list[Snapshot]:
    """Compute indicators for every bar, returning ``None`` for missing history.

    The result is aligned with ``bars`` (same order, same length). A field is
    ``None`` whenever the indicator's window is not fully available at that bar;
    a partial-window value is never produced.
    """

    closes = [bar.close for bar in bars]
    highs = [bar.high for bar in bars]
    lows = [bar.low for bar in bars]
    volumes = [float(bar.volume) for bar in bars]

    sma20 = sma(closes, SMA_PERIODS[0])
    sma50 = sma(closes, SMA_PERIODS[1])
    sma200 = sma(closes, SMA_PERIODS[2])
    ema21 = ema(closes, EMA_PERIODS[0])
    ema55 = ema(closes, EMA_PERIODS[1])
    rsi14 = rsi(closes, RSI_PERIOD)
    adx14 = adx(highs, lows, closes, ADX_PERIOD)
    macd_line, macd_signal, macd_hist = macd(closes, *MACD_PERIODS)
    bb_upper, bb_middle, bb_lower = bollinger(
        closes, BOLLINGER_PERIOD, BOLLINGER_DEVIATIONS
    )
    rvol50 = rvol(volumes, RVOL_PERIOD)

    return [
        Snapshot(
            date=bar.date,
            sma20=sma20[index],
            sma50=sma50[index],
            sma200=sma200[index],
            ema21=ema21[index],
            ema55=ema55[index],
            rsi14=rsi14[index],
            adx=adx14[index],
            macd=macd_line[index],
            macd_signal=macd_signal[index],
            macd_hist=macd_hist[index],
            bb_upper=bb_upper[index],
            bb_middle=bb_middle[index],
            bb_lower=bb_lower[index],
            rvol=rvol50[index],
        )
        for index, bar in enumerate(bars)
    ]
