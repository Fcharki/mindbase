import os
from pathlib import Path

import torch
from transformers import AutoModelForSeq2SeqLM, AutoTokenizer

from app.util import clean_text, is_degenerate_output

MODELS_DIR = Path(__file__).resolve().parent.parent / "models"
MODEL_PATH = MODELS_DIR / "summarizer"

MAX_INPUT_LENGTH = int(os.getenv("AI_MAX_INPUT_LENGTH", "512"))
MAX_NEW_TOKENS = int(os.getenv("AI_MAX_NEW_TOKENS", "150"))
NUM_BEAMS = int(os.getenv("AI_NUM_BEAMS", "1"))

# Greedy search on this checkpoint loops on short, keyword-like or repetitive
# input, emitting a phrase until max_new_tokens is spent. Barring any trigram
# from repeating is the cheapest way to stop that, and a summary has no reason
# to repeat a trigram anyway.
NO_REPEAT_NGRAM_SIZE = int(os.getenv("AI_NO_REPEAT_NGRAM_SIZE", "3"))
REPETITION_PENALTY = float(os.getenv("AI_REPETITION_PENALTY", "1.15"))

# "t5" is fast but largely extracts; "qwen" abstracts properly and is two
# orders of magnitude slower on CPU.
BACKEND = os.getenv("AI_SUMMARY_BACKEND", "t5").strip().lower()
BACKENDS = ("t5", "qwen")


class T5Summarizer:
    """Lazily loads the T5-small checkpoint and generates extractive summaries."""

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

    def _decode(self, text: str, max_new_tokens: int, num_beams: int) -> str:
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
                num_beams=num_beams,
                early_stopping=num_beams > 1,
                no_repeat_ngram_size=NO_REPEAT_NGRAM_SIZE,
                repetition_penalty=REPETITION_PENALTY,
            )

        return self._tokenizer.decode(output_ids[0], skip_special_tokens=True)

    def generate(self, text: str, max_new_tokens: int | None = None) -> str:
        self._load()

        cleaned = clean_text(text)

        if not cleaned:
            return ""

        budget = MAX_NEW_TOKENS if max_new_tokens is None else max_new_tokens

        summary = self._decode(cleaned, budget, NUM_BEAMS).strip()

        if not is_degenerate_output(summary):
            return summary

        # Beam search explores more of the distribution and occasionally escapes
        # a loop that greedy search fell into, so it is worth one retry before
        # giving up.
        if NUM_BEAMS > 1:
            return ""

        print("Degenerate summary from greedy search, retrying with beam search.")

        retry = self._decode(cleaned, budget, 4).strip()

        if is_degenerate_output(retry):
            return ""

        return retry


class Summarizer:
    """Entry-point for summarization, dispatching to the configured backend."""

    def __init__(self, backend: str | None = None):
        self.backend = (backend or BACKEND).strip().lower()

        if self.backend not in BACKENDS:
            print(
                f"Unknown AI_SUMMARY_BACKEND '{self.backend}', "
                f"expected one of {BACKENDS}. Falling back to 't5'."
            )
            self.backend = "t5"

        self._t5 = None

    @property
    def t5(self) -> T5Summarizer:
        if self._t5 is None:
            self._t5 = T5Summarizer()

        return self._t5

    @property
    def _impl(self):
        if self.backend == "qwen":
            from app.services.llm import llm_service

            return llm_service

        return self.t5

    def is_loaded(self) -> bool:
        return self._impl.is_loaded()

    def resolve_backend(self, backend: str | None = None) -> str:
        """The backend a call will actually use.

        An unknown name falls back to the configured one rather than failing:
        a caller asking for a model that does not exist still wants a summary,
        and the response reports which backend answered so the caller can tell
        the difference.
        """
        if backend is None:
            return self.backend

        candidate = backend.strip().lower()

        return candidate if candidate in BACKENDS else self.backend

    def generate(
        self,
        text: str,
        max_new_tokens: int | None = None,
        backend: str | None = None,
    ) -> str:
        # The Qwen backend is an instruction model, so summarisation is a
        # prompted call rather than a prefixed one, and it owns its own
        # markup flattening.
        if self.resolve_backend(backend) == "qwen":
            from app.services.llm import llm_service

            return llm_service.summarize(text, max_new_tokens=max_new_tokens)

        return self.t5.generate(text, max_new_tokens=max_new_tokens)


summary_service = Summarizer()
