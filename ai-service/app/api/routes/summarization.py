from fastapi import APIRouter
from pydantic import BaseModel

from app.services.summarizer import model_service


router = APIRouter()


class SummarizeRequest(BaseModel):
    prompt: str


class SummarizeResponse(BaseModel):
    response: str


@router.post("/summarize", response_model=SummarizeResponse)
def chat(request: SummarizeRequest):
    response = model_service.generate(request.prompt)

    return {
        "response": response
    }