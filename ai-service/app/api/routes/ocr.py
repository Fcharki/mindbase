import os
from pathlib import Path

from fastapi import APIRouter, File, HTTPException, UploadFile
from pydantic import BaseModel

from app.ocr import extract_text


router = APIRouter()

# The backend caps document uploads at 20 MB and entry images at 10 MB; this is
# a second line of defence for direct callers, not the client-facing limit.
MAX_IMAGE_BYTES = int(os.getenv("OCR_MAX_IMAGE_BYTES", str(20 * 1024 * 1024)))

ALLOWED_SUFFIXES = {".jpg", ".jpeg", ".png", ".gif", ".webp", ".bmp", ".tif", ".tiff"}


class OcrResponse(BaseModel):
    text: str


@router.post("/ocr", response_model=OcrResponse)
def ocr(file: UploadFile = File(...)):
    """Read text from a raster image. Sync so FastAPI uses its threadpool."""
    suffix = Path(file.filename or "image.png").suffix.lower()

    if suffix not in ALLOWED_SUFFIXES:
        raise HTTPException(
            status_code=415,
            detail=f"OCR does not handle {suffix or 'files without an extension'}.",
        )

    # Read one byte past the cap so an oversized upload is detected without
    # buffering the whole thing.
    contents = file.file.read(MAX_IMAGE_BYTES + 1)

    if not contents:
        raise HTTPException(status_code=400, detail="The image is empty.")

    if len(contents) > MAX_IMAGE_BYTES:
        raise HTTPException(
            status_code=413,
            detail="The image is larger than the OCR limit.",
        )

    try:
        return {"text": extract_text(contents, suffix)}
    except Exception as error:  # noqa: BLE001 - reported to the caller
        raise HTTPException(
            status_code=500,
            detail=f"OCR failed: {error}",
        ) from error
