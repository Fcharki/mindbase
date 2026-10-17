"""The /rag and /rag/stream contract shared with the Laravel backend.

The model is stubbed out. What matters is the frame shape the backend parses:
deltas as they arrive, then one `done` carrying the full text, and an `error`
frame instead of a raise when generation fails mid-stream.
"""

import json

import pytest
from fastapi.testclient import TestClient

from app.api.routes import rag as rag_route
from app.main import app


class _StubLLM:
    def __init__(self, pieces=None, error=None):
        self.pieces = pieces or []
        self.error = error
        self.calls = []

    def generate(self, prompt, system=None, max_new_tokens=None):
        self.calls.append((prompt, system, max_new_tokens))

        return "".join(self.pieces)

    def generate_stream(self, prompt, system=None, max_new_tokens=None):
        self.calls.append((prompt, system, max_new_tokens))

        yield from self.pieces

        if self.error is not None:
            raise RuntimeError(self.error)


@pytest.fixture
def client():
    return TestClient(app)


def _frames(response):
    return [
        json.loads(line[len("data:"):])
        for line in response.text.splitlines()
        if line.startswith("data:")
    ]


def test_rag_returns_the_answer(client, monkeypatch):
    stub = _StubLLM(["Hello."])
    monkeypatch.setattr(rag_route, "llm_service", stub)

    response = client.post("/rag", json={"prompt": "hi", "max_new_tokens": 10})

    assert response.status_code == 200
    assert response.json() == {"response": "Hello."}
    assert stub.calls == [("hi", None, 10)]


def test_stream_emits_deltas_then_the_full_answer(client, monkeypatch):
    stub = _StubLLM(["The ", "answer."])
    monkeypatch.setattr(rag_route, "llm_service", stub)

    response = client.post("/rag/stream", json={"prompt": "hi"})

    assert response.status_code == 200

    frames = _frames(response)

    assert {"type": "delta", "text": "The "} in frames
    assert {"type": "delta", "text": "answer."} in frames
    assert frames[-1] == {"type": "done", "response": "The answer."}


def test_a_failure_mid_stream_is_reported_as_an_error_frame(client, monkeypatch):
    stub = _StubLLM(["partial"], error="model exploded")
    monkeypatch.setattr(rag_route, "llm_service", stub)

    response = client.post("/rag/stream", json={"prompt": "hi"})

    frames = _frames(response)

    assert frames[-1] == {"type": "error", "message": "model exploded"}
    # No `done` frame, so the backend does not treat the fragment as an answer.
    assert all(frame["type"] != "done" for frame in frames)
