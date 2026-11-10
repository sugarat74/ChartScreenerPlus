"""Conservative Head-and-Shoulders and triangle geometry detectors."""

from app.models import Bar, Pattern, PatternPoint
from app.patterns.rules import DEFAULT_PARAMS, Detector, _pattern
from app.patterns.swings import swing_highs, swing_lows

# Named conservative limits; thresholds are intentionally geometry-only.
HS_MAX_AGE = 100
HS_MIN_SPACING = 5
HS_MAX_SPACING = 35
HS_SHOULDER_TOLERANCE = 0.08
HS_HEAD_CLEARANCE = 0.04
TRIANGLE_MIN_AGE = 20
TRIANGLE_MAX_AGE = 100
TRIANGLE_SLOPE_TOLERANCE = 0.03
TRIANGLE_CONVERGENCE = 0.80
BREAKOUT_SESSIONS = 10


def _line(a: int, av: float, b: int, bv: float, x: int) -> float:
    return av + (bv - av) * (x - a) / (b - a)


def _make(bars: list[Bar], *, typ: str, direction: str | None, status: str, start: int,
          end: int, level: float | None, points: list[tuple[int, float, str]], metadata: dict[str, float]) -> Pattern:
    return Pattern(type=typ, direction=direction, status=status, as_of_date=bars[-1].date,
                   start_date=bars[start].date, end_date=bars[end].date, breakout_level=level,
                   points=[PatternPoint(date=bars[j].date, price=price, role=role) for j, price, role in points],
                   metadata=metadata)


def _head_shoulders(bars: list[Bar], highs_side: bool, start: int) -> Pattern | None:
    pivots = [j for j in (swing_highs(bars) if highs_side else swing_lows(bars)) if j >= start]
    i = len(bars) - 1
    for k in range(len(pivots) - 3, -1, -1):
        a, h, c = pivots[k:k + 3]
        if i - c > HS_MAX_AGE or not HS_MIN_SPACING <= h-a <= HS_MAX_SPACING or not HS_MIN_SPACING <= c-h <= HS_MAX_SPACING:
            continue
        values = [float(bars[x].high if highs_side else bars[x].low) for x in (a, h, c)]
        shoulders = max(values[0], values[2])
        if shoulders <= 0 or abs(values[0]-values[2])/shoulders > HS_SHOULDER_TOLERANCE:
            continue
        if (values[1] <= shoulders*(1+HS_HEAD_CLEARANCE)) if highs_side else (values[1] >= shoulders*(1-HS_HEAD_CLEARANCE)):
            continue
        between1 = min(range(a+1, h), key=lambda x: bars[x].low) if highs_side else max(range(a+1, h), key=lambda x: bars[x].high)
        between2 = min(range(h+1, c), key=lambda x: bars[x].low) if highs_side else max(range(h+1, c), key=lambda x: bars[x].high)
        p1 = float(bars[between1].low if highs_side else bars[between1].high)
        p2 = float(bars[between2].low if highs_side else bars[between2].high)
        boundary = _line(between1, p1, between2, p2, i)
        close = float(bars[i].close)
        direction = "bearish" if highs_side else "bullish"
        broken = close < boundary if highs_side else close > boundary
        age = next((n for n in range(i, max(c, i-BREAKOUT_SESSIONS)-1, -1)
                    if (bars[n].close < _line(between1,p1,between2,p2,n) if highs_side else bars[n].close > _line(between1,p1,between2,p2,n))), None)
        if broken and age is None:
            age = i
        confirmed = age is not None and i-age < BREAKOUT_SESSIONS
        if not confirmed and (close < boundary if highs_side else close > boundary):
            continue
        return _make(bars, typ="head_and_shoulders" if highs_side else "inverse_head_and_shoulders",
                     direction=direction, status="confirmed" if confirmed else "forming", start=a, end=i,
                     level=boundary if confirmed else None,
                     points=[(a,values[0],"left_shoulder"),(h,values[1],"head"),(c,values[2],"right_shoulder"),
                             (between1,p1,"neckline_start"),(between2,p2,"neckline_end")],
                     metadata={"neckline_slope": (p2-p1)/(between2-between1)})
    return None


