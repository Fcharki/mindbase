import json
import os
import threading
import urllib.error
import urllib.request
from pathlib import Path
from typing import Iterator

import torch
from transformers import AutoModelForCausalLM, AutoTokenizer

from app.util import clean_text, is_degenerate_output

MODELS_DIR = Path(__file__).resolve().parent.parent / "models"
MODEL_PATH = MODELS_DIR / "qwen3-0.6B"

MAX_NEW_TOKENS = int(os.getenv("LLM_MAX_NEW_TOKENS", "200"))

# Which engine answers /rag. 'hf' loads the bundled Qwen3 checkpoint locally,
# which is self-contained but slow on CPU. 'ollama' calls a separate Ollama
# server instead, which lets an operator run any model -- a larger one, or one
# already pulled -- without this image carrying its weights.
LLM_PROVIDER = os.getenv("LLM_PROVIDER", "hf").strip().lower()

OLLAMA_BASE_URL = os.getenv("OLLAMA_BASE_URL", "http://ollama:11434").rstrip("/")
OLLAMA_MODEL = os.getenv("OLLAMA_MODEL", "qwen2.5:0.5b")
# How long to wait for Ollama to emit the first byte before giving up. Generation
# itself is streamed, so this only bounds the initial connect and load.
OLLAMA_TIMEOUT = int(os.getenv("OLLAMA_TIMEOUT", "600"))

# bfloat16 is smaller and, on a CPU with AVX512-BF16, more accurate than
# float32 for this model. That speed and accuracy gain is hardware-conditional
# and this project runs on a 4-core container without it, so the default is
# unchanged. See LLMService for the measurements.
DTYPE_NAME = os.getenv("LLM_DTYPE", "bfloat16").lower()
DTYPES = {
    "bfloat16": torch.bfloat16,
    "float16": torch.float16,
    "float32": torch.float32,
}
DTYPE = DTYPES.get(DTYPE_NAME, torch.bfloat16)

# Qwen3 emits a visible <think> block before answering. It costs hundreds of
# tokens and is useless for a summary, so it is off unless asked for.
THINKING = os.getenv("LLM_THINKING", "false").strip().lower() in {
    "1", "true", "yes", "on"
}

REPETITION_PENALTY = float(os.getenv("LLM_REPETITION_PENALTY", "1.1"))

SUMMARY_SYSTEM_PROMPT = (
    "You are a technical writer. Summarise the user's document faithfully in "
    "three or four sentences. Preserve concrete facts: names, dates, numbers, "
    "causes and outcomes. Do not add information that is not in the document, "
    "do not speculate, and do not mention that you were asked to summarise. "
    "Reply with the summary text only."
)


