"use client";

import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type FormEvent,
} from "react";
import Link from "next/link";
import { toast } from "sonner";
import { ThumbsDown, ThumbsUp } from "lucide-react";

import {
  askErrorMessage,
  askWorkspace,
  fetchAssistantMessage,
  fetchAssistantMessages,
  regenerateAssistantMessage,
  setAssistantFeedback,
  streamAssistantMessage,
  ASK_POLL_INTERVAL_MS,
  ASK_POLL_LIMIT_MS,
  ASK_TOP_K_DEFAULT,
  ASK_TOP_K_MAX,
  ASK_TOP_K_MIN,
  type AskAnswer,
  type AskFeedback,
  type AskSource,
} from "@/lib/llm";

interface Turn {
  // The answer row's id. Every turn has one as soon as it is accepted, and the
  // same key survives a regenerate because the row is reused.
  id: number;
  question: string;
  answer: AskAnswer | null;
  // Text shown while the answer is still being written. The row's own `answer`
  // is only authoritative once the job reports ready.
  streamed: string;
  streaming: boolean;
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

function toTurn(message: AskAnswer): Turn {
  return {
    id: message.id,
    question: message.question,
    answer: message,
    streamed: message.answer ?? "",
    streaming: false,
    error: null,
  };
}

export function AssistantPanel({
  workspaceId,
}: {
  workspaceId: number | string;
}) {
  const [turns, setTurns] = useState<Turn[]>([]);
  const [question, setQuestion] = useState("");
  const [pending, setPending] = useState(false);
  const [loadingHistory, setLoadingHistory] = useState(true);
  const [waited, setWaited] = useState(0);
  const [topK, setTopK] = useState(ASK_TOP_K_DEFAULT);
  const [rewrite, setRewrite] = useState(false);
  const [activeSource, setActiveSource] = useState<AskSource | null>(null);
  const endRef = useRef<HTMLDivElement | null>(null);

  // One generation at a time: the model is slow and the answers are read in
  // order, so a second concurrent stream would only make both harder to follow.
  const stopStream = useRef<(() => void) | null>(null);
  const startedAt = useRef<number>(0);

  const busy = pending;

  useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: "smooth", block: "end" });
  }, [turns, pending, waited]);

  // Stop tailing when the panel goes away, so a closed tab does not keep a
  // connection open on the server for the rest of the answer's budget.
  useEffect(() => {
    return () => {
      stopStream.current?.();
    };
  }, []);

  useEffect(() => {
    let cancelled = false;

    fetchAssistantMessages(workspaceId)
      .then((messages) => {
        if (cancelled) {
          return;
        }

        // The endpoint returns newest first; the thread reads oldest first.
        setTurns(messages.slice().reverse().map(toTurn));
      })
      .catch(() => {
        // An empty history is a fine outcome; do not block the panel on it.
      })
      .finally(() => {
        if (!cancelled) {
          setLoadingHistory(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [workspaceId]);

  const patchTurn = useCallback((id: number, update: Partial<Turn>) => {
    setTurns((current) =>
      current.map((turn) => (turn.id === id ? { ...turn, ...update } : turn)),
    );
  }, []);

  /**
   * Refresh one answer from the row once the job reports it is done, so the
   * sources and the authoritative text come from the server rather than the
   * deltas we happened to receive.
   */
  const settle = useCallback(
    async (id: number) => {
      try {
        const message = await fetchAssistantMessage(workspaceId, id);

        patchTurn(id, {
          answer: message,
          streamed: message.answer ?? "",
          streaming: false,
          error:
            message.status === "failed"
              ? message.error ?? "The AI service could not answer that."
              : null,
        });
      } catch {
        patchTurn(id, {
          streaming: false,
          error: "The answer landed, but it could not be read back.",
        });
      } finally {
        stopStream.current = null;
        setPending(false);
      }
    },
    [patchTurn, workspaceId],
  );

  const poll = useCallback(
    async (id: number, beganAt: number) => {
      const deadline = beganAt + ASK_POLL_LIMIT_MS;

      const tick = async () => {
        try {
          const message = await fetchAssistantMessage(workspaceId, id);

          if (message.status === "ready") {
            patchTurn(id, {
              answer: message,
              streamed: message.answer ?? "",
              streaming: false,
              error: null,
            });
            setPending(false);

            return;
          }

          if (message.status === "failed") {
            patchTurn(id, {
              answer: message,
              streaming: false,
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

          setWaited(Date.now() - beganAt);
          setTimeout(tick, ASK_POLL_INTERVAL_MS);
        } catch (error) {
          patchTurn(id, { streaming: false, error: askErrorMessage(error) });
          setPending(false);
        }
      };

      await tick();
    },
    [patchTurn, workspaceId],
  );

  /**
   * Tail a pending answer, falling back to polling if the stream cannot be
   * opened or dies before the answer is ready.
   */
  const tail = useCallback(
    (id: number) => {
      stopStream.current?.();
      startedAt.current = Date.now();
      setWaited(0);

      stopStream.current = streamAssistantMessage(workspaceId, id, {
        onDelta: (text) => patchTurn(id, { streamed: text, streaming: true }),
        onDone: () => {
          void settle(id);
        },
        onError: (message) => {
          stopStream.current = null;
          // The stream is only a faster view of the same row, so a drop is not
          // fatal: polling reads the row directly.
          toast.message("Live updates stopped", { description: message });
          void poll(id, startedAt.current);
        },
      });
    },
    [patchTurn, poll, settle, workspaceId],
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
      const message = await askWorkspace(workspaceId, trimmed, {
        top_k: topK,
        rewrite,
      });

      setTurns((current) => [...current, toTurn(message)]);

      // Only a question that is still pending needs watching. A failure already
      // carries its own explanation.
      if (message.status === "pending") {
        tail(message.id);
      } else {
        setPending(false);
      }
    } catch (error) {
      const text = askErrorMessage(error);
      toast.error(text);
      setTurns((current) => [
        ...current,
        {
          id: -Date.now(),
          question: trimmed,
          answer: null,
          streamed: "",
          streaming: false,
          error: text,
        },
      ]);
      setPending(false);
    }
  }

  async function handleRegenerate(turn: Turn) {
    if (busy || !turn.answer) {
      return;
    }

    stopStream.current?.();
    setPending(true);
    patchTurn(turn.id, {
      answer: { ...turn.answer, answer: null, status: "pending" },
      streamed: "",
      streaming: false,
      error: null,
    });

    try {
      const message = await regenerateAssistantMessage(workspaceId, turn.id, {
        top_k: topK,
        rewrite,
      });

      patchTurn(turn.id, { answer: message });
      tail(turn.id);
    } catch (error) {
      const text = askErrorMessage(error);
      toast.error(text);
      patchTurn(turn.id, { streaming: false, error: text });
      setPending(false);
    }
  }

  async function handleFeedback(turn: Turn, value: AskFeedback) {
    const previous = turn.answer?.feedback ?? null;
    const next = previous === value ? null : value;

    // Optimistic, because the vote is cosmetic next to the answer itself.
    patchTurn(turn.id, {
      answer: turn.answer ? { ...turn.answer, feedback: next } : turn.answer,
    });

    try {
      await setAssistantFeedback(workspaceId, turn.id, next);
    } catch {
      patchTurn(turn.id, {
        answer: turn.answer ? { ...turn.answer, feedback: previous } : turn.answer,
      });
      toast.error("Could not record your feedback.");
    }
  }

  async function copyAnswer(text: string) {
    try {
      await navigator.clipboard.writeText(text);
      toast.success("Answer copied.");
    } catch {
      toast.error("Could not copy the answer.");
    }
  }

  function handleClear() {
    stopStream.current?.();
    setTurns([]);
    setQuestion("");
    setPending(false);
    setWaited(0);
  }

  return (
    <section className="rounded-2xl border border-[#D7D5DE] bg-white shadow-sm dark:border-[#34343c] dark:bg-[#1b1b1f]">
      <div className="flex items-center justify-between border-b border-[#D7D5DE] px-7 py-5 sm:px-8 dark:border-[#34343c]">
        <div>
          <p className="text-sm font-semibold uppercase tracking-[0.1em] text-[#5146E5] dark:text-[#a5b4fc]">
            Assistant
          </p>

          <h2 className="mt-1 text-xl font-semibold tracking-tight dark:text-[#f5f5f5]">
            Ask about this workspace
          </h2>
        </div>

        {turns.length > 0 && (
          <button
            type="button"
            onClick={handleClear}
            className="text-sm font-medium text-[#5146E5] transition hover:text-[#4338CA] dark:text-[#a5b4fc]"
          >
            Clear
          </button>
        )}
      </div>

      <div className="px-7 py-5 sm:px-8">
        {loadingHistory ? (
          <div className="flex flex-col gap-3" aria-hidden="true">
            <div className="h-4 w-1/3 animate-pulse rounded bg-[#E7E6ED] dark:bg-[#2c2c33]" />
            <div className="h-20 animate-pulse rounded-lg bg-[#F2F0EA] dark:bg-[#24242a]" />
          </div>
        ) : turns.length === 0 ? (
          <p className="text-sm leading-relaxed text-[#686873] dark:text-[#b0b0b9]">
            Ask a question and it will be answered from the entries in this
            workspace. Answers cite the passages they came from. The first answer
            can take a few minutes while the model loads.
          </p>
        ) : (
          <div className="flex flex-col gap-5">
            {turns.map((turn) => {
              const stillWorking = turn.streaming || (pending && turn.answer?.status === "pending");
              const text = turn.streamed || turn.answer?.answer || "";
              const ready = turn.answer?.status === "ready" && text !== "";

              return (
                <div key={turn.id} className="flex flex-col gap-3">
                  <p className="text-sm font-medium text-[#20202A] dark:text-[#ececf1]">
                    {turn.question}
                  </p>

                  {stillWorking && (
                    <p className="flex flex-wrap items-center gap-2 text-sm text-[#686873] dark:text-[#b0b0b9]">
                      <span className="h-2 w-2 animate-pulse rounded-full bg-[#5146E5]" />
                      {text
                        ? `Writing the answer - ${elapsedLabel(waited)} so far.`
                        : `Working on it - ${elapsedLabel(waited)} so far.`}{" "}
                      You can leave this open or come back later.
                    </p>
                  )}

                  {turn.error && (
                    <p
                      role="alert"
                      className="rounded-lg border border-[#F2C9D1] bg-[#FCECEF] px-4 py-3 text-sm leading-relaxed text-[#B13D55] dark:border-[#5a2b36] dark:bg-[#2a161a] dark:text-[#f3a7b6]"
                    >
                      {turn.error}
                    </p>
                  )}

                  {text && (
                    <div className="rounded-lg border border-[#E7E6ED] bg-[#F2F0EA] px-4 py-3 dark:border-[#34343c] dark:bg-[#24242a]">
                      <p className="whitespace-pre-wrap text-sm leading-relaxed text-[#20202A] dark:text-[#ececf1]">
                        {text}
                      </p>

                      {turn.answer?.sources && turn.answer.sources.length > 0 && (
                        <details className="mt-3 border-t border-[#D7D5DE] pt-3 dark:border-[#34343c]">
                          <summary className="cursor-pointer text-xs font-medium text-[#686873] dark:text-[#b0b0b9]">
                            {turn.answer.sources.length}{" "}
                            {turn.answer.sources.length === 1
                              ? "source"
                              : "sources"}
                          </summary>

                          <ul className="mt-2 flex flex-col gap-2">
                            {turn.answer.sources.map((source, sourceIndex) => (
                              <li key={sourceIndex}>
                                <button
                                  type="button"
                                  onClick={() => setActiveSource(source)}
                                  className="w-full rounded-md px-2 py-1.5 text-left text-xs leading-relaxed text-[#686873] transition hover:bg-[#E7E6ED] dark:text-[#b0b0b9] dark:hover:bg-[#2c2c33]"
                                >
                                  <span className="font-medium text-[#20202A] dark:text-[#ececf1]">
                                    {source.title ?? "Untitled entry"}
                                  </span>
                                  {" - "}
                                  {source.body.length > 160
                                    ? `${source.body.slice(0, 160)}…`
                                    : source.body}
                                </button>
                              </li>
                            ))}
                          </ul>
                        </details>
                      )}

                      {ready && (
                        <div className="mt-3 flex items-center gap-1 border-t border-[#D7D5DE] pt-3 dark:border-[#34343c]">
                          <button
                            type="button"
                            onClick={() => copyAnswer(text)}
                            className="rounded-md px-2 py-1 text-xs font-medium text-[#686873] transition hover:bg-[#E7E6ED] hover:text-[#20202A] dark:text-[#b0b0b9] dark:hover:bg-[#2c2c33]"
                          >
                            Copy
                          </button>

                          <button
                            type="button"
                            onClick={() => handleRegenerate(turn)}
                            disabled={busy}
                            className="rounded-md px-2 py-1 text-xs font-medium text-[#686873] transition hover:bg-[#E7E6ED] hover:text-[#20202A] disabled:cursor-not-allowed disabled:opacity-40 dark:text-[#b0b0b9] dark:hover:bg-[#2c2c33]"
                          >
                            Regenerate
                          </button>

                          <span className="mx-1 h-4 w-px bg-[#D7D5DE] dark:bg-[#34343c]" />

                          <button
                            type="button"
                            onClick={() => handleFeedback(turn, "up")}
                            aria-label="This answer was helpful"
                            aria-pressed={turn.answer?.feedback === "up"}
                            title="Helpful"
                            className={`rounded-md p-1.5 transition hover:bg-[#E7E6ED] dark:hover:bg-[#2c2c33] ${
                              turn.answer?.feedback === "up"
                                ? "text-[#159A9C] dark:text-[#3ac6c8]"
                                : "text-[#9897A1] dark:text-[#8b8b94]"
                            }`}
                          >
                            <ThumbsUp className="h-4 w-4" aria-hidden="true" />
                          </button>

                          <button
                            type="button"
                            onClick={() => handleFeedback(turn, "down")}
                            aria-label="This answer was not helpful"
                            aria-pressed={turn.answer?.feedback === "down"}
                            title="Not helpful"
                            className={`rounded-md p-1.5 transition hover:bg-[#E7E6ED] dark:hover:bg-[#2c2c33] ${
                              turn.answer?.feedback === "down"
                                ? "text-[#B13D55] dark:text-[#f3a7b6]"
                                : "text-[#9897A1] dark:text-[#8b8b94]"
                            }`}
                          >
                            <ThumbsDown className="h-4 w-4" aria-hidden="true" />
                          </button>
                        </div>
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
            className="w-full rounded-lg border border-[#D7D5DE] bg-white px-4 py-3 text-sm text-[#20202A] outline-none transition placeholder:text-[#A3A2AC] focus:border-[#5146E5] focus:ring-2 focus:ring-[#EEEDFF] dark:focus:ring-[#32324a] disabled:opacity-60 dark:border-[#34343c] dark:bg-[#1b1b1f] dark:text-[#ececf1] dark:placeholder:text-[#8b8b94]"
          />

          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex flex-wrap items-center gap-4">
              <label className="flex items-center gap-2 text-xs font-medium text-[#686873] dark:text-[#b0b0b9]">
                Passages
                <input
                  type="number"
                  min={ASK_TOP_K_MIN}
                  max={ASK_TOP_K_MAX}
                  value={topK}
                  onChange={(event) =>
                    setTopK(
                      Math.min(
                        ASK_TOP_K_MAX,
                        Math.max(ASK_TOP_K_MIN, Number(event.target.value) || ASK_TOP_K_DEFAULT),
                      ),
                    )
                  }
                  disabled={busy}
                  className="w-16 rounded-md border border-[#D7D5DE] bg-white px-2 py-1 text-sm text-[#20202A] outline-none focus:border-[#5146E5] disabled:opacity-60 dark:border-[#34343c] dark:bg-[#1b1b1f] dark:text-[#ececf1]"
                />
              </label>

              <label className="flex items-center gap-2 text-xs font-medium text-[#686873] dark:text-[#b0b0b9]">
                <input
                  type="checkbox"
                  checked={rewrite}
                  onChange={(event) => setRewrite(event.target.checked)}
                  disabled={busy}
                  className="h-4 w-4 rounded border-[#D7D5DE] text-[#5146E5] focus:ring-[#EEEDFF] dark:focus:ring-[#32324a] dark:border-[#34343c]"
                />
                Rewrite query when nothing matches
              </label>
            </div>

            <button
              type="submit"
              disabled={busy || question.trim().length === 0}
              className="self-end rounded-lg bg-[#5146E5] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[#4338CA] disabled:cursor-not-allowed disabled:opacity-50 sm:self-auto"
            >
              {busy ? "Asking…" : "Ask"}
            </button>
          </div>
        </form>
      </div>

      {activeSource && (
        <div
          role="dialog"
          aria-modal="true"
          aria-label={`Source: ${activeSource.title ?? "Untitled entry"}`}
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
          onClick={() => setActiveSource(null)}
        >
          <div
            className="flex max-h-[85vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-xl dark:bg-[#1b1b1f]"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="flex items-center justify-between gap-4 border-b border-[#D7D5DE] px-5 py-3 dark:border-[#34343c]">
              <div className="min-w-0">
                <p className="truncate text-sm font-medium text-[#20202A] dark:text-[#ececf1]">
                  {activeSource.title ?? "Untitled entry"}
                </p>

                {activeSource.position !== undefined && (
                  <p className="mt-0.5 text-xs text-[#9897A1] dark:text-[#8b8b94]">
                    Passage {activeSource.position + 1}
                    {activeSource.found_by?.length
                      ? ` · found by ${activeSource.found_by.join(" + ")}`
                      : ""}
                  </p>
                )}
              </div>

              <button
                type="button"
                onClick={() => setActiveSource(null)}
                className="shrink-0 rounded-lg border border-[#D7D5DE] px-3 py-1.5 text-sm font-medium text-[#686873] transition hover:bg-[#F2F0EA] hover:text-[#20202A] dark:border-[#34343c] dark:text-[#b0b0b9] dark:hover:bg-[#24242a]"
              >
                Close
              </button>
            </div>

            <div className="min-h-0 flex-1 overflow-auto px-5 py-4">
              <p className="whitespace-pre-wrap text-sm leading-relaxed text-[#20202A] dark:text-[#ececf1]">
                {activeSource.body}
              </p>
            </div>

            <div className="flex justify-end border-t border-[#D7D5DE] px-5 py-3 dark:border-[#34343c]">
              <Link
                href={`/workspaces/${workspaceId}/entries/${activeSource.entry_id}`}
                className="rounded-lg bg-[#5146E5] px-4 py-2 text-sm font-medium text-white transition hover:bg-[#4338CA]"
              >
                Open entry
              </Link>
            </div>
          </div>
        </div>
      )}
    </section>
  );
}
