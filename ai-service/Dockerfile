FROM python:3.11-slim

WORKDIR /srv

# libgl1 and libglib2.0-0 are OpenCV's runtime dependencies; PaddleOCR imports
# opencv through paddlex, which fails outright without them.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libgomp1 libgl1 libglib2.0-0 curl \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .

# Pinned with +cpu from the PyTorch index rather than left to requirements.txt.
# PyPI's plain `torch` resolves to a CUDA build that adds several gigabytes to
# the image and buys nothing on a CPU-only service, and --extra-index-url does
# not prevent that: pip treats both indexes as equal candidates.
RUN pip install --no-cache-dir torch==2.14.0+cpu --index-url https://download.pytorch.org/whl/cpu \
    && pip install --no-cache-dir -r requirements.txt

COPY app ./app

EXPOSE 8002

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8002"]