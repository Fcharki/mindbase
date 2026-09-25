import os
from pathlib import Path

import torch
from transformers import AutoModelForSeq2SeqLM, AutoTokenizer

MODEL_PATH = Path(__file__).resolve().parent.parent / "models"

MAX_INPUT_LENGTH = int(os.getenv("AI_MAX_INPUT_LENGTH", "512"))
MAX_NEW_TOKENS = int(os.getenv("AI_MAX_NEW_TOKENS", "150"))
NUM_BEAMS = int(os.getenv("AI_NUM_BEAMS", "1"))


class ModelService:
    """Lazily loads the summarization model on first use, then caches it."""

    def __init__(self):
        self._model = None
        self._tokenizer = None
        self._device = None

    def _load(self) -> None:
        if self._model is not None:
            return

        self._device = "cuda" if torch.cuda.is_available() else "cpu"
        print(f"Loading summarization model onto '{self._device}'...")

        self._tokenizer = AutoTokenizer.from_pretrained(MODEL_PATH)
        self._model = AutoModelForSeq2SeqLM.from_pretrained(MODEL_PATH)
        self._model.to(self._device)
        self._model.eval()

        print("Summarization model ready.")

    def is_loaded(self) -> bool:
        return self._model is not None

    def generate(self, text: str, max_new_tokens: int = MAX_NEW_TOKENS) -> str:
        self._load()

        prompt = f"summarize: {text}"

        inputs = self._tokenizer(
            prompt,
            return_tensors="pt",
            truncation=True,
            max_length=MAX_INPUT_LENGTH,
        )
        inputs = {key: value.to(self._device) for key, value in inputs.items()}

        with torch.inference_mode():
            output_ids = self._model.generate(
                **inputs,
                max_new_tokens=max_new_tokens,
                num_beams=NUM_BEAMS,
                early_stopping=NUM_BEAMS > 1,
            )

        return self._tokenizer.decode(
            output_ids[0],
            skip_special_tokens=True,
        )


model_service = ModelService()