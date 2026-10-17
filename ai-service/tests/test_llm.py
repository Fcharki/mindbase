"""The LLM boundary: chat template, decoding budget and output sanitising.

Weights are never loaded here. What matters is that the prompt reaches the
model in the shape Qwen3 expects, that the answer is decoded without the prompt,
and that a repetition loop is discarded rather than served.
"""

import pytest

from app.services import llm
from app.services.llm import LLMService


class _FakeTensor:
    def to(self, _device):
        return self

    @property
    def shape(self):
        return (1, 4)


class _FakeTokenizer:
    def __init__(self):
        self.templates = []
        self.decoded = "A summary."
        self.supports_thinking = True

    def apply_chat_template(self, messages, **kwargs):
        self.templates.append((messages, kwargs))

        return "templated"

    def __call__(self, texts, **_kwargs):
        return {"input_ids": _FakeTensor()}

    def convert_tokens_to_ids(self, _token):
        return 42

    def decode(self, _output, **_kwargs):
        return self.decoded


class _FakeModel:
    def __init__(self):
        self.calls = []

    def generate(self, **kwargs):
        self.calls.append(kwargs)

        # The service slices the prompt length off before decoding.
        return [[0, 1, 2, 3, 4]]


@pytest.fixture
def service():
    instance = LLMService()
    instance._load = lambda: None
    instance._tokenizer = _FakeTokenizer()
    instance._model = _FakeModel()
    instance._device = "cpu"

    return instance


class TestPromptShape:
    def test_a_system_prompt_is_placed_before_the_user_turn(self, service):
        service.generate("the document", system="You are a technical writer.")

        messages, _kwargs = service._tokenizer.templates[0]

        assert messages[0] == {
            "role": "system",
            "content": "You are a technical writer.",
        }
        assert messages[-1] == {"role": "user", "content": "the document"}

    def test_no_system_turn_is_invented(self, service):
        service.generate("the document")

        messages, _kwargs = service._tokenizer.templates[0]

        assert [message["role"] for message in messages] == ["user"]

    def test_thinking_is_off_by_default(self, service, monkeypatch):
        monkeypatch.setattr(llm, "THINKING", False)

        service.generate("the document")

        _messages, kwargs = service._tokenizer.templates[0]

        assert kwargs["enable_thinking"] is False

    def test_thinking_can_be_requested_per_call(self, service):
        service.generate("the document", thinking=True)

        _messages, kwargs = service._tokenizer.templates[0]

        assert kwargs["enable_thinking"] is True

    def test_an_older_transformers_without_thinking_still_works(self, service):
        class _LegacyTokenizer(_FakeTokenizer):
            def apply_chat_template(self, messages, **kwargs):
                if "enable_thinking" in kwargs:
                    raise TypeError("unexpected keyword argument 'enable_thinking'")

                self.templates.append((messages, kwargs))

                return "templated"

        service._tokenizer = _LegacyTokenizer()

        assert service.generate("the document") == "A summary."
        assert service._tokenizer.templates[0][1] == {
            "tokenize": False,
            "add_generation_prompt": True,
        }


class TestDecoding:
    def test_generation_is_budgeted_and_capped(self, service):
        service.generate("the document")

        call = service._model.calls[0]

        assert call["max_new_tokens"] == llm.MAX_NEW_TOKENS
        assert call["eos_token_id"] == 42
        assert call["repetition_penalty"] == llm.REPETITION_PENALTY

    def test_an_explicit_budget_overrides_the_default(self, service):
        service.generate("the document", max_new_tokens=40)

        assert service._model.calls[0]["max_new_tokens"] == 40

    def test_empty_output_is_normalised_to_empty_string(self, service):
        service._tokenizer.decoded = "   \n  "

        assert service.generate("the document") == ""

    def test_a_repetition_loop_is_discarded(self, service):
        service._tokenizer.decoded = "timeout 504 timeout 504 timeout 504 timeout 504"

        assert service.generate("the document") == ""

    def test_sanitising_can_be_switched_off_for_raw_chat(self, service):
        service._tokenizer.decoded = "timeout 504 timeout 504 timeout 504"

        answer = service.generate("the document", sanitize=False)

        assert answer == "timeout 504 timeout 504 timeout 504"


class TestSummarize:
    def test_markup_is_flattened_before_it_reaches_the_prompt(self, service):
        service.summarize("<h2>Parser</h2><p>We shipped the **parser**.</p>")

        messages, _kwargs = service._tokenizer.templates[0]

        assert messages[-1]["content"] == "Parser We shipped the parser."

    def test_the_summarisation_system_prompt_is_used(self, service):
        service.summarize("<p>We shipped the parser.</p>")

        messages, _kwargs = service._tokenizer.templates[0]

        assert messages[0]["content"] == llm.SUMMARY_SYSTEM_PROMPT

    def test_an_entry_of_only_formatting_returns_empty_without_calling_the_model(
        self, service
    ):
        assert service.summarize("<pre><code>rm -rf /</code></pre>") == ""
        assert service._model.calls == []


class TestProviderDispatch:
    """Which engine serves a call, and what the streaming path hands back."""

    def test_hf_is_the_default_engine(self, service):
        assert service.provider() == "hf"
        assert service.generate("the document") == "A summary."

    def test_ollama_is_used_when_configured(self, service, monkeypatch):
        monkeypatch.setattr(llm, "LLM_PROVIDER", "ollama")

        service._ollama_request = lambda _payload: {
            "message": {"content": "  From Ollama.  "}
        }

        assert service.generate("the document") == "From Ollama."
        # The local model was never touched on this path.
        assert service._model.calls == []

    def test_streaming_follows_the_configured_provider(self, service, monkeypatch):
        monkeypatch.setattr(llm, "LLM_PROVIDER", "ollama")
        service._stream_ollama = lambda *_args, **_kwargs: iter(["A", "B"])

        assert list(service.generate_stream("the document")) == ["A", "B"]

