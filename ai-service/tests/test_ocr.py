"""The /ocr boundary, with PaddleOCR stubbed out."""

import pytest
from fastapi.testclient import TestClient

from app.api.routes import ocr as ocr_route
from app.main import app


@pytest.fixture
def client():
    return TestClient(app)


def test_ocr_returns_the_extracted_text(client, monkeypatch):
    calls = []

    def fake_extract(contents, suffix):
        calls.append((contents, suffix))

        return "Invoice 42\nTotal 10.00"

    monkeypatch.setattr(ocr_route, "extract_text", fake_extract)

    response = client.post(
        "/ocr",
        files={"file": ("receipt.png", b"image-bytes", "image/png")},
    )

    assert response.status_code == 200
    assert response.json() == {"text": "Invoice 42\nTotal 10.00"}
    assert calls == [(b"image-bytes", ".png")]


def test_ocr_rejects_a_file_that_is_not_an_image(client, monkeypatch):
    called = False

    def fake_extract(contents, suffix):
        nonlocal called
        called = True

        return ""

    monkeypatch.setattr(ocr_route, "extract_text", fake_extract)

    response = client.post(
        "/ocr",
        files={"file": ("notes.txt", b"plain text", "text/plain")},
    )

    assert response.status_code == 415
    assert called is False


def test_an_empty_upload_is_rejected(client):
    response = client.post(
        "/ocr",
        files={"file": ("blank.png", b"", "image/png")},
    )

    assert response.status_code == 400
