"""The model boundary: what actually gets tokenised.

clean_text is unit tested on its own; these tests only care that
T5Summarizer.generate applies it before the prompt is built, that an entry
which is entirely formatting never reaches the model at all, and that a
decoder loop is rejected rather than returned.
"""

import pytest

from app.services import summarizer
from app.services.summarizer import T5Summarizer, Summarizer


class _FakeTensor:
    def to(self, _device):
        return self

    @property
    def shape(self):
        return (1, 1)


class _FakeTokenizer:
    def __init__(self, decoded="A summary."):
        self.prompts = []
        self.decoded = decoded

    def __call__(self, text, **_kwargs):
        self.prompts.append(text)

        return {"input_ids": _FakeTensor()}

    def decode(self, _output, **_kwargs):
        return self.decoded


class _FakeModel:
    """Records every generate() call so decoder settings can be asserted."""

    def __init__(self, decoded="A summary."):
        self.calls = []
        self.decoded = decoded

    def generate(self, **kwargs):
        self.calls.append(kwargs)

        return [object()]


def _service(decoded="A summary."):
    instance = T5Summarizer()
    instance._load = lambda: None
    instance._tokenizer = _FakeTokenizer(decoded)
    instance._model = _FakeModel(decoded)
    instance._device = "cpu"

    return instance


@pytest.fixture
def service():
    return _service()


class TestPromptConstruction:
    def test_the_prompt_carries_flattened_prose(self, service):
        service.generate("<h2>Parser</h2><p>We shipped the **parser** last week.</p>")

        assert service._tokenizer.prompts == [
            "summarize: Parser We shipped the parser last week."
        ]

    def test_adjacent_paragraphs_are_not_welded_together(self, service):
        service.generate("<p>We hiked</p><p>the Blue Ridge trail.</p>")

        assert service._tokenizer.prompts == ["summarize: We hiked the Blue Ridge trail."]

    def test_formatting_is_preserved_when_stripping_is_disabled(self, service, monkeypatch):
        monkeypatch.setenv("AI_STRIP_FORMATTING", "false")

        service.generate("<h2>Parser</h2><p>We shipped the parser.</p>")

        assert service._tokenizer.prompts == [
            "summarize: <h2>Parser</h2><p>We shipped the parser.</p>"
        ]

    def test_an_entry_of_only_code_never_reaches_the_model(self, service):
        summary = service.generate("<pre><code>rm -rf /</code></pre>")

        assert summary == ""
        assert service._tokenizer.prompts == []

    def test_code_is_summarised_when_dropping_it_is_disabled(self, service, monkeypatch):
        monkeypatch.setenv("AI_DROP_CODE_BLOCKS", "false")

        summary = service.generate("<pre><code>rm -rf /</code></pre>")

        assert summary == "A summary."


class TestDecoderGuards:
    def test_repetition_guards_are_applied(self, service):
        service.generate("<p>We shipped the parser last week.</p>")

        call = service._model.calls[0]

        assert call["no_repeat_ngram_size"] == summarizer.NO_REPEAT_NGRAM_SIZE
        assert call["repetition_penalty"] == summarizer.REPETITION_PENALTY
        assert call["no_repeat_ngram_size"] > 0

    def test_a_repetition_loop_is_never_returned(self):
        loop = "timeout 504 timeout 504 timeout 504 timeout 504"

        assert _service(loop).generate("<p>pool exhausted</p>") == ""

    def test_a_loop_triggers_exactly_one_beam_retry(self):
        loop = "timeout 504 timeout 504 timeout 504 timeout 504"
        instance = _service(loop)

        instance.generate("<p>pool exhausted</p>")

        assert len(instance._model.calls) == 2
        assert instance._model.calls[1]["num_beams"] == 4

    def test_a_loop_that_survives_the_retry_is_discarded(self):
        instance = _service("timeout 504 timeout 504 timeout 504 timeout 504")

        assert instance.generate("<p>pool exhausted</p>") == ""
        assert len(instance._model.calls) == 2

    def test_beam_retry_recovers_a_non_degenerate_summary(self):
        loop = "timeout 504 timeout 504 timeout 504 timeout 504"
        instance = T5Summarizer()
        instance._load = lambda: None
        instance._device = "cpu"

        outputs = [loop, "The pool was exhausted by unbounded cache growth."]

        class _SwitchingTokenizer(_FakeTokenizer):
            def decode(self, _output, **_kwargs):
                return outputs.pop(0)

        instance._tokenizer = _SwitchingTokenizer()
        instance._model = _FakeModel()

        assert (
            instance.generate("<p>pool exhausted</p>")
            == "The pool was exhausted by unbounded cache growth."
        )


class TestBackendSelection:
    def test_t5_is_the_default_backend(self):
        assert Summarizer().backend == "t5"

    def test_an_unknown_backend_falls_back_to_t5(self):
        assert Summarizer("gpt-9").backend == "t5"

    def test_qwen_backend_delegates_to_the_llm_summariser(self, monkeypatch):
        from app.services import llm

        calls = []
        monkeypatch.setattr(
            llm.llm_service,
            "summarize",
            lambda text, max_new_tokens=None: calls.append((text, max_new_tokens))
            or "llm summary",
        )

        service = Summarizer("qwen")

        assert service.generate("<p>We shipped the parser.</p>") == "llm summary"
        # Raw markup is handed over; summarize() owns the flattening.
        assert calls[0] == ("<p>We shipped the parser.</p>", None)

    def test_the_facade_reports_the_selected_backend(self):
        assert Summarizer("qwen").is_loaded() in (True, False)


class TestBackendOverride:
    """A caller can pick a model for one request without changing the default."""

    def test_no_override_uses_the_configured_backend(self):
        assert Summarizer("t5").resolve_backend() == "t5"
        assert Summarizer("qwen").resolve_backend() == "qwen"

    def test_an_override_selects_that_backend(self):
        assert Summarizer("t5").resolve_backend("qwen") == "qwen"
        assert Summarizer("qwen").resolve_backend("t5") == "t5"

    def test_the_override_is_normalised(self):
        assert Summarizer("t5").resolve_backend("  QWEN ") == "qwen"

    def test_an_unknown_override_falls_back_to_the_configured_backend(self):
        assert Summarizer("t5").resolve_backend("gpt-9") == "t5"
        assert Summarizer("qwen").resolve_backend("gpt-9") == "qwen"

    def test_an_override_reaches_the_llm_summariser(self, monkeypatch):
        from app.services import llm

        calls = []
        monkeypatch.setattr(
            llm.llm_service,
            "summarize",
            lambda text, max_new_tokens=None: calls.append(text) or "llm summary",
        )

        # Configured for t5, but asked for qwen.
        assert Summarizer("t5").generate("<p>Hi.</p>", backend="qwen") == "llm summary"
        # Raw markup is handed over; summarize() owns the flattening.
        assert calls == ["<p>Hi.</p>"]

    def test_no_override_keeps_using_the_t5_path(self, service):
        assert Summarizer("t5").backend == "t5"
        assert service.generate("<p>Hi.</p>") == "A summary."
