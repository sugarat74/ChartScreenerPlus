"""FastAPI application for the Chartiko engine."""

from typing import Literal

import httpx
from fastapi import FastAPI, HTTPException

from app import __version__
from app.indicators.snapshots import compute_snapshots
from app.models import (
    EodResponse,
    IndicatorsComputeRequest,
    IndicatorsComputeResponse,
    PatternParametersResponse,
    PatternsDetectRequest,
    PatternsDetectResponse,
    PatternsNearMissesResponse,
    SignalsDetectRequest,
    SignalsDetectResponse,
)
from app.patterns.detect import detect_patterns
from app.patterns.near_misses import detect_near_misses
from app.patterns.rules import DEFAULT_PARAMS
from app.signals.detect import detect_signals
from app.sources.stooq import fetch_eod, symbol_matches_market


def create_app() -> FastAPI:
    application = FastAPI(title="Chartiko Engine", version=__version__)

    @application.get("/health")
    def health() -> dict[str, str]:
        return {
            "status": "ok",
            "service": "alphapulse-engine",
            "version": __version__,
        }

    @application.get("/eod/{symbol}", response_model=EodResponse)
    def eod(symbol: str, market: Literal["US", "XMAD", "XETR"] = "US") -> EodResponse:
        """Return parsed EOD bars for one symbol (fetch + parse only).

        market is the Listing Market (default US, so existing calls are
        unchanged). The symbol must follow the market's suffix rule (422).
        """

        normalized = symbol.strip().upper()
        if not normalized:
            raise HTTPException(status_code=404, detail="No EOD data for the requested symbol.")

        if not symbol_matches_market(normalized, market):
            raise HTTPException(
                status_code=422,
                detail=f"Symbol {normalized} does not belong to listing market {market}.",
            )

        try:
            result = fetch_eod(normalized, market)
        except ValueError as exc:
            raise HTTPException(
                status_code=502,
                detail=f"Upstream EOD source returned an unusable response for {normalized}.",
            ) from exc
        except httpx.HTTPError as exc:
            raise HTTPException(
                status_code=502,
                detail=f"Upstream EOD source failed for {normalized}.",
            ) from exc

        if not result.bars:
            raise HTTPException(status_code=404, detail=f"No EOD data for {normalized}.")

        return EodResponse(symbol=normalized, bars=result.bars, currency=result.currency)

    @application.post("/indicators/compute", response_model=IndicatorsComputeResponse)
    def indicators_compute(
        request: IndicatorsComputeRequest,
    ) -> IndicatorsComputeResponse:
        """Compute one indicator snapshot per supplied bar (pure, stateless)."""

        return IndicatorsComputeResponse(snapshots=compute_snapshots(request.bars))

    @application.post("/signals/detect", response_model=SignalsDetectResponse)
    def signals_detect(request: SignalsDetectRequest) -> SignalsDetectResponse:
        """Detect the deterministic signals that hold on the as-of bar (pure)."""

        return SignalsDetectResponse(signals=detect_signals(request.bars))

    @application.post("/patterns/detect", response_model=PatternsDetectResponse)
    def patterns_detect(request: PatternsDetectRequest) -> PatternsDetectResponse:
        """Detect the chartist patterns active on the as-of bar (pure)."""

        return PatternsDetectResponse(patterns=detect_patterns(request.bars))

    @application.post("/patterns/near-misses", response_model=PatternsNearMissesResponse)
    def patterns_near_misses(request: PatternsDetectRequest) -> PatternsNearMissesResponse:
        """Calibration aid: instances rejected by exactly one rule (pure, never persisted)."""

        return PatternsNearMissesResponse(near_misses=detect_near_misses(request.bars))

    @application.get("/patterns/parameters", response_model=PatternParametersResponse)
    def patterns_parameters() -> PatternParametersResponse:
        """The production pattern parameters (booleans as 1/0), for calibration reports."""

        return PatternParametersResponse(
            parameters={name: float(value) for name, value in DEFAULT_PARAMS.as_dict().items()}
        )

    return application


app = create_app()
