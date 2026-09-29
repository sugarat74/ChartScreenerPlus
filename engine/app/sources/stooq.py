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
from datetime import date

import httpx

from app.models import Bar

STOOQ_URL = "https://stooq.com/q/d/l/?s={symbol}&i=d"
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


def fetch_eod(symbol: str) -> list[Bar]:
    """Fetch and parse the daily EOD bars for one symbol from Stooq.

    ``symbol`` is a plain ticker (``NVDA``); the ``.us`` suffix is added when
    missing. Network/HTTP failures propagate as ``httpx.HTTPError``; an unusable
    payload propagates as ``ValueError``. The caller (HTTP endpoint) turns both
    into controlled client errors.
    """

    normalized_symbol = symbol.strip().lower()
    if not normalized_symbol:
        raise ValueError("symbol must not be empty")
    if not normalized_symbol.endswith(".us"):
        normalized_symbol = f"{normalized_symbol}.us"

    url = STOOQ_URL.format(symbol=normalized_symbol)
    headers = {"User-Agent": USER_AGENT, "Accept": "text/csv,text/plain;q=0.9,*/*;q=0.8"}

    with httpx.Client(
        timeout=REQUEST_TIMEOUT_SECONDS, follow_redirects=True, headers=headers
    ) as client:
        response = client.get(url)
        response.raise_for_status()
        return parse_eod_csv(response.text)
