from fastapi import APIRouter
from pydantic import BaseModel

from app.services.rerank import rerank_service


router = APIRouter()


class RerankRequest(BaseModel):
    query: str
    passages: list[str]


class RerankResponse(BaseModel):
    # Passage indexes into the request's `passages`, most relevant first. The
    # caller zips these back onto its own result objects.
    order: list[int]


@router.post("/rerank", response_model=RerankResponse)
def rerank(request: RerankRequest):
    return {"order": rerank_service.rerank(request.query, request.passages)}