class LLMService:
    """Lazily loads Qwen3-0.6B and answers instructions and chat prompts.

    Serves both /summarize and /rag.

    Measured on the 4-core container this project ships on: ~535 s to load the
    1.5 GB checkpoint, then ~0.10 tokens/second, so a 200 token answer takes
    about half an hour. Budgets downstream were once sized from a claimed 0.4
    tokens/second, which is why this model used to fail as an opaque 10 minute
    Guzzle abort instead of being refused up front.

    LLM_DTYPE=bfloat16 is the prime suspect: it is native only on CPUs with
    AVX512-BF16 and emulated elsewhere, so it can lose to float32 outright. Not
    measured here, so the default is left alone.

    Callers wanting a real answer should use the T5 summarizer; see
    app/services/summarizer.py.
    """

    def __init__(self):
        self._model = None
        self._tokenizer = None
        self._device = None

    def _load(self) -> None:
        if self._model is not None:
            return

        self._device = "cuda" if torch.cuda.is_available() else "cpu"
        print(f"Loading LLM onto '{self._device}' as {DTYPE_NAME}...")

        self._tokenizer = AutoTokenizer.from_pretrained(MODEL_PATH)
        self._model = AutoModelForCausalLM.from_pretrained(MODEL_PATH, dtype=DTYPE)
        self._model.to(self._device)
        self._model.eval()

        print("LLM ready.")

    def is_loaded(self) -> bool:
        return self._model is not None

    def provider(self) -> str:
        """Which engine serves requests. Reported by /health."""
        return LLM_PROVIDER

    def _apply_template(self, messages: list[dict], thinking: bool) -> str:
        kwargs = dict(
            tokenize=False,
            add_generation_prompt=True,
        )

        try:
            return self._tokenizer.apply_chat_template(
                messages, enable_thinking=thinking, **kwargs
            )
        except TypeError:
            # enable_thinking needs transformers >= 4.51. Fall back rather than
            # lose the request entirely on an older pin.
            return self._tokenizer.apply_chat_template(messages, **kwargs)

    def _messages(self, prompt: str, system: str | None) -> list[dict]:
        messages = []

        if system:
            messages.append({"role": "system", "content": system})

        messages.append({"role": "user", "content": prompt})

        return messages

    def generate(
        self,
        prompt: str,
        system: str | None = None,
        max_new_tokens: int | None = None,
        thinking: bool | None = None,
        sanitize: bool = True,
    ) -> str:
        if LLM_PROVIDER == "ollama":
            return self._generate_ollama(prompt, system, max_new_tokens)

        return self._generate_hf(prompt, system, max_new_tokens, thinking, sanitize)

    def _generate_hf(
        self,
        prompt: str,
        system: str | None,
        max_new_tokens: int | None,
        thinking: bool | None,
        sanitize: bool,
    ) -> str:
        self._load()

        text = self._apply_template(
            self._messages(prompt, system),
            THINKING if thinking is None else thinking,
        )

        inputs = self._tokenizer([text], return_tensors="pt")
        inputs = {key: value.to(self._device) for key, value in inputs.items()}

        with torch.inference_mode():
            output_ids = self._model.generate(
                **inputs,
                max_new_tokens=max_new_tokens or MAX_NEW_TOKENS,
                eos_token_id=self._tokenizer.convert_tokens_to_ids("<|im_end|>"),
                repetition_penalty=REPETITION_PENALTY,
            )

        # Slice off the prompt so the answer is decoded on its own terms.
        prompt_length = inputs["input_ids"].shape[1]
        answer = self._tokenizer.decode(
            output_ids[0][prompt_length:], skip_special_tokens=True
        ).strip()

        if not answer:
            return ""

        if sanitize and is_degenerate_output(answer):
            print("Discarding degenerate LLM output (repetition loop).")
            return ""

        return answer

    def _generate_ollama(
        self,
        prompt: str,
        system: str | None,
        max_new_tokens: int | None,
    ) -> str:
        payload = {
            "model": OLLAMA_MODEL,
            "messages": self._messages(prompt, system),
            "stream": False,
            "options": {"num_predict": max_new_tokens or MAX_NEW_TOKENS},
        }

        body = self._ollama_request(payload)

        return str(body.get("message", {}).get("content", "")).strip()

    def generate_stream(
        self,
        prompt: str,
        system: str | None = None,
        max_new_tokens: int | None = None,
    ) -> Iterator[str]:
        """Yield the answer piece by piece, for /rag/stream.

        The provider is chosen the same way as generate(), so a caller gets
        streaming whichever engine is configured.
        """
        if LLM_PROVIDER == "ollama":
            yield from self._stream_ollama(prompt, system, max_new_tokens)

            return

        yield from self._stream_hf(prompt, system, max_new_tokens)

    def _stream_hf(
        self,
        prompt: str,
        system: str | None,
        max_new_tokens: int | None,
    ) -> Iterator[str]:
        # Imported here so a service configured for Ollama never pays for the
        # streaming helper's dependencies at module import time.
        from transformers import TextIteratorStreamer

        self._load()

        text = self._apply_template(self._messages(prompt, system), THINKING)

        inputs = self._tokenizer([text], return_tensors="pt")
        inputs = {key: value.to(self._device) for key, value in inputs.items()}

        streamer = TextIteratorStreamer(
            self._tokenizer, skip_prompt=True, skip_special_tokens=True
        )

        # generate() is blocking, so it runs on its own thread and this generator
        # reads what the streamer hands back as each token is decoded.
        thread = threading.Thread(
            target=self._model.generate,
            kwargs=dict(
                **inputs,
                max_new_tokens=max_new_tokens or MAX_NEW_TOKENS,
                eos_token_id=self._tokenizer.convert_tokens_to_ids("<|im_end|>"),
                repetition_penalty=REPETITION_PENALTY,
                streamer=streamer,
            ),
        )
        thread.start()

        try:
            for piece in streamer:
                if piece:
                    yield piece
        finally:
            thread.join()

    def _stream_ollama(
        self,
        prompt: str,
        system: str | None,
        max_new_tokens: int | None,
    ) -> Iterator[str]:
        payload = {
            "model": OLLAMA_MODEL,
            "messages": self._messages(prompt, system),
            "stream": True,
            "options": {"num_predict": max_new_tokens or MAX_NEW_TOKENS},
        }

        request = urllib.request.Request(
            f"{OLLAMA_BASE_URL}/api/chat",
            data=json.dumps(payload).encode("utf-8"),
            headers={"Content-Type": "application/json"},
            method="POST",
        )

        with urllib.request.urlopen(request, timeout=OLLAMA_TIMEOUT) as response:
            for raw in response:
                line = raw.decode("utf-8").strip()

                if not line:
                    continue

                try:
                    event = json.loads(line)
                except json.JSONDecodeError:
                    continue

                if event.get("done"):
                    break

                piece = event.get("message", {}).get("content", "")

                if piece:
                    yield piece

    def _ollama_request(self, payload: dict) -> dict:
        request = urllib.request.Request(
            f"{OLLAMA_BASE_URL}/api/chat",
            data=json.dumps(payload).encode("utf-8"),
            headers={"Content-Type": "application/json"},
            method="POST",
        )

        try:
            with urllib.request.urlopen(request, timeout=OLLAMA_TIMEOUT) as response:
                return json.loads(response.read().decode("utf-8"))
        except urllib.error.URLError as error:
            raise RuntimeError(
                f"Could not reach Ollama at {OLLAMA_BASE_URL}: {error}"
            ) from error

    def summarize(self, text: str, max_new_tokens: int | None = None) -> str:
        """Summarise entry markup, flattening it to prose first."""
        cleaned = clean_text(text)

        if not cleaned:
            return ""

        return self.generate(
            cleaned,
            system=SUMMARY_SYSTEM_PROMPT,
            max_new_tokens=max_new_tokens,
        )


llm_service = LLMService()
