"""FastAPI application for the AlphaPulse engine."""

from fastapi import FastAPI

from app import __version__


def create_app() -> FastAPI:
    application = FastAPI(title="AlphaPulse Engine", version=__version__)

    @application.get("/health")
    def health() -> dict[str, str]:
        return {
            "status": "ok",
            "service": "alphapulse-engine",
            "version": __version__,
        }

    return application


app = create_app()
