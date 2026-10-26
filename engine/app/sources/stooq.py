"""Stooq end-of-day CSV source.

The engine fetches and parses here; it never touches the database. The parsed
bars are returned over HTTP and Laravel (the DB owner) persists them.

Source: ``https://stooq.com/q/d/l/?s={symbol}.us&i=d`` (US only; daily, unadjusted OHLCV,
header ``Date,Open,High,Low,Close,Volume``). The source is fragile: its markup,
availability and rate limits can change, and it may serve an HTML anti-bot
challenge instead of CSV. Parsing is deliberately isolated and strict so an
unusable response becomes a controlled upstream error rather than bad data.
"""

import csv
import io
import json
from dataclasses import dataclass
from datetime import date, datetime
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

import httpx

from app.models import Bar

STOOQ_URL = "https://stooq.com/q/d/l/?s={symbol}&i=d"
YAHOO_CHART_URL = (
    "https://query1.finance.yahoo.com/v8/finance/chart/"
    "{symbol}?range=2y&interval=1d&events=history"
)
EXPECTED_COLUMNS = ("date", "open", "high", "low", "close", "volume")
REQUEST_TIMEOUT_SECONDS = 30.0
USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/124.0 Safari/537.36"
)


# Listing Markets (docs/specs/multi-market-universes.md, D1). The suffix is the
# ticker suffix Yahoo uses for the exchange; US tickers carry none.
MARKET_TIMEZONES = {
    "US": "America/New_York",
    "XMAD": "Europe/Madrid",
    "XETR": "Europe/Berlin",
}
MARKET_SUFFIXES = {"US": None, "XMAD": ".MC", "XETR": ".DE"}
DEFAULT_MARKET = "US"


@dataclass(frozen=True)
class EodResult:
    """Parsed bars plus the currency the source reported (None if unknown)."""

    bars: list[Bar]
    currency: str | None = None


def symbol_matches_market(symbol: str, market: str) -> bool:
    """Whether the symbol follows the suffix rule of the market.

    .MC is exactly XMAD and .DE exactly XETR; a US symbol ends in neither.
    """

    if market not in MARKET_SUFFIXES:
        return False

    upper = symbol.upper()
    for code, suffix in MARKET_SUFFIXES.items():
        if suffix is None:
            continue
        if upper.endswith(suffix) != (code == market):
            return False

    return True


def yahoo_symbol(symbol: str, market: str) -> str:
    """Yahoo symbol: US class-share dots become hyphens, European tickers stay verbatim."""

    if market == "US":
        return symbol.replace(".", "-")

    return symbol


def parse_eod_csv(text: str) -> list[Bar]:
    """Parse a Stooq EOD CSV payload into ordered bars.

    Blank lines are ignored. Any non-blank row that does not have the six
    expected columns with a valid date/number is rejected with ``ValueError``
    (the source is treated as unusable instead of importing corrupt data).
    """

    if not text or not text.strip():
        return []

    reader = csv.reader(io.StringIO(text.lstrip("\ufeff")))
    try:
        header = next(reader)
    except StopIteration:  # pragma: no cover - defensive; splitlines already handled empty
        return []

    normalized_header = tuple(column.strip().lower() for column in header)
    if normalized_header != EXPECTED_COLUMNS:
        raise ValueError(f"unexpected EOD CSV header: {header!r}")

    bars: list[Bar] = []
    for line_number, row in enumerate(reader, start=2):
        if not row or all(not cell.strip() for cell in row):
            continue
        if len(row) != len(EXPECTED_COLUMNS):
            raise ValueError(
                f"malformed EOD CSV row {line_number}: "
                f"expected {len(EXPECTED_COLUMNS)} columns, got {len(row)}"
            )

        raw_date, raw_open, raw_high, raw_low, raw_close, raw_volume = (
            cell.strip() for cell in row
        )
        try:
            bar_date = date.fromisoformat(raw_date)
            open_price = float(raw_open)
            high_price = float(raw_high)
            low_price = float(raw_low)
            close_price = float(raw_close)
            volume = int(float(raw_volume))
        except ValueError as exc:
            raise ValueError(f"malformed EOD CSV row {line_number}: {exc}") from exc

        bars.append(
            Bar(
                date=bar_date,
                open=open_price,
                high=high_price,
                low=low_price,
                close=close_price,
                volume=volume,
            )
        )

    bars.sort(key=lambda bar: bar.date)

    return bars


def _resolve_timezone(name: object, market: str) -> ZoneInfo:
    """The exchange timezone from the response, else the market's own timezone."""

    if isinstance(name, str) and name:
        try:
            return ZoneInfo(name)
        except (ZoneInfoNotFoundError, ValueError):
            pass

    return ZoneInfo(MARKET_TIMEZONES.get(market, MARKET_TIMEZONES[DEFAULT_MARKET]))


