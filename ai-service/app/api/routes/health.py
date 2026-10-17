from fastapi import APIRouter
from pydantic import BaseModel

from app.services.embeddings import embedding_service
from app.services.llm import MAX_NEW_TOKENS, llm_service
from app.services.rerank import RERANK_MODEL, rerank_service
from app.services.summarizer import summary_service


router = APIRouter()


class HealthResponse(BaseModel):
    status: str

    # Which backend /summarize will use when a caller does not name one.
    summarization_backend: str

    # Which engine answers /rag: 'hf' (bundled model) or 'ollama' (a server).
    llm_provider: str

    # Whether each model is resident. They all load lazily on first use, so a
    # false here usually means "not called yet" rather than "broken".
    summarizer_loaded: bool
    embedder_loaded: bool
    llm_loaded: bool
    rerank_loaded: bool

    # The instruction backend is only reachable from the 'qwen' summarization
    # backend, so its cost is reported wherever the cost of summarizing is.
    llm_max_new_tokens: int
    llm_known_slow: bool

    # The rerank model is fetched from the Hub, so it can be unavailable in an
    # offline deployment; that is worth surfacing next to the load flag.
    rerank_model: str


@router.get("/health", response_model=HealthResponse)
def health():
    return {
        "status": "ok",
        "summarization_backend": summary_service.backend,
        "llm_provider": llm_service.provider(),
        "summarizer_loaded": summary_service.is_loaded(),
        "embedder_loaded": embedding_service.is_loaded(),
        "llm_loaded": llm_service.is_loaded(),
        "rerank_loaded": rerank_service.is_loaded(),
        "llm_max_new_tokens": MAX_NEW_TOKENS,
        "llm_known_slow": True,
        "rerank_model": RERANK_MODEL,
    }
