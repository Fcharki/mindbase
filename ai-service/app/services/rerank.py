import os

import torch
from transformers import AutoModelForSequenceClassification, AutoTokenizer

# A cross-encoder reads the query and the passage together and scores the pair,
# which is a stronger relevance signal than the position fusion upstream. It is
# deliberately a small MiniLM: reranking runs inside the same request as the
# answer, so it has to be cheap enough not to dominate the latency.
#
# Built on plain transformers rather than sentence-transformers' CrossEncoder to
# keep the dependency set to the one already in the image. A cross-encoder is
# just a sequence-classification head over a concatenated (query, passage) pair,
# which is all the code below reproduces.
RERANK_MODEL = os.getenv("RERANK_MODEL", "cross-encoder/ms-marco-MiniLM-L-6-v2")

# Pairs longer than this are truncated. A passage that is already a chunk is far
# under it; the cap only bounds a pathological input.
MAX_PAIR_LENGTH = int(os.getenv("RERANK_MAX_PAIR_LENGTH", "512"))


class RerankService:
    """Scores query/passage pairs and returns them best-first.

    The model is fetched from the Hub on first use, so a service that is
    firewalled off will fail to load. That is reported to the caller as an
    identity order rather than an error: reranking is an optional precision
    step, and losing it must never lose results.
    """

    def __init__(self):
        self._model = None
        self._tokenizer = None
        self._device = None
        self._failed = False

    def _load(self) -> bool:
        if self._model is not None:
            return True

        if self._failed:
            return False

        self._device = "cuda" if torch.cuda.is_available() else "cpu"
        print(f"Loading rerank model '{RERANK_MODEL}' onto '{self._device}'...")

        try:
            self._tokenizer = AutoTokenizer.from_pretrained(RERANK_MODEL)
            self._model = AutoModelForSequenceClassification.from_pretrained(RERANK_MODEL)
        except Exception as error:  # noqa: BLE001 - reported, not swallowed
            # Remember the failure so every later request does not retry a slow,
            # doomed download.
            self._failed = True
            print(f"Rerank model unavailable, serving identity order: {error}")

            return False

        self._model.to(self._device)
        self._model.eval()

        return True

    def is_loaded(self) -> bool:
        return self._model is not None

    def rerank(self, query: str, passages: list[str]) -> list[int]:
        """Return passage indexes, most relevant first.

        Falls back to the input order whenever the model cannot be used, so the
        caller's fallback and this one agree.
        """
        identity = list(range(len(passages)))

        if not passages:
            return []

        if not self._load():
            return identity

        pairs = [(query, passage) for passage in passages]

        inputs = self._tokenizer(
            pairs,
            padding=True,
            truncation=True,
            max_length=MAX_PAIR_LENGTH,
            return_tensors="pt",
        )
        inputs = {key: value.to(self._device) for key, value in inputs.items()}

        with torch.inference_mode():
            logits = self._model(**inputs).logits

        # num_labels is 1 for the ms-marco checkpoints, so the squeeze turns the
        # (n, 1) tensor into one score per pair.
        scores = logits.squeeze(-1).tolist()

        return sorted(identity, key=lambda index: scores[index], reverse=True)


rerank_service = RerankService()
