from fastapi import APIRouter
from pydantic import BaseModel

from app.services.llm import llm_service


router = APIRouter()


class RagRequest(BaseModel):
    prompt: str
    system: str | None = None


class RagResponse(BaseModel):
    response: str


@router.post("/rag", response_model=RagResponse)
def chat(request: RagRequest):
    response = llm_service.generate(
        request.prompt,
        system=request.system,
    )

    return {
        "response": response
    }