from datetime import date, timedelta

import pytest

from app.models import Bar
from app.patterns import head_shoulders_triangles as geometry
from app.patterns.detect import detect_patterns


def _bars(highs: dict[int, float], lows: dict[int, float], close: float = 90.0) -> list[Bar]:
    return [Bar(date=date(2026, 1, 1) + timedelta(days=i), open=close,
                high=highs.get(i, close + 1), low=lows.get(i, close - 1), close=close, volume=1000)
            for i in range(80)]


def _head_shoulders_bars(inverse: bool) -> list[Bar]:
    if inverse:
        return _bars({30: 110, 50: 115}, {20: 100, 40: 80, 60: 102})
    return _bars({20: 100, 40: 120, 60: 102}, {30: 92, 50: 88})


def _stub_head_shoulders_swings(monkeypatch, inverse: bool) -> None:
    monkeypatch.setattr(geometry, 'swing_highs', lambda _: [30, 50] if inverse else [20, 40, 60])
    monkeypatch.setattr(geometry, 'swing_lows', lambda _: [20, 40, 60] if inverse else [30, 50])


def test_head_and_shoulders_exposes_fixed_direction_and_sloped_neckline(monkeypatch):
    bars = _bars({20: 100, 40: 120, 60: 102}, {30: 92, 50: 88})
    monkeypatch.setattr(geometry, 'swing_highs', lambda _: [20, 40, 60])
    monkeypatch.setattr(geometry, 'swing_lows', lambda _: [30, 50])
    pattern = geometry.head_and_shoulders(bars, [], 0)
    assert pattern is not None
    assert pattern.type == 'head_and_shoulders' and pattern.direction == 'bearish'
    assert pattern.status == 'forming' and pattern.breakout_level is None
    assert {point.role for point in pattern.points} == {'left_shoulder', 'head', 'right_shoulder', 'neckline_start', 'neckline_end'}


def test_forming_triangle_is_direction_neutral_and_keeps_boundary_roles(monkeypatch):
    bars = _bars({20: 100, 40: 100}, {25: 70, 45: 78})
    bars = bars[:70]
    monkeypatch.setattr(geometry, 'swing_highs', lambda _: [20, 40])
    monkeypatch.setattr(geometry, 'swing_lows', lambda _: [25, 45])
    pattern = geometry.ascending_triangle(bars, [], 0)
    assert pattern is not None
    assert pattern.type == 'ascending_triangle' and pattern.status == 'forming'
    assert pattern.direction is None and pattern.breakout_level is None
    assert {point.role for point in pattern.points} == {'upper_start', 'upper_end', 'lower_start', 'lower_end'}


def test_triangle_breakout_direction_is_set_only_after_close_through_boundary(monkeypatch):
    bars = _bars({50: 100, 65: 100}, {55: 75, 70: 85}, close=95)
    bars[-1].close = 101
    monkeypatch.setattr(geometry, 'swing_highs', lambda _: [50, 65])
    monkeypatch.setattr(geometry, 'swing_lows', lambda _: [55, 70])
    pattern = geometry.ascending_triangle(bars, [], 0)
    assert pattern is not None
    assert pattern.status == 'confirmed' and pattern.direction == 'bullish'
    assert pattern.breakout_level == 100


def test_inverse_head_and_shoulders_keeps_its_bullish_orientation(monkeypatch):
    bars = _bars({30: 110, 50: 115}, {20: 100, 40: 80, 60: 102})
    monkeypatch.setattr(geometry, 'swing_highs', lambda _: [30, 50])
    monkeypatch.setattr(geometry, 'swing_lows', lambda _: [20, 40, 60])
    pattern = geometry.inverse_head_and_shoulders(bars, [], 0)
    assert pattern is not None and pattern.type == 'inverse_head_and_shoulders'
    assert pattern.direction == 'bullish' and pattern.status == 'forming'


def test_descending_triangle_confirms_bearish_at_lower_boundary(monkeypatch):
    bars = _bars({40: 100, 60: 90}, {45: 70, 65: 70})
    for bar in bars:
        bar.close = 80
    bars[-1].close = 60
    monkeypatch.setattr(geometry, 'swing_highs', lambda _: [40, 60])
    monkeypatch.setattr(geometry, 'swing_lows', lambda _: [45, 65])
    pattern = geometry.descending_triangle(bars, [], 0)
    assert pattern is not None and pattern.type == 'descending_triangle'
    assert pattern.status == 'confirmed' and pattern.direction == 'bearish'
    assert pattern.breakout_level == 70


