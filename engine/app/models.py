"""Engine data models (pydantic schemas)."""

from datetime import date

from pydantic import BaseModel


class Bar(BaseModel):
    """One end-of-day OHLCV bar."""

    date: date
    open: float
    high: float
    low: float
    close: float
    volume: int


class EodResponse(BaseModel):
    """Parsed EOD bars for one symbol."""

    symbol: str
    bars: list[Bar]


class Snapshot(BaseModel):
    """Computed indicators for one bar.

    A field is ``None`` when the indicator's window is not fully available at
    that bar; the engine never emits a partial-window value.
    """

    date: date
    sma20: float | None = None
    sma50: float | None = None
    sma200: float | None = None
    ema21: float | None = None
    ema55: float | None = None
    rsi14: float | None = None
    adx: float | None = None
    macd: float | None = None
    macd_signal: float | None = None
    macd_hist: float | None = None
    bb_upper: float | None = None
    bb_middle: float | None = None
    bb_lower: float | None = None
    rvol: float | None = None


class IndicatorsComputeRequest(BaseModel):
    """Request body for ``POST /indicators/compute``."""

    bars: list[Bar]


class IndicatorsComputeResponse(BaseModel):
    """One indicator snapshot per submitted bar, in the same order."""

    snapshots: list[Snapshot]


class Signal(BaseModel):
    """A deterministic signal detected on the as-of bar.

    ``date`` is the as-of (latest) bar date and ``type`` is one of the fixed
    vocabulary strings; ``metadata`` carries the numeric values the rule used.
    """

    date: date
    type: str
    metadata: dict[str, float]


class SignalsDetectRequest(BaseModel):
    """Request body for ``POST /signals/detect``."""

    bars: list[Bar]


class SignalsDetectResponse(BaseModel):
    """The signals that hold on the as-of bar of the submitted series."""

    signals: list[Signal]


class PatternPoint(BaseModel):
    """One key point of a chartist pattern (a swing or a pattern extreme)."""

    date: date
    price: float
    role: str


class Pattern(BaseModel):
    """A chartist pattern active on the as-of bar.

    ``status`` is ``forming`` (geometry complete, no breakout yet) or
    ``confirmed`` (breakout close within the last sessions). ``breakout_level``
    is the single price line the pattern exposes; no target is ever computed.
    """

    type: str
    status: str
    as_of_date: date
    start_date: date
    end_date: date
    breakout_level: float
    points: list[PatternPoint]
    metadata: dict[str, float]


class PatternsDetectRequest(BaseModel):
    """Request body for ``POST /patterns/detect``."""

    bars: list[Bar]


class PatternsDetectResponse(BaseModel):
    """The patterns active on the as-of bar of the submitted series."""

    patterns: list[Pattern]
