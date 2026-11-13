"""London pence handling, real source fixtures and price-scale behavior; offline."""

import json
from datetime import date, datetime
from pathlib import Path
from zoneinfo import ZoneInfo

import pytest
from fastapi.testclient import TestClient

from app import main as main_module
from app.indicators.snapshots import compute_snapshots
from app.sources import stooq
from app.sources.stooq import parse_yahoo_eod, symbol_matches_market, yahoo_symbol

FIXTURES = Path(__file__).parent / "fixtures"


def azn_payload():
    return json.loads((FIXTURES / "yahoo_azn_l_gbp.json").read_text(encoding="utf-8"))


def with_closes(closes, currency="GBp"):
    payload = azn_payload()
    chart = payload["chart"]["result"][0]
    chart["meta"]["currency"] = currency
    chart["timestamp"] = chart["timestamp"][:len(closes)]
    chart["indicators"]["quote"][0] = {
        "open": closes, "high": closes, "low": closes, "close": closes,
        "volume": [1000] * len(closes),
    }
    return json.dumps(payload)


@pytest.mark.parametrize("raw,expected", [("GBp", "GBX"), (" GBp ", "GBX"), ("GBX", "GBX"), ("GBP", "GBP"), ("gbp", "GBP"), ("usd", "USD"), ("EUR", "EUR")])
def test_real_azn_currency_normalization_preserves_prices(raw, expected):
    payload = azn_payload()
    chart = payload["chart"]["result"][0]
    chart["meta"]["currency"] = raw
    result = parse_yahoo_eod(json.dumps(payload), "XLON")
    assert result.currency == expected
    assert len(result.bars) == 505
    assert [bar.close for bar in result.bars] == chart["indicators"]["quote"][0]["close"]
    assert result.bars[-1].date == date(2026, 11, 9)


@pytest.mark.parametrize("closes", [[100, 4000], [4000, 100], [100, 10000], [10000, 100]])
def test_scale_jump_at_or_above_forty_is_rejected_in_both_directions(closes):
    with pytest.raises(ValueError, match="scale change"):
        parse_yahoo_eod(with_closes(closes), "XLON")


@pytest.mark.parametrize("closes", [[100, 3999.9], [3999.9, 100], [999999, 1000000]])
def test_below_ratio_threshold_and_exact_cap_are_accepted(closes):
    assert [bar.close for bar in parse_yahoo_eod(with_closes(closes), "XLON").bars] == closes


@pytest.mark.parametrize("closes", [[1000000, 1000000.01], [0, 10], [-1, 10], [float("inf"), 10], [float("nan"), 10]])
def test_pence_cap_and_unusable_scale_inputs_fail_closed(closes):
    with pytest.raises(ValueError):
        parse_yahoo_eod(with_closes(closes), "XLON")


def test_london_guard_does_not_change_other_markets_or_rescale_pounds():
    assert len(parse_yahoo_eod(with_closes([100, 10000], "USD"), "US").bars) == 2
    result = parse_yahoo_eod(with_closes([1000001, 1000002], "GBP"), "XLON")
    assert result.currency == "GBP"
    assert result.bars[0].close == 1000001


def test_real_dates_respect_london_and_paris_distinct_bank_holidays():
    london = parse_yahoo_eod(json.dumps(azn_payload()), "XLON")
    paris = parse_yahoo_eod((FIXTURES / "yahoo_san_pa_uk_holidays.json").read_text(encoding="utf-8"), "XPAR")
    london_days = {bar.date for bar in london.bars}
    paris_days = {bar.date for bar in paris.bars}
    for day in (date(2026, 5, 4), date(2026, 5, 25), date(2026, 8, 31)):
        assert day not in london_days
        assert day in paris_days
    assert all(day.weekday() < 5 for day in london_days)


def test_missing_source_timezone_uses_london_session_day_during_bst():
    payload = json.loads(with_closes([100]))
    chart = payload["chart"]["result"][0]
    chart["meta"].pop("exchangeTimezoneName")
    chart["timestamp"] = [int(datetime(2026, 7, 13, tzinfo=ZoneInfo("Europe/London")).timestamp())]
    assert parse_yahoo_eod(json.dumps(payload), "XLON").bars[0].date == date(2026, 7, 13)


