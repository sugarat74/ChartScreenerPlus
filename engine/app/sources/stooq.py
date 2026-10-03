"""Stooq end-of-day CSV source.

The engine fetches and parses here; it never touches the database. The parsed
bars are returned over HTTP and Laravel (the DB owner) persists them.

Source: ``https://stooq.com/q/d/l/?s={symbol}.us&i=d`` (daily, unadjusted OHLCV,
header ``Date,Open,High,Low,Close,Volume``). The source is fragile: its markup,
availability and rate limits can change, and it may serve an HTML anti-bot
challenge instead of CSV. Parsing is deliberately isolated and strict so an
unusable response becomes a controlled upstream error rather than bad data.
"""

import csv
import io
import json
from datetime import date, datetime, timezone

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


def parse_yahoo_eod_json(text: str) -> list[Bar]:
    """Parse a Yahoo Finance chart response into ordered daily bars.

    Yahoo may include ``null`` OHLCV fields for an incomplete session. Those
    entries are not usable EOD bars and are skipped; an invalid response shape
    remains a controlled upstream error.
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
                date=datetime.fromtimestamp(timestamp, tz=timezone.utc).date(),
                open=float(open_price),
                high=float(high_price),
                low=float(low_price),
                close=float(close_price),
                volume=int(volume),
            )
        )

    bars.sort(key=lambda bar: bar.date)

    return bars


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


def _fetch_yahoo(normalized_symbol: str) -> list[Bar]:
    """Fetch the fallback chart API, translating class-share dots to hyphens."""

    url = YAHOO_CHART_URL.format(symbol=normalized_symbol.replace(".", "-"))

    with httpx.Client(
        timeout=REQUEST_TIMEOUT_SECONDS, follow_redirects=True, headers={"User-Agent": USER_AGENT}
    ) as client:
        response = client.get(url)
        response.raise_for_status()
        return parse_yahoo_eod_json(response.text)


def fetch_eod(symbol: str) -> list[Bar]:
    """Fetch daily EOD bars from Yahoo, falling back when it is unusable.

    Stooq's current anti-bot challenge can consume the complete request timeout
    before returning unusable content. Yahoo Finance is therefore attempted
    first, while the strict Stooq CSV parser remains the fallback.
    """

    normalized_symbol = symbol.strip().upper()
    if not normalized_symbol:
        raise ValueError("symbol must not be empty")

    try:
        return _fetch_yahoo(normalized_symbol)
    except (httpx.HTTPError, ValueError):
        return _fetch_stooq(normalized_symbol)
