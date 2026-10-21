"""Extractor tests with a fake PaddleOCR engine."""

import base64
import json
from io import BytesIO
from pathlib import Path

import pytest
from PIL import Image

from app import ocr


PNG = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8"
    "/x8AAwMCAO+ip1sAAAAASUVORK5CYII="
)


class _Result:
    def __init__(self, payload):
        self.json = payload


class _MethodResult:
    """A build where json is a method rather than a property."""

    def __init__(self, payload):
        self._payload = payload

    def json(self):
        return self._payload


class _Engine:
    def __init__(self, results):
        self.results = results
        self.paths = []
        self.contents = []

    def predict(self, path):
        self.paths.append(path)
        self.contents.append(Path(path).read_bytes())

        return self.results


@pytest.fixture
def engine(monkeypatch):
    def install(results):
        fake = _Engine(results)
        monkeypatch.setattr(ocr, "_ocr", fake)

        return fake

    return install


def test_lines_are_joined_in_detection_order(engine):
    engine([_Result({"res": {"rec_texts": ["Invoice 42", " ", "Total 10.00"]}})])

    assert ocr.extract_text(PNG, ".png") == "Invoice 42\nTotal 10.00"


def test_a_plain_mapping_is_accepted(engine):
    engine([{"res": {"rec_texts": ["First", None, "Second"]}}])

    assert ocr.extract_text(PNG, ".png") == "First\nSecond"


def test_json_exposed_as_a_method_is_accepted(engine):
    engine([_MethodResult({"res": {"rec_texts": ["From a method"]}})])

    assert ocr.extract_text(PNG, ".png") == "From a method"


def test_json_encoded_as_a_string_is_accepted(engine):
    engine([_Result(json.dumps({"res": {"rec_texts": ["Encoded"]}}))])

    assert ocr.extract_text(PNG, ".png") == "Encoded"


def test_a_page_without_text_is_an_empty_string(engine):
    engine([])

    assert ocr.extract_text(PNG, ".png") == ""


def test_webp_is_converted_to_png_before_the_engine_sees_it(engine):
    buffer = BytesIO()
    Image.new("RGB", (4, 3), "white").save(buffer, format="WEBP")

    fake = engine([])
    ocr.extract_text(buffer.getvalue(), ".webp")

    assert fake.contents[0].startswith(b"\x89PNG\r\n\x1a\n")
    assert fake.paths[0].endswith(".png")


def test_an_upload_that_is_not_an_image_is_passed_through_unchanged(engine):
    fake = engine([])
    ocr.extract_text(b"not really an image", "jpg")

    assert fake.contents[0] == b"not really an image"
    assert fake.paths[0].endswith(".jpg")


def test_the_temporary_file_is_removed_after_the_read(engine):
    fake = engine([])
    ocr.extract_text(PNG, ".png")

    assert not Path(fake.paths[0]).exists()
