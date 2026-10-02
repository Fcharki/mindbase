from fastapi import FastAPI

from app.api.routes.embeddings import router as embeddings_router
from app.api.routes.health import router as health_router
from app.api.routes.summarization import router as summarization_router
from app.api.routes.rag import router as rag_router


app = FastAPI()

app.include_router(health_router)
app.include_router(summarization_router)
app.include_router(embeddings_router)
app.include_router(rag_router)