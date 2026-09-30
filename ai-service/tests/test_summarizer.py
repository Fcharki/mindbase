"""The model boundary: what actually gets tokenised.

clean_text is unit tested on its own; these tests only care that
ModelService.generate applies it before the prompt is built, and that an entry
which is entirely formatting never reaches the model at all.
"""

import pytest

from app.services import summarizer


class _FakeTensor:
    def to(self, _device):
        return self


class _FakeTokenizer:
    def __init__(self):
        self.prompts = []

    def __call__(self, text, **_kwargs):
        self.prompts.append(text)

        return {"input_ids": _FakeTensor()}

    def decode(self, _output, **_kwargs):
        return "A summary."


class _FakeModel:
    def generate(self, **_kwargs):
        return [object()]


@pytest.fixture
def service():
    """A ModelService with the weights stubbed out."""
    instance = summarizer.ModelService()
    instance._load = lambda: None
    instance._tokenizer = _FakeTokenizer()
    instance._model = _FakeModel()
    instance._device = "cpu"

    return instance


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
