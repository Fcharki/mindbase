"""The rerank boundary: score ordering and the identity fallback.

The weights are never loaded. What matters is that the model's scores decide
the order, and that a service which cannot reach its model degrades to the
input order rather than dropping results.
"""

import pytest
import torch

from app.services.rerank import RerankService


class _FakeBatch(dict):
    def to(self, _device):
        return self


class _FakeTokenizer:
    def __init__(self):
        self.pairs = []

    def __call__(self, pairs, **_kwargs):
        self.pairs.append(pairs)

        return _FakeBatch(input_ids=torch.zeros(len(pairs), 2))


class _Logits:
    def __init__(self, values):
        # (n, 1), matching a cross-encoder checkpoint's single label.
        self.logits = torch.tensor([[value] for value in values])


class _FakeModel:
    def __init__(self, scores):
        self.scores = scores

    def __call__(self, **_kwargs):
        return _Logits(self.scores)

    def to(self, _device):
        return self

    def eval(self):
        return self


class TestOrdering:
    def test_passages_come_back_best_first(self):
        service = RerankService()
        service._load = lambda: True
        service._tokenizer = _FakeTokenizer()
        service._model = _FakeModel([0.1, 9.0, 3.0])
        service._device = "cpu"

        assert service.rerank("question", ["a", "b", "c"]) == [1, 2, 0]

    def test_every_passage_is_paired_with_the_query(self):
        service = RerankService()
        service._load = lambda: True
        tokenizer = _FakeTokenizer()
        service._tokenizer = tokenizer
        service._model = _FakeModel([1.0, 2.0])
        service._device = "cpu"

        service.rerank("how do tokens expire", ["one", "two"])

        assert tokenizer.pairs[0] == [
            ("how do tokens expire", "one"),
            ("how do tokens expire", "two"),
        ]


class TestFallback:
    def test_no_passages_needs_no_model(self):
        service = RerankService()

        assert service.rerank("question", []) == []

    def test_an_unavailable_model_keeps_the_input_order(self):
        service = RerankService()
        service._load = lambda: False

        assert service.rerank("question", ["a", "b", "c"]) == [0, 1, 2]

    def test_a_failed_load_is_not_retried(self):
        service = RerankService()
        calls = []

        def load():
            calls.append(1)

            return False

        service._load = load

        service.rerank("question", ["a"])
        service.rerank("question", ["a"])

        assert len(calls) == 2

        # is_loaded stays false; the service did not fabricate a model.
        assert service.is_loaded() is False
