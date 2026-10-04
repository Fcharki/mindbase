"""The embedding boundary: pooling and normalisation.

The weights are stubbed out; what matters here is that CLS pooling is taken
from the right tensor and that the vector handed back is unit length, since
every later cosine comparison assumes it.
"""

import pytest
import torch

from app.services import embeddings


class _FakeTensor:
    def __init__(self, value):
        self.value = value

    def to(self, _device):
        return self


class _FakeTokenizer:
    def __init__(self):
        self.texts = []

    def __call__(self, text, **_kwargs):
        self.texts.append(text)

        return {"input_ids": _FakeTensor(None)}


class _FakeOutput:
    def __init__(self, last_hidden_state):
        self.last_hidden_state = last_hidden_state


class _FakeModel:
    """Returns a known CLS vector so the expected output is computable."""

    def __call__(self, **_kwargs):
        # Batch of 1, sequence of 2: only the first row is the CLS token.
        return _FakeOutput(
            torch.tensor([[[3.0, 4.0, 0.0], [99.0, 99.0, 99.0]]])
        )

    def to(self, _device):
        return self

    def eval(self):
        return self


@pytest.fixture
def service():
    instance = embeddings.EmbeddingService()
    instance._load = lambda: None
    instance._tokenizer = _FakeTokenizer()
    instance._model = _FakeModel()
    instance._device = "cpu"

    return instance


class TestEncoding:
    def test_the_vector_is_unit_length(self, service):
        vector = service.encode("Some prose about the project.")

        assert pytest.approx(
            sum(component**2 for component in vector), abs=1e-6
        ) == 1.0

    def test_cls_token_is_pooled_and_normalised(self, service):
        # CLS is [3, 4, 0]; normalising a 3-4-5 triangle gives [0.6, 0.8, 0].
        assert service.encode("Some prose.") == pytest.approx(
            [0.6, 0.8, 0.0], abs=1e-6
        )

    def test_markup_is_flattened_before_encoding(self, service):
        service.encode("<h2>Parser</h2><p>We shipped it.</p>")

        assert service._tokenizer.texts == ["Parser We shipped it."]

    def test_an_entry_of_only_markup_yields_no_vector(self, service):
        assert service.encode("<script>alert(1)</script>") == []
        assert service._tokenizer.texts == []

    def test_an_empty_string_yields_no_vector(self, service):
        assert service.encode("") == []
        assert service._tokenizer.texts == []

    def test_a_code_only_chunk_still_gets_a_vector(self, service):
        # Dropping code is the right call for a summariser and the wrong one
        # here: a chunk given no vector is not indexed, so it is not findable by
        # keyword search either.
        vector = service.encode(
            "<pre><code>SELECT id FROM orders WHERE total IS NULL;</code></pre>"
        )

        assert vector == pytest.approx([0.6, 0.8, 0.0], abs=1e-6)
        assert service._tokenizer.texts == [
            "SELECT id FROM orders WHERE total IS NULL;"
        ]

    def test_a_fenced_chunk_collapsed_onto_one_line_still_gets_a_vector(self, service):
        # The shape the indexing job actually sends, because the chunker flattens
        # content to a single line first. This used to return an empty vector,
        # which failed embedding of every chunk in the entry.
        vector = service.encode("```sql SELECT id FROM orders;```")

        assert vector == pytest.approx([0.6, 0.8, 0.0], abs=1e-6)
        assert service._tokenizer.texts == ["sql SELECT id FROM orders;"]

class TestEncodeMany:
    """Indexing an entry must not cost one model load per chunk."""

    def test_an_empty_batch_returns_nothing(self, service):
        assert service.encode_many([]) == []
        assert service._tokenizer.texts == []

    def test_results_stay_aligned_with_the_input(self, service):
        vectors = service.encode_many(["first", "second"])

        assert len(vectors) == 2
        assert service._tokenizer.texts == ["first", "second"]

    def test_a_blank_entry_keeps_its_slot_as_an_empty_vector(self, service):
        vectors = service.encode_many(["first", "   "])

        # Alignment matters: the caller zips chunks to vectors positionally.
        assert vectors[0] == pytest.approx([0.6, 0.8, 0.0], abs=1e-6)
        assert vectors[1] == []

    def test_markup_is_flattened_for_every_text(self, service):
        service.encode_many(["<p>One.</p>", "<p>Two.</p>"])

        assert service._tokenizer.texts == ["One.", "Two."]