def test_hyphenated_bt_symbol_and_api_identity_are_preserved(monkeypatch):
    result = parse_yahoo_eod((FIXTURES / "yahoo_bt_a_l_gbp.json").read_text(encoding="utf-8"), "XLON")
    seen = []
    monkeypatch.setattr(main_module, "fetch_eod", lambda symbol, market: seen.append((symbol, market)) or result)
    response = TestClient(main_module.app).get("/eod/bt-a.l?market=XLON")
    assert response.status_code == 200
    assert response.json()["symbol"] == "BT-A.L"
    assert response.json()["currency"] == "GBX"
    assert seen == [("BT-A.L", "XLON")]
    assert yahoo_symbol("BT-A.L", "XLON") == "BT-A.L"


def test_cross_market_suffixes_fail_before_fetch(monkeypatch):
    monkeypatch.setattr(main_module, "fetch_eod", lambda *args: pytest.fail("Cross-market fetch"))
    for path in ["/eod/AZN.L", "/eod/AZN.L?market=XPAR", "/eod/AZN.L?market=XMAD", "/eod/SAN.PA?market=XLON", "/eod/AZN?market=XLON"]:
        assert TestClient(main_module.app).get(path).status_code == 422
    assert symbol_matches_market("AZN.L", "XLON")
    assert not symbol_matches_market("AZN.L", "US")
    assert not symbol_matches_market("AZN.L", "XPAR")


def test_scale_error_is_controlled_502_and_never_uses_stooq(monkeypatch):
    def bad_yahoo(*args):
        return parse_yahoo_eod(with_closes([100, 10000]), "XLON")
    monkeypatch.setattr(stooq, "_fetch_yahoo", bad_yahoo)
    monkeypatch.setattr(stooq, "_fetch_stooq", lambda *args: pytest.fail("London Stooq fallback"))
    response = TestClient(main_module.app).get("/eod/AZN.L?market=XLON")
    assert response.status_code == 502
    assert "unusable response" in response.json()["detail"]


def test_indicators_have_the_correct_scale_on_all_real_azn_bars():
    pence = parse_yahoo_eod(json.dumps(azn_payload()), "XLON").bars
    pounds = [bar.model_copy(update={key: getattr(bar, key) / 100 for key in ("open", "high", "low", "close")}) for bar in pence]
    price_fields = {"sma20", "sma50", "sma200", "ema21", "ema55", "macd", "macd_signal", "macd_hist", "bb_upper", "bb_middle", "bb_lower", "atr14", "supertrend"}
    for pence_snapshot, pounds_snapshot in zip(compute_snapshots(pence), compute_snapshots(pounds)):
        scaled = pounds_snapshot.model_dump()
        for key, value in pence_snapshot.model_dump().items():
            other = scaled[key]
            if value is None or key == "date":
                assert value == other
            else:
                assert value == pytest.approx(other * (100 if key in price_fields else 1), rel=1e-7, abs=1e-7), key


def test_every_ftse100_member_is_an_xlon_symbol_sent_to_yahoo_verbatim():
    """universe-ftse100: the committed snapshot passes the engine's suffix rule."""
    import csv

    dataset = Path(__file__).resolve().parents[2] / "database" / "data" / "ftse100.csv"
    with dataset.open(encoding="utf-8", newline="") as handle:
        tickers = [row["ticker"] for row in csv.DictReader(handle)]

    assert len(tickers) == 97
    assert "BT-A.L" in tickers
    for excluded in ("CPG.L", "IHG.L", "MTLN.L"):
        assert excluded not in tickers
    for ticker in tickers:
        assert symbol_matches_market(ticker, "XLON"), ticker
        assert yahoo_symbol(ticker, "XLON") == ticker
        for other in ("US", "XMAD", "XETR", "XPAR"):
            assert not symbol_matches_market(ticker, other), (ticker, other)
