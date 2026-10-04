"use client";

import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";

import {
  askErrorMessage,
  askWorkspace,
  fetchAssistantMessage,
  ASK_POLL_INTERVAL_MS,
  ASK_POLL_LIMIT_MS,
  type AskAnswer,
} from "@/lib/llm";

interface Turn {
  question: string;
  answer: AskAnswer | null;
  error: string | null;
}

/**
 * Elapsed time, so a slow answer reads as working rather than stuck.
 */
function elapsedLabel(milliseconds: number): string {
  const seconds = Math.max(1, Math.round(milliseconds / 1000));

  if (seconds < 60) {
    return `${seconds}s`;
  }

  const minutes = Math.floor(seconds / 60);
  const remainder = seconds % 60;

  return remainder === 0 ? `${minutes}m` : `${minutes}m ${remainder}s`;
}

export function AssistantPanel({ workspaceId }: { workspaceId: number | string }) {
  const [turns, setTurns] = useState<Turn[]>([]);
  const [question, setQuestion] = useState("");
  const [pending, setPending] = useState(false);
  const [waited, setWaited] = useState(0);
  const endRef = useRef<HTMLDivElement | null>(null);

  const busy = pending;

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: "smooth", block: "end" });
  }, [turns, pending, waited]);

  /**
   * Keyed on message id, so a slow answer lands on its own question even when
   * newer turns have been added since.
   */
  const settle = useCallback((messageId: number, update: Partial<Turn>) => {
    setTurns((current) =>
      current.map((turn) =>
        turn.answer?.id === messageId ? { ...turn, ...update } : turn,
      ),
    );
  }, []);

  const poll = useCallback(
    async (messageId: number, startedAt: number) => {
      const deadline = startedAt + ASK_POLL_LIMIT_MS;

      const tick = async () => {
        try {
          const message = await fetchAssistantMessage(workspaceId, messageId);

          if (message.status === "ready") {
            settle(messageId, { answer: message, error: null });
            setPending(false);

            return;
          }

          if (message.status === "failed") {
            settle(messageId, {
              error: message.error ?? "The AI service could not answer that.",
            });
            setPending(false);

            return;
          }

          if (Date.now() >= deadline) {
            // Left pending: the job is still running, so the answer can still land.
            // Calling it failed would be a guess.
            setPending(false);

            return;
          }

          setWaited(Date.now() - startedAt);
          setTimeout(tick, ASK_POLL_INTERVAL_MS);
        } catch (error) {
          settle(messageId, { error: askErrorMessage(error) });
          setPending(false);
        }
      };

      await tick();
    },
    [settle, workspaceId],
  );

  async function handleAsk(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    const trimmed = question.trim();

    if (!trimmed || busy) {
      return;
    }

    setQuestion("");
    setPending(true);
    setWaited(0);

    try {
      const message = await askWorkspace(workspaceId, trimmed);

      setTurns((current) => [
        ...current,
        { question: trimmed, answer: message, error: null },
      ]);

      // Only a question that is still pending needs watching. A failure already
      // carries its own explanation.
      if (message.status === "pending") {
        void poll(message.id, Date.now());
      } else {
        setPending(false);
      }
    } catch (error) {
      setTurns((current) => [
        ...current,
        { question: trimmed, answer: null, error: askErrorMessage(error) },
      ]);
      setPending(false);
    }
  }

  function handleClear() {
    setTurns([]);
    setQuestion("");
    setPending(false);
    setWaited(0);
  }

  return (
    <section className="rounded-2xl border border-[#DDDCE3] bg-white shadow-sm">
      <div className="flex items-center justify-between border-b border-[#DDDCE3] px-7 py-5 sm:px-8">
        <div>
          <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5]">
            Assistant
          </p>

          <h2 className="mt-1 text-xl font-semibold tracking-tight">
            Ask this workspace
          </h2>
        </div>

        {turns.length > 0 && (
          <button
            type="button"
            onClick={handleClear}
            className="text-sm font-medium text-[#5146E5] transition hover:text-[#4338CA]"
          >
            Clear
          </button>
        )}
      </div>

      <div className="px-7 py-5 sm:px-8">
        {turns.length === 0 ? (
          <p className="text-sm leading-relaxed text-[#686873]">
            Ask a question and it will be answered from the entries in this
            workspace. Answers cite the passages they came from. The first answer
            can take a few minutes while the model loads.
          </p>
        ) : (
          <div className="flex flex-col gap-5">
            {turns.map((turn, index) => {
              const stillWorking =
                turn.answer?.status === "pending" && busy;

              return (
                <div key={turn.answer?.id ?? index} className="flex flex-col gap-3">
                  <p className="text-sm font-medium text-[#20202A]">
                    {turn.question}
                  </p>

                  {stillWorking && (
                    <p className="flex flex-wrap items-center gap-2 text-sm text-[#686873]">
                      <span className="h-2 w-2 rounded-full bg-[#5146E5]" />
                      Working on it — {elapsedLabel(waited)} so far. You can leave
                      this open or come back later.
                    </p>
                  )}

                  {turn.error && (
                    <p
                      role="alert"
                      className="rounded-lg border border-[#F2C9D1] bg-[#FCECEF] px-4 py-3 text-sm leading-relaxed text-[#B13D55]"
                    >
                      {turn.error}
                    </p>
                  )}

                  {turn.answer?.answer && (
                    <div className="rounded-lg border border-[#EEEDEF] bg-[#F7F6F2] px-4 py-3">
                      <p className="whitespace-pre-wrap text-sm leading-relaxed text-[#20202A]">
                        {turn.answer.answer}
                      </p>

                      {turn.answer.sources.length > 0 && (
                        <details className="mt-3 border-t border-[#DDDCE3] pt-3">
                          <summary className="cursor-pointer text-xs font-medium text-[#686873]">
                            {turn.answer.sources.length}{" "}
                            {turn.answer.sources.length === 1
                              ? "source"
                              : "sources"}
                          </summary>

                          <ul className="mt-2 flex flex-col gap-2">
                            {turn.answer.sources.map((source, sourceIndex) => (
                              <li
                                key={sourceIndex}
                                className="text-xs leading-relaxed text-[#686873]"
                              >
                                <span className="font-medium text-[#20202A]">
                                  {source.title ?? "Untitled entry"}
                                </span>
                                {" — "}
                                {source.body.length > 160
                                  ? `${source.body.slice(0, 160)}…`
                                  : source.body}
                              </li>
                            ))}
                          </ul>
                        </details>
                      )}
                    </div>
                  )}
                </div>
              );
            })}

            <div ref={endRef} />
          </div>
        )}

        <form onSubmit={handleAsk} className="mt-5 flex flex-col gap-3">
          <label htmlFor="assistant-question" className="sr-only">
            Ask a question about this workspace
          </label>

          <input
            id="assistant-question"
            type="text"
            value={question}
            onChange={(event) => setQuestion(event.target.value)}
            disabled={busy}
            placeholder="How do tokens expire?"
            className="w-full rounded-lg border border-[#DDDCE3] bg-white px-4 py-3 text-sm text-[#20202A] outline-none transition placeholder:text-[#A3A2AC] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] disabled:opacity-60"
          />

          <div className="flex justify-end">
            <button
              type="submit"
              disabled={busy || question.trim().length === 0}
              className="rounded-lg bg-[#5146E5] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[#4338CA] disabled:cursor-not-allowed disabled:opacity-50"
            >
              {busy ? "Asking…" : "Ask"}
            </button>
          </div>
        </form>
      </div>
    </section>
  );
}