def parse_yahoo_eod(text: str, market: str = DEFAULT_MARKET) -> EodResult:
    """Parse a Yahoo Finance chart response into ordered daily bars and currency.

    Yahoo may include null OHLCV fields for an incomplete session. Those
    entries are not usable EOD bars and are skipped; an invalid response shape
    remains a controlled upstream error.

    Bar dates are the session date in the exchange timezone
    (chart.meta.exchangeTimezoneName, else the market's timezone), never in
    UTC: a daily timestamp at midnight local time is the previous day in UTC
    for European exchanges.
    """

    try:
        payload = json.loads(text)
        result = payload["chart"]["result"]
        chart = result[0]
        timestamps = chart["timestamp"]
        quote = chart["indicators"]["quote"][0]
        opens = quote["open"]
        highs = quote["high"]
        lows = quote["low"]
        closes = quote["close"]
        volumes = quote["volume"]
    except (IndexError, KeyError, TypeError, json.JSONDecodeError) as exc:
        raise ValueError("unexpected Yahoo Finance chart response") from exc

    meta = chart.get("meta") if isinstance(chart, dict) else None
    meta = meta if isinstance(meta, dict) else {}
    timezone_info = _resolve_timezone(meta.get("exchangeTimezoneName"), market)
    raw_currency = meta.get("currency")
    currency = (
        raw_currency.strip().upper()
        if isinstance(raw_currency, str) and raw_currency.strip()
        else None
    )

    series = (timestamps, opens, highs, lows, closes, volumes)
    if not all(isinstance(values, list) for values in series):
        raise ValueError("unexpected Yahoo Finance chart series")
    if not all(len(values) == len(timestamps) for values in series):
        raise ValueError("mismatched Yahoo Finance chart series lengths")

    bars: list[Bar] = []
    for timestamp, open_price, high_price, low_price, close_price, volume in zip(
        timestamps, opens, highs, lows, closes, volumes
    ):
        if any(
            value is None for value in (open_price, high_price, low_price, close_price, volume)
        ):
            continue
        if not all(
            isinstance(value, (int, float))
            for value in (timestamp, open_price, high_price, low_price, close_price, volume)
        ):
            raise ValueError("invalid Yahoo Finance chart value")

        bars.append(
            Bar(
                date=datetime.fromtimestamp(timestamp, tz=timezone_info).date(),
                open=float(open_price),
                high=float(high_price),
                low=float(low_price),
                close=float(close_price),
                volume=int(volume),
            )
        )

    bars.sort(key=lambda bar: bar.date)

    return EodResult(bars=bars, currency=currency)


def parse_yahoo_eod_json(text: str, market: str = DEFAULT_MARKET) -> list[Bar]:
    """Parse a Yahoo Finance chart response into ordered daily bars."""

    return parse_yahoo_eod(text, market).bars


def _fetch_stooq(normalized_symbol: str) -> list[Bar]:
    """Fetch the preferred Stooq CSV source for a normalized ticker."""

    url = STOOQ_URL.format(symbol=f"{normalized_symbol.lower()}.us")
    headers = {"User-Agent": USER_AGENT, "Accept": "text/csv,text/plain;q=0.9,*/*;q=0.8"}

    with httpx.Client(
        timeout=REQUEST_TIMEOUT_SECONDS, follow_redirects=True, headers=headers
    ) as client:
        response = client.get(url)
        response.raise_for_status()
        return parse_eod_csv(response.text)


def _fetch_yahoo(normalized_symbol: str, market: str = DEFAULT_MARKET) -> EodResult:
    """Fetch the Yahoo chart API with the market's symbol mapping."""

    url = YAHOO_CHART_URL.format(symbol=yahoo_symbol(normalized_symbol, market))

    with httpx.Client(
        timeout=REQUEST_TIMEOUT_SECONDS, follow_redirects=True, headers={"User-Agent": USER_AGENT}
    ) as client:
        response = client.get(url)
        response.raise_for_status()
        return parse_yahoo_eod(response.text, market)


def fetch_eod(symbol: str, market: str = DEFAULT_MARKET) -> EodResult:
    """Fetch daily EOD bars from Yahoo, falling back to Stooq for US only.

    Stooq's current anti-bot challenge can consume the complete request timeout
    before returning unusable content. Yahoo Finance is therefore attempted
    first, while the strict Stooq CSV parser remains the fallback for US.
    No Stooq mapping for XMAD/XETR has been verified (the symbol rule
    {symbol}.us is US-only), so a failing Yahoo call for a European market
    is a controlled upstream error with no fallback.
    """

    normalized_symbol = symbol.strip().upper()
    if not normalized_symbol:
        raise ValueError("symbol must not be empty")
    if market not in MARKET_SUFFIXES:
        raise ValueError(f"unknown listing market: {market}")

    try:
        return _fetch_yahoo(normalized_symbol, market)
    except (httpx.HTTPError, ValueError):
        if market != DEFAULT_MARKET:
            raise
        return EodResult(bars=_fetch_stooq(normalized_symbol), currency=None)
