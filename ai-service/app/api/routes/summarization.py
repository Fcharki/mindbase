from fastapi import APIRouter
from pydantic import BaseModel

from app.services.summarizer import summary_service


router = APIRouter()


class SummarizeRequest(BaseModel):
    prompt: str

    # Lets a single request pick a model without changing the service's
    # configured default, so a caller can regenerate one entry with the better
    # backend while bulk generation stays on the fast one.
    backend: str | None = None


class SummarizeResponse(BaseModel):
    response: str

    # Which model actually answered, which is not necessarily the one asked
    # for: an unknown name falls back to the configured default.
    backend: str


@router.post("/summarize", response_model=SummarizeResponse)
def chat(request: SummarizeRequest):
    response = summary_service.generate(request.prompt, backend=request.backend)

    return {
        "response": response,
        "backend": summary_service.resolve_backend(request.backend),
    }
