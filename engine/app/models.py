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
