"""Offline tests for the Stooq EOD source and the /eod/{symbol} endpoint.

The fixture is committed and read from disk; these tests never touch the network.
"""

from datetime import date
from pathlib import Path

import httpx
import pytest
from fastapi.testclient import TestClient

from app import main as main_module
from app.models import Bar
from app.sources import stooq
from app.sources.stooq import parse_eod_csv, parse_yahoo_eod_json

FIXTURE = Path(__file__).parent / "fixtures" / "stooq_nvda.csv"
HEADER = "Date,Open,High,Low,Close,Volume"
client = TestClient(main_module.app)


def test_parse_fixture_returns_ordered_bars() -> None:
    text = FIXTURE.read_text(encoding="utf-8")
    data_rows = [line for line in text.splitlines() if line.strip()][1:]

    bars = parse_eod_csv(text)

    assert len(data_rows) > 0
    assert len(bars) == len(data_rows)
    assert all(isinstance(bar, Bar) for bar in bars)
    assert all(bar.date for bar in bars)
    assert bars == sorted(bars, key=lambda bar: bar.date)

    first = bars[0]
    assert first.date == date(2025, 9, 29)
    assert first.open == 180.43
    assert first.high == 184.00
    assert first.low == 180.32
    assert first.close == 181.85
    assert first.volume == 193063500

    last = bars[-1]
    assert last.date == date(2026, 9, 29)
    assert last.open == 230.99
    assert last.close == 228.16
    assert last.volume == 62821287


def test_parse_strips_utf8_bom_and_ignores_blank_lines() -> None:
    text = "\ufeff" + HEADER + "\r\n2026-01-02,10.00,11.00,9.50,10.50,1000\r\n\r\n"

    bars = parse_eod_csv(text)

    assert len(bars) == 1
    assert bars[0].date == date(2026, 1, 2)
    assert bars[0].close == 10.50
    assert bars[0].volume == 1000


def test_parse_returns_empty_for_empty_or_header_only_payloads() -> None:
    assert parse_eod_csv("") == []
    assert parse_eod_csv("   \n") == []
    assert parse_eod_csv(HEADER + "\n") == []


def test_parse_rejects_malformed_row() -> None:
    text = HEADER + "\n2026-01-02,10.00,11.00,9.50,10.50\n"

    with pytest.raises(ValueError):
        parse_eod_csv(text)


def test_parse_rejects_unparseable_values() -> None:
    text = HEADER + "\nnot-a-date,10.00,11.00,9.50,10.50,1000\n"

    with pytest.raises(ValueError):
        parse_eod_csv(text)


def test_parse_rejects_non_csv_payload() -> None:
    text = "<!DOCTYPE html><html><body>This site requires JavaScript.</body></html>"

    with pytest.raises(ValueError):
        parse_eod_csv(text)


def test_parse_yahoo_chart_response_skips_incomplete_sessions() -> None:
    text = """{
      "chart": {"result": [{
        "timestamp": [1760020200, 1760106600],
        "indicators": {"quote": [{
          "open": [10.0, null], "high": [11.0, null], "low": [9.0, null],
          "close": [10.5, null], "volume": [1000, null]
        }]}
      }], "error": null}
    }"""

    bars = parse_yahoo_eod_json(text)

    assert bars == [
        Bar(date=date(2025, 10, 9), open=10.0, high=11.0, low=9.0, close=10.5, volume=1000)
    ]


def test_fetch_uses_stooq_when_yahoo_is_unusable(monkeypatch: pytest.MonkeyPatch) -> None:
    expected = [Bar(date=date(2026, 9, 25), open=1.0, high=2.0, low=0.5, close=1.5, volume=10)]

    def failed_yahoo(symbol: str) -> list[Bar]:
        raise ValueError("unusable chart response")

    observed_symbol: str | None = None

    def successful_stooq(symbol: str) -> list[Bar]:
        nonlocal observed_symbol
        observed_symbol = symbol
        return expected

    monkeypatch.setattr(stooq, "_fetch_yahoo", failed_yahoo)
    monkeypatch.setattr(stooq, "_fetch_stooq", successful_stooq)

    assert stooq.fetch_eod("brk.b") == expected
    assert observed_symbol == "BRK.B"


def test_eod_endpoint_returns_parsed_bars(monkeypatch: pytest.MonkeyPatch) -> None:
    sample = [Bar(date=date(2026, 9, 25), open=1.0, high=2.0, low=0.5, close=1.5, volume=10)]
    monkeypatch.setattr(main_module, "fetch_eod", lambda symbol: sample)

    response = client.get("/eod/nvda")

    assert response.status_code == 200
    assert response.json() == {
        "symbol": "NVDA",
        "bars": [
            {
                "date": "2026-09-25",
                "open": 1.0,
                "high": 2.0,
                "low": 0.5,
                "close": 1.5,
                "volume": 10,
            }
        ],
    }


def test_eod_endpoint_returns_404_when_source_has_no_bars(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(main_module, "fetch_eod", lambda symbol: [])

    response = client.get("/eod/unknown")

    assert response.status_code == 404
    assert response.json()["detail"] == "No EOD data for UNKNOWN."


def test_eod_endpoint_returns_502_on_upstream_http_error(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    def raise_http_error(symbol: str) -> list[Bar]:
        raise httpx.ConnectError("upstream unreachable")

    monkeypatch.setattr(main_module, "fetch_eod", raise_http_error)

    response = client.get("/eod/nvda")

    assert response.status_code == 502
    assert "traceback" not in response.text.lower()
    assert set(response.json()) == {"detail"}


def test_eod_endpoint_returns_502_on_unusable_payload(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    def raise_value_error(symbol: str) -> list[Bar]:
        raise ValueError("unexpected EOD CSV header")

    monkeypatch.setattr(main_module, "fetch_eod", raise_value_error)

    response = client.get("/eod/nvda")

    assert response.status_code == 502
    assert set(response.json()) == {"detail"}
