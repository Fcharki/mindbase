import os

import torch
from fastapi import FastAPI

from app.api.routes.embeddings import router as embeddings_router
from app.api.routes.health import router as health_router
from app.api.routes.rerank import router as rerank_router
from app.api.routes.summarization import router as summarization_router
from app.api.routes.rag import router as rag_router

# Pinned before any model is built, because torch reads this once at import.
#
# Left to itself torch sizes threads from the host's core count, which over-
# subscribes inside a container. Measured here, encoding one sentence took 13s
# on a single thread against 1.1s on two, so the default cost every search
# about twelve seconds. Clamped to the CPU quota rather than the visible core
# count, since a limited container can see cores it may not use.
try:
    quota = len(os.sched_getaffinity(0))
except AttributeError:  # pragma: no cover - not reachable on Linux
    quota = os.cpu_count() or 1

threads = max(1, min(4, quota))

torch.set_num_threads(threads)

app = FastAPI()

app.include_router(health_router)
app.include_router(summarization_router)
app.include_router(embeddings_router)
app.include_router(rerank_router)
app.include_router(rag_router)