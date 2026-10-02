from fastapi import APIRouter
from pydantic import BaseModel

from app.services.embeddings import EMBEDDING_DIMENSION, embedding_service


router = APIRouter()


class EmbedRequest(BaseModel):
    text: str


class EmbedResponse(BaseModel):
    embedding: list[float]
    dimension: int


class EmbedBatchRequest(BaseModel):
    texts: list[str]


class EmbedBatchResponse(BaseModel):
    embeddings: list[list[float]]
    dimension: int


@router.post("/embed", response_model=EmbedResponse)
def embed(request: EmbedRequest):
    vector = embedding_service.encode(request.text)

    return {
        "embedding": vector,
        "dimension": len(vector) if vector else EMBEDDING_DIMENSION,
    }


@router.post("/embed/batch", response_model=EmbedBatchResponse)
def embed_batch(request: EmbedBatchRequest):
    # Indexed in one call rather than one call per chunk: the model is loaded
    # once either way, but this saves a round trip and a tokenizer pass setup
    # per chunk.
    vectors = embedding_service.encode_many(request.texts)

    return {
        "embeddings": vectors,
        "dimension": EMBEDDING_DIMENSION,
    }
