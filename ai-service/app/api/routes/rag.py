import json

from fastapi import APIRouter
from fastapi.responses import StreamingResponse
from pydantic import BaseModel

from app.services.llm import llm_service


router = APIRouter()


class RagRequest(BaseModel):
    prompt: str
    system: str | None = None

    # Optional cap on answer length, for short calls such as query rewriting or
    # title generation that should not be allowed to run to the default budget.
    max_new_tokens: int | None = None


class RagResponse(BaseModel):
    response: str


@router.post("/rag", response_model=RagResponse)
def chat(request: RagRequest):
    response = llm_service.generate(
        request.prompt,
        system=request.system,
        max_new_tokens=request.max_new_tokens,
    )

    return {
        "response": response
    }


@router.post("/rag/stream")
def chat_stream(request: RagRequest):
    """The same answer as /rag, written out as the model produces it.

    Server-sent events, one JSON object per frame:
        {"type": "delta", "text": "..."}   as tokens are decoded
        {"type": "done", "response": "..."} once, with the full text
        {"type": "error", "message": "..."} if generation fails

    The final frame carries the whole answer so a reader that missed a delta --
    or joined late -- still ends up with the exact same text /rag would return.
    """

    def events():
        answer = ""

        try:
            for piece in llm_service.generate_stream(
                request.prompt,
                system=request.system,
                max_new_tokens=request.max_new_tokens,
            ):
                answer += piece
                yield _frame({"type": "delta", "text": piece})
        except Exception as error:  # noqa: BLE001 - reported to the caller, not swallowed
            yield _frame({"type": "error", "message": str(error)})

            return

        yield _frame({"type": "done", "response": answer})

    return StreamingResponse(
        events(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache, no-transform",
            "X-Accel-Buffering": "no",
        },
    )


def _frame(payload: dict) -> str:
    return "data: " + json.dumps(payload, ensure_ascii=False) + "\n\n"