def test_symmetrical_triangle_type_stays_stable_for_either_breakout(monkeypatch):
    bars = _bars({40: 110, 60: 100}, {45: 70, 65: 80}, close=90)
    monkeypatch.setattr(geometry, 'swing_highs', lambda _: [40, 60])
    monkeypatch.setattr(geometry, 'swing_lows', lambda _: [45, 65])
    forming = geometry.symmetrical_triangle(bars, [], 0)
    assert forming is not None and forming.type == 'symmetrical_triangle'
    assert forming.status == 'forming' and forming.direction is None
    bars[-1].close = 100
    bullish = geometry.symmetrical_triangle(bars, [], 0)
    assert bullish is not None and bullish.type == 'symmetrical_triangle' and bullish.direction == 'bullish'
    bars[-1].close = 70
    bearish = geometry.symmetrical_triangle(bars, [], 0)
    assert bearish is not None and bearish.type == 'symmetrical_triangle' and bearish.direction == 'bearish'


def test_triangle_breakout_is_removed_when_price_breaks_the_opposite_boundary(monkeypatch):
    bars = _bars({40: 110, 60: 100}, {45: 70, 65: 80}, close=90)
    bars[67].close = 100
    bars[69].close = 70
    monkeypatch.setattr(geometry, 'swing_highs', lambda _: [40, 60])
    monkeypatch.setattr(geometry, 'swing_lows', lambda _: [45, 65])
    assert geometry.symmetrical_triangle(bars, [], 0) is None


@pytest.mark.parametrize('inverse', [False, True], ids=['head-and-shoulders', 'inverse-head-and-shoulders'])
def test_head_shoulders_confirmation_window_starts_once_and_expires(monkeypatch, inverse):
    bars = _head_shoulders_bars(inverse)
    _stub_head_shoulders_swings(monkeypatch, inverse)
    detector = geometry.inverse_head_and_shoulders if inverse else geometry.head_and_shoulders
    bars[61].close = 118 if inverse else 85

    active = detector(bars[:70], [], 0)  # breakout at 61, as-of 69: age 8
    assert active is not None and active.status == 'confirmed'
    assert active.direction == ('bullish' if inverse else 'bearish')
    assert active.breakout_level is not None

    expired = detector(bars[:72], [], 0)  # same first breakout, as-of 71: age 10
    assert expired is None


@pytest.mark.parametrize('inverse', [False, True], ids=['head-and-shoulders', 'inverse-head-and-shoulders'])
def test_head_shoulders_is_removed_after_opposite_extreme_invalidation(monkeypatch, inverse):
    bars = _head_shoulders_bars(inverse)
    _stub_head_shoulders_swings(monkeypatch, inverse)
    detector = geometry.inverse_head_and_shoulders if inverse else geometry.head_and_shoulders
    bars[61].close = 118 if inverse else 85
    bars[62].close = 79 if inverse else 121

    assert detector(bars[:64], [], 0) is None


@pytest.mark.parametrize('bad_shoulders,bad_head', [(88, 100), (100, 103)], ids=['shoulder-mismatch', 'shallow-head'])
def test_head_shoulders_near_misses_are_rejected(monkeypatch, bad_shoulders, bad_head):
    bars = _bars({20: 100, 40: bad_head, 60: bad_shoulders}, {30: 92, 50: 88})
    _stub_head_shoulders_swings(monkeypatch, inverse=False)
    assert geometry.head_and_shoulders(bars, [], 0) is None


def test_triangle_near_miss_with_nonflat_upper_boundary_is_rejected(monkeypatch):
    bars = _bars({40: 100, 60: 104}, {45: 70, 65: 82})
    monkeypatch.setattr(geometry, 'swing_highs', lambda _: [40, 60])
    monkeypatch.setattr(geometry, 'swing_lows', lambda _: [45, 65])
    assert geometry.ascending_triangle(bars, [], 0) is None


def test_triangle_confirmation_is_not_renewed_by_repeated_breakout_closes(monkeypatch):
    bars = _bars({40: 110, 60: 100}, {45: 70, 65: 80}, close=90)
    for index in range(67, 78):
        bars[index].close = 100
    monkeypatch.setattr(geometry, 'swing_highs', lambda _: [40, 60])
    monkeypatch.setattr(geometry, 'swing_lows', lambda _: [45, 65])
    assert geometry.symmetrical_triangle(bars, [], 0) is None


def test_triangle_forming_geometry_expires_after_its_last_swing():
    bars = _bars({40: 110, 60: 100}, {45: 70, 65: 80}, close=90)
    bars.extend(
        Bar(date=bars[-1].date + timedelta(days=offset), open=90, high=91, low=89, close=90, volume=1000)
        for offset in range(1, 19)
    )
    assert len(bars) == 98  # last swing is 32 sessions before the as-of bar
    assert geometry.symmetrical_triangle(bars, [], 0) is None


def test_pattern_engine_rejects_insufficient_history():
    assert detect_patterns(_bars({}, {})[:59]) == []