def _triangle(bars: list[Bar], start: int, typ: str) -> Pattern | None:
    i = len(bars)-1
    highs = [j for j in swing_highs(bars) if j >= start]
    lows = [j for j in swing_lows(bars) if j >= start]
    # Use the two most recent highs and lows; stable geometry type independent of breakout.
    if len(highs) < 2 or len(lows) < 2:
        return None
    h1,h2=highs[-2:]; l1,l2=lows[-2:]
    first=min(h1,l1); last=max(h2,l2)
    duration=last-first
    if not TRIANGLE_MIN_AGE <= duration <= TRIANGLE_MAX_AGE or i-last > 30:
        return None
    hv1,hv2=float(bars[h1].high),float(bars[h2].high)
    lv1,lv2=float(bars[l1].low),float(bars[l2].low)
    hs=(hv2-hv1)/(h2-h1); ls=(lv2-lv1)/(l2-l1)
    span=max(hv1-lv1,hv2-lv2)
    if span <= 0 or _line(h1,hv1,h2,hv2,i) <= _line(l1,lv1,l2,lv2,i): return None
    narrowing=(_line(h1,hv1,h2,hv2,last)-_line(l1,lv1,l2,lv2,last))/span
    if narrowing > TRIANGLE_CONVERGENCE: return None
    flat=TRIANGLE_SLOPE_TOLERANCE*max(abs(hv1),abs(lv1),1)
    if typ == "ascending_triangle" and (abs(hs)>flat or ls <= 0): return None
    if typ == "descending_triangle" and (abs(ls)>flat or hs >= 0): return None
    if typ == "symmetrical_triangle" and not (hs < 0 and ls > 0): return None
    breakout: tuple[int, str, float] | None = None
    for n in range(last, i + 1):
        up = _line(h1, hv1, h2, hv2, n)
        lo = _line(l1, lv1, l2, lv2, n)
        if bars[n].close > up:
            breakout = (n, "bullish", up)
            break
        if bars[n].close < lo:
            breakout = (n, "bearish", lo)
            break
    confirmed = breakout is not None and i - breakout[0] < BREAKOUT_SESSIONS
    if breakout is not None:
        breakout_i, direction, level = breakout
        invalidated = any(
            bars[n].close < _line(l1, lv1, l2, lv2, n) if direction == "bullish"
            else bars[n].close > _line(h1, hv1, h2, hv2, n)
            for n in range(breakout_i + 1, i + 1)
        )
        if invalidated or not confirmed:
            return None
    else:
        direction = None
        level = None
    return _make(bars,typ=typ,direction=direction if confirmed else None,status="confirmed" if confirmed else "forming",
        start=first,end=i,level=level if confirmed else None,
        points=[(h1,hv1,"upper_start"),(h2,hv2,"upper_end"),(l1,lv1,"lower_start"),(l2,lv2,"lower_end")],
        metadata={"upper_slope":hs,"lower_slope":ls})


def head_and_shoulders(bars, _rvol, start, _params=DEFAULT_PARAMS): return _head_shoulders(bars, True, start)
def inverse_head_and_shoulders(bars, _rvol, start, _params=DEFAULT_PARAMS): return _head_shoulders(bars, False, start)
def ascending_triangle(bars, _rvol, start, _params=DEFAULT_PARAMS): return _triangle(bars, start, "ascending_triangle")
def descending_triangle(bars, _rvol, start, _params=DEFAULT_PARAMS): return _triangle(bars, start, "descending_triangle")
def symmetrical_triangle(bars, _rvol, start, _params=DEFAULT_PARAMS): return _triangle(bars, start, "symmetrical_triangle")

DETECTORS: tuple[tuple[str, Detector], ...] = (
    ("head_and_shoulders", head_and_shoulders), ("inverse_head_and_shoulders", inverse_head_and_shoulders),
    ("ascending_triangle", ascending_triangle), ("descending_triangle", descending_triangle),
    ("symmetrical_triangle", symmetrical_triangle),
)
