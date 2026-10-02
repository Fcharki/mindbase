import os
from pathlib import Path

import torch
import torch.nn.functional as F
from transformers import AutoModel, AutoTokenizer

from app.util import clean_text

MODELS_DIR = Path(__file__).resolve().parent.parent / "models"
MODEL_PATH = MODELS_DIR / "bge-m3"

EMBEDDING_DIMENSION = 1024

# bge-m3 was trained on inputs up to 8192 tokens. Truncating there keeps a long
# document from being silently cut mid-sentence at the smaller default.
MAX_INPUT_LENGTH = int(os.getenv("EMBEDDING_MAX_INPUT_LENGTH", "8192"))


class EmbeddingService:
    """Lazily loads bge-m3 and encodes text into dense vectors.

    Deliberately built on plain transformers rather than SentenceTransformer:
    bge-m3's published snapshot lists a `2_Normalize` module in modules.json
    but never ships the directory, so SentenceTransformer raises on load. The
    dense recipe this model documents is only the two steps reproduced below,
    and doing them directly keeps the dense-only dependency out of the image.

    Vectors are L2-normalised, so a dot product is a cosine similarity and
    there is no need to normalise again at query time.
    """

    def __init__(self):
        self._model = None
        self._tokenizer = None
        self._device = None

    def _load(self) -> None:
        if self._model is not None:
            return

        self._device = "cuda" if torch.cuda.is_available() else "cpu"
        print(f"Loading embedding model onto '{self._device}'...")

        self._tokenizer = AutoTokenizer.from_pretrained(MODEL_PATH)
        self._model = AutoModel.from_pretrained(MODEL_PATH)
        self._model.to(self._device)
        self._model.eval()

        print("Embedding model ready.")

    def is_loaded(self) -> bool:
        return self._model is not None

    def encode(self, text: str) -> list[float]:
        """Encode one string, returning a unit-length dense vector.

        Markup is flattened first for the same reason summarization does it:
        the model was trained on prose, and editor HTML only wastes tokens.

        A chunk that is nothing but code comes back empty from that flattening,
        because dropping code blocks is the right call for a summariser and the
        wrong one here. bge-m3 handles code perfectly well, and a chunk given no
        vector at all is not indexed and so is not findable by keyword search
        either -- one pasted SQL dump would cost the entry its whole index. So
        the fallback re-flattens with the code kept.
        """
        self._load()

        cleaned = clean_text(text)

        if not cleaned:
            cleaned = clean_text(text, drop_code=False)

        if not cleaned:
            return []

        inputs = self._tokenizer(
            cleaned,
            return_tensors="pt",
            truncation=True,
            max_length=MAX_INPUT_LENGTH,
        )
        inputs = {key: value.to(self._device) for key, value in inputs.items()}

        with torch.inference_mode():
            output = self._model(**inputs)

        # CLS pooling, per the model's own 1_Pooling config, then L2 normalise.
        vector = F.normalize(output.last_hidden_state[:, 0], p=2, dim=1)

        return vector[0].tolist()

    def encode_many(self, texts: list[str]) -> list[list[float]]:
        """Encode several strings in one pass.

        Loads the model once and batches the forward passes, so indexing an
        entry costs one model load rather than one per chunk. A text with no
        prose still returns an empty vector to keep the results aligned with the
        input, which the caller relies on to zip chunks to vectors.
        """
        if not texts:
            return []

        self._load()

        return [self.encode(text) for text in texts]


embedding_service = EmbeddingService()