import json
import os
from io import BytesIO
from tempfile import NamedTemporaryFile
from threading import RLock

# Built lazily: the model download would otherwise delay service startup.
_ocr = None

# Engine init and predict are not safe to overlap on one instance.
_lock = RLock()


def _engine():
    global _ocr

    with _lock:
        if _ocr is None:
            from paddleocr import PaddleOCR

            _ocr = PaddleOCR(
                lang=os.getenv("OCR_LANG", "en"),
                device="cpu",
                use_doc_orientation_classify=False,
                use_doc_unwarping=False,
                use_textline_orientation=False,
            )

    return _ocr


def is_loaded() -> bool:
    return _ocr is not None


def _as_png(image_bytes: bytes) -> bytes | None:
    """Convert accepted formats to PNG and apply EXIF orientation."""
    try:
        from PIL import Image, ImageOps
    except ImportError:  # pragma: no cover - Pillow ships with PaddleOCR
        return None

    try:
        with Image.open(BytesIO(image_bytes)) as image:
            image.seek(0)

            buffer = BytesIO()
            ImageOps.exif_transpose(image).convert("RGB").save(
                buffer, format="PNG"
            )

            return buffer.getvalue()
    except Exception:  # noqa: BLE001 - not an image, or a truncated one
        return None


def _lines(results) -> list[str]:
    """Extract recognised lines from the result shapes PaddleOCR may return."""
    lines: list[str] = []

    for result in results:
        data = result

        if hasattr(result, "json"):
            data = result.json
            if callable(data):
                data = data()

        if isinstance(data, str):
            try:
                data = json.loads(data)
            except ValueError:
                continue

        payload = data.get("res", data) if isinstance(data, dict) else {}
        texts = payload.get("rec_texts", []) if isinstance(payload, dict) else []

        lines.extend(
            text.strip()
            for text in texts
            if isinstance(text, str) and text.strip()
        )

    return lines


def extract_text(image_bytes: bytes, suffix: str = ".png") -> str:
    suffix = suffix if suffix.startswith(".") else "." + suffix

    normalised = _as_png(image_bytes)

    if normalised is not None:
        image_bytes = normalised
        suffix = ".png"

    # delete=False so PaddleOCR can reopen the closed file on Windows.
    with NamedTemporaryFile(suffix=suffix, delete=False) as temp:
        temp.write(image_bytes)
        path = temp.name

    try:
        with _lock:
            results = _engine().predict(path)

        return "\n".join(_lines(results))
    finally:
        try:
            os.unlink(path)
        except OSError:  # pragma: no cover - already gone or locked by the OS
            pass
