"""FastAPI application for the AlphaPulse engine."""

import httpx
from fastapi import FastAPI, HTTPException

from app import __version__
from app.models import EodResponse
from app.sources.stooq import fetch_eod


def create_app() -> FastAPI:
    application = FastAPI(title="AlphaPulse Engine", version=__version__)

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

    return application


app = create_app()
