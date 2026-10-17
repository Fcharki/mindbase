export const RECENT_VIEWED_KEY = "recentViewedEntries";

const MAX_RECENT = 20;

// The snapshot handed to useSyncExternalStore must not be a fresh array each
// call, or React re-renders forever. One cached array is kept and replaced only
// when the stored value actually changes.
const EMPTY: number[] = [];

let cache: number[] | null = null;
const listeners = new Set<() => void>();

function readFromStorage(): number[] {
  try {
    if (typeof window === "undefined") {
      return EMPTY;
    }

    const raw = localStorage.getItem(RECENT_VIEWED_KEY);

    if (!raw) {
      return EMPTY;
    }

    const parsed = JSON.parse(raw) as unknown;

    if (!Array.isArray(parsed)) {
      return EMPTY;
    }

    return parsed
      .filter((value): value is number => typeof value === "number" && !isNaN(value))
      .slice(0, MAX_RECENT);
  } catch {
    return EMPTY;
  }
}

function snapshot(): number[] {
  if (cache === null) {
    cache = readFromStorage();
  }

  return cache;
}

function emit(): void {
  listeners.forEach((listener) => listener());
}

export function getRecentViewed(): number[] {
  return snapshot();
}

export function addRecentViewed(entryId: number): void {
  try {
    if (typeof window === "undefined") {
      return;
    }

    const recent = snapshot().filter((id) => id !== entryId);
    recent.unshift(entryId);
    cache = recent.slice(0, MAX_RECENT);
    localStorage.setItem(RECENT_VIEWED_KEY, JSON.stringify(cache));
    emit();
  } catch {
    // ignore
  }
}

export function clearRecentViewed(): void {
  try {
    if (typeof window === "undefined") {
      return;
    }

    cache = EMPTY;
    localStorage.removeItem(RECENT_VIEWED_KEY);
    emit();
  } catch {
    // ignore
  }
}

/** For useSyncExternalStore; emits when the list changes in this tab. */
export function subscribeRecentViewed(listener: () => void): () => void {
  listeners.add(listener);

  return () => {
    listeners.delete(listener);
  };
}

/** The client snapshot. Stable between changes, so React can diff it. */
export function getRecentViewedSnapshot(): number[] {
  return snapshot();
}

/** The server/hydration snapshot: localStorage is not readable there. */
export function getRecentViewedServerSnapshot(): number[] {
  return EMPTY;
}
