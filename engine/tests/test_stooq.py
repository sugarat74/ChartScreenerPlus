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
from app.sources.stooq import EodResult, parse_eod_csv, parse_yahoo_eod, parse_yahoo_eod_json

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

    def failed_yahoo(symbol: str, market: str = "US") -> EodResult:
        raise ValueError("unusable chart response")

    observed_symbol: str | None = None

    def successful_stooq(symbol: str) -> list[Bar]:
        nonlocal observed_symbol
        observed_symbol = symbol
        return expected

    monkeypatch.setattr(stooq, "_fetch_yahoo", failed_yahoo)
    monkeypatch.setattr(stooq, "_fetch_stooq", successful_stooq)

    assert stooq.fetch_eod("brk.b") == EodResult(bars=expected, currency=None)
    assert observed_symbol == "BRK.B"


def test_eod_endpoint_returns_parsed_bars(monkeypatch: pytest.MonkeyPatch) -> None:
    sample = [Bar(date=date(2026, 9, 25), open=1.0, high=2.0, low=0.5, close=1.5, volume=10)]
    monkeypatch.setattr(
        main_module, "fetch_eod", lambda symbol, market="US": EodResult(bars=sample)
    )

    response = client.get("/eod/nvda")

    assert response.status_code == 200
    assert response.json() == {
        "symbol": "NVDA",
        "currency": None,
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
    monkeypatch.setattr(
        main_module, "fetch_eod", lambda symbol, market="US": EodResult(bars=[])
    )

    response = client.get("/eod/unknown")

    assert response.status_code == 404
    assert response.json()["detail"] == "No EOD data for UNKNOWN."


def test_eod_endpoint_returns_502_on_upstream_http_error(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    def raise_http_error(symbol: str, market: str = "US") -> EodResult:
        raise httpx.ConnectError("upstream unreachable")

    monkeypatch.setattr(main_module, "fetch_eod", raise_http_error)

    response = client.get("/eod/nvda")

    assert response.status_code == 502
    assert "traceback" not in response.text.lower()
    assert set(response.json()) == {"detail"}


def test_eod_endpoint_returns_502_on_unusable_payload(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    def raise_value_error(symbol: str, market: str = "US") -> EodResult:
        raise ValueError("unexpected EOD CSV header")

    monkeypatch.setattr(main_module, "fetch_eod", raise_value_error)

    response = client.get("/eod/nvda")

    assert response.status_code == 502
    assert set(response.json()) == {"detail"}


# --- Multi-market (docs/specs/multi-market-universes.md, Feature 2) ---------------

SAP_FIXTURE = Path(__file__).parent / "fixtures" / "yahoo_sap_de.json"


def test_yahoo_midnight_berlin_timestamps_yield_session_dates() -> None:
    # The fixture's timestamps are midnight Europe/Berlin (CEST then CET): in UTC they
    # fall on Thursday, Sunday and Monday. The bar dates must be the local sessions.
    result = parse_yahoo_eod(SAP_FIXTURE.read_text(encoding="utf-8"), "XETR")

    assert [bar.date for bar in result.bars] == [
        date(2026, 10, 22),
        date(2026, 10, 23),
        date(2026, 10, 26),
        date(2026, 10, 27),
    ]
    assert all(bar.date.weekday() < 5 for bar in result.bars)
    assert result.currency == "EUR"


def test_yahoo_dates_fall_back_to_the_market_timezone_without_meta_timezone() -> None:
    import json

    payload = json.loads(SAP_FIXTURE.read_text(encoding="utf-8"))
    del payload["chart"]["result"][0]["meta"]["exchangeTimezoneName"]

    result = parse_yahoo_eod(json.dumps(payload), "XETR")

    assert [bar.date for bar in result.bars][1:3] == [date(2026, 10, 23), date(2026, 10, 26)]


def test_yahoo_unknown_meta_timezone_falls_back_to_the_market_timezone() -> None:
    import json

    payload = json.loads(SAP_FIXTURE.read_text(encoding="utf-8"))
    payload["chart"]["result"][0]["meta"]["exchangeTimezoneName"] = "Mars/Olympus"

    result = parse_yahoo_eod(json.dumps(payload), "XETR")

    assert result.bars[2].date == date(2026, 10, 26)


def test_yahoo_without_meta_has_no_currency() -> None:
    text = """{
      "chart": {"result": [{
        "timestamp": [1760020200],
        "indicators": {"quote": [{
          "open": [10.0], "high": [11.0], "low": [9.0], "close": [10.5], "volume": [1000]
        }]}
      }], "error": null}
    }"""

    assert parse_yahoo_eod(text).currency is None


def test_us_symbol_keeps_the_dot_to_hyphen_rule_and_european_symbols_stay_verbatim(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    urls: list[str] = []

    class FakeResponse:
        text = SAP_FIXTURE.read_text(encoding="utf-8")

        def raise_for_status(self) -> None:
            return None

    class FakeClient:
        def __init__(self, *args: object, **kwargs: object) -> None:
            pass

        def __enter__(self) -> object:
            return self

        def __exit__(self, *args: object) -> None:
            return None

        def get(self, url: str) -> FakeResponse:
            urls.append(url)
            return FakeResponse()

    monkeypatch.setattr(stooq.httpx, "Client", FakeClient)

    stooq.fetch_eod("BRK.B")
    stooq.fetch_eod("SAP.DE", "XETR")
    stooq.fetch_eod("SAN.MC", "XMAD")

    assert "/chart/BRK-B?" in urls[0]
    assert "/chart/SAP.DE?" in urls[1]
    assert "/chart/SAN.MC?" in urls[2]


def test_european_markets_have_no_stooq_fallback(monkeypatch: pytest.MonkeyPatch) -> None:
    def failed_yahoo(symbol: str, market: str = "US") -> EodResult:
        raise ValueError("unusable chart response")

    def stooq_must_not_run(symbol: str) -> list[Bar]:
        raise AssertionError("Stooq is US-only")

    monkeypatch.setattr(stooq, "_fetch_yahoo", failed_yahoo)
    monkeypatch.setattr(stooq, "_fetch_stooq", stooq_must_not_run)

    with pytest.raises(ValueError):
        stooq.fetch_eod("SAP.DE", "XETR")


def test_eod_endpoint_passes_the_market_and_returns_currency(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    sample = [Bar(date=date(2026, 10, 23), open=1.0, high=2.0, low=0.5, close=1.5, volume=10)]
    seen: dict[str, str] = {}

    def fake(symbol: str, market: str = "US") -> EodResult:
        seen.update(symbol=symbol, market=market)
        return EodResult(bars=sample, currency="EUR")

    monkeypatch.setattr(main_module, "fetch_eod", fake)

    response = client.get("/eod/sap.de?market=XETR")

    assert response.status_code == 200
    assert response.json()["currency"] == "EUR"
    assert response.json()["symbol"] == "SAP.DE"
    assert seen == {"symbol": "SAP.DE", "market": "XETR"}


def test_eod_endpoint_defaults_to_the_us_market(monkeypatch: pytest.MonkeyPatch) -> None:
    seen: dict[str, str] = {}

    def fake(symbol: str, market: str = "US") -> EodResult:
        seen["market"] = market
        return EodResult(bars=[Bar(date=date(2026, 9, 25), open=1, high=2, low=0.5, close=1.5, volume=1)])

    monkeypatch.setattr(main_module, "fetch_eod", fake)

    assert client.get("/eod/BRK.B").status_code == 200
    assert seen["market"] == "US"


@pytest.mark.parametrize(
    "path",
    [
        "/eod/SAN.MC?market=XETR",
        "/eod/SAP.DE?market=XMAD",
        "/eod/SAN.MC",
        "/eod/SAP.DE?market=US",
        "/eod/SAN?market=XMAD",
        "/eod/SAN.MC?market=NOPE",
    ],
)
def test_eod_endpoint_rejects_a_symbol_that_does_not_match_the_market(
    monkeypatch: pytest.MonkeyPatch, path: str
) -> None:
    def must_not_fetch(symbol: str, market: str = "US") -> EodResult:
        raise AssertionError("no upstream call for an invalid symbol/market")

    monkeypatch.setattr(main_module, "fetch_eod", must_not_fetch)

    assert client.get(path).status_code == 422


# --- IBEX 35 (docs/specs/multi-market-universes.md, Feature 5) ---------------------

SAN_FIXTURE = Path(__file__).parent / "fixtures" / "yahoo_san_mc.json"


def test_yahoo_san_mc_recording_has_madrid_session_dates_and_skips_bme_closures() -> None:
    # Trimmed real Yahoo response for SAN.MC (recorded 2026-10-26) around Easter 2026:
    # Good Friday (2026-04-03) and Easter Monday (2026-04-06) are BME non-trading days.
    result = parse_yahoo_eod(SAN_FIXTURE.read_text(encoding="utf-8"), "XMAD")

    assert [bar.date for bar in result.bars] == [
        date(2026, 3, 30),
        date(2026, 3, 31),
        date(2026, 4, 1),
        date(2026, 4, 2),
        date(2026, 4, 7),
        date(2026, 4, 8),
    ]
    assert all(bar.date.weekday() < 5 for bar in result.bars)
    assert result.currency == "EUR"
