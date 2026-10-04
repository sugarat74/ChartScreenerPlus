"""FastAPI application for the Chartiko engine."""

import httpx
from fastapi import FastAPI, HTTPException

from app import __version__
from app.indicators.snapshots import compute_snapshots
from app.models import (
    EodResponse,
    IndicatorsComputeRequest,
    IndicatorsComputeResponse,
    SignalsDetectRequest,
    SignalsDetectResponse,
)
from app.signals.detect import detect_signals
from app.sources.stooq import fetch_eod


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
    def eod(symbol: str) -> EodResponse:
        """Return parsed EOD bars for one symbol (fetch + parse only)."""

        normalized = symbol.strip().upper()
        if not normalized:
            raise HTTPException(status_code=404, detail="No EOD data for the requested symbol.")

        try:
            bars = fetch_eod(normalized)
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

        if not bars:
            raise HTTPException(status_code=404, detail=f"No EOD data for {normalized}.")

        return EodResponse(symbol=normalized, bars=bars)

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

    return application


app = create_app()
