import { api } from "./api";

export interface EntryCreator {
  id: number;
  username: string;
}

export interface EntryTag {
  id: number;
  name: string;
  workspace_id: number;
}

export interface KnowledgeEntry {
  id: number;
  title: string;
  content: string | null;
  created_by: number;
  workspace_id: number;
  is_pinned?: boolean;
  created_at: string;
  updated_at: string;
  creator?: EntryCreator;
  tags?: EntryTag[];
  tags_count?: number;
}

export type BulkAction = "delete" | "tag" | "untag" | "pin" | "unpin";

export interface BulkResult {
  action: BulkAction;
  requested: number;
  affected: number;
  // Ids that were not found in the workspace, so a partial success is visible.
  missing: number[];
}

interface ResourceResponse<T> {
  data: T;
}

export async function listEntries(
  workspaceId: number | string,
): Promise<KnowledgeEntry[]> {
  const { data } = await api.get<ResourceResponse<KnowledgeEntry[]>>(
    `/workspaces/${workspaceId}/entries`,
  );

  return data.data;
}

export async function getEntry(
  workspaceId: number | string,
  entryId: number | string,
): Promise<KnowledgeEntry> {
  const { data } = await api.get<ResourceResponse<KnowledgeEntry>>(
    `/workspaces/${workspaceId}/entries/${entryId}`,
  );

  return data.data;
}

export async function createEntry(
  workspaceId: number | string,
  title: string,
  content: string,
): Promise<KnowledgeEntry> {
  const { data } = await api.post<ResourceResponse<KnowledgeEntry>>(
    `/workspaces/${workspaceId}/entries`,
    { title, content },
  );

  return data.data;
}

export async function updateEntry(
  workspaceId: number | string,
  entryId: number | string,
  title: string,
  content: string,
): Promise<KnowledgeEntry> {
  const { data } = await api.put<ResourceResponse<KnowledgeEntry>>(
    `/workspaces/${workspaceId}/entries/${entryId}`,
    { title, content },
  );

  return data.data;
}

export async function deleteEntry(
  workspaceId: number | string,
  entryId: number | string,
): Promise<void> {
  await api.delete(`/workspaces/${workspaceId}/entries/${entryId}`);
}

export async function tagEntry(
  workspaceId: number | string,
  entryId: number | string,
  tags: string[],
): Promise<EntryTag[]> {
  const { data } = await api.post<ResourceResponse<EntryTag[]>>(
    `/workspaces/${workspaceId}/entries/${entryId}/tags`,
    { tags },
  );

  return data.data;
}

interface SummarizeResponse {
  data: {
    summary: string;
  };
}

export async function summarizeEntry(
  workspaceId: number | string,
  entryId: number | string,
): Promise<string> {
  const { data } = await api.post<SummarizeResponse>(
    `/workspaces/${workspaceId}/entries/${entryId}/summarize`,
  );

  return data.data.summary;
}

/**
 * Pin an entry to the top of the list, or pass false to release it. Omitting
 * the flag toggles, which is what a single button wants.
 */
export async function pinEntry(
  workspaceId: number | string,
  entryId: number | string,
  isPinned?: boolean,
): Promise<KnowledgeEntry> {
  const { data } = await api.post<ResourceResponse<KnowledgeEntry>>(
    `/workspaces/${workspaceId}/entries/${entryId}/pin`,
    isPinned === undefined ? {} : { is_pinned: isPinned },
  );

  return data.data;
}

/**
 * Apply one action to a selection of entries in a single request.
 */
export async function bulkManageEntries(
  workspaceId: number | string,
  entryIds: number[],
  action: BulkAction,
  tags: string[] = [],
): Promise<BulkResult> {
  const { data } = await api.post<ResourceResponse<BulkResult>>(
    `/workspaces/${workspaceId}/entries/bulk`,
    { entry_ids: entryIds, action, tags },
  );

  return data.data;
}

/**
 * Tag names the workspace vocabulary suggests for this entry.
 */
export async function suggestEntryTags(
  workspaceId: number | string,
  entryId: number | string,
): Promise<string[]> {
  const { data } = await api.post<
    ResourceResponse<{ suggestions: string[] }>
  >(`/workspaces/${workspaceId}/entries/${entryId}/suggest-tags`);

  return data.data.suggestions;
}

export type ExportFormat = "md" | "html" | "json" | "pdf";

const EXPORT_EXTENSIONS: Record<ExportFormat, string> = {
  md: "md",
  html: "html",
  json: "json",
  pdf: "pdf",
};

/**
 * Download one entry through axios, so the Sanctum cookie is always sent.
 * Unlike a plain link this also gives us the server's filename.
 */
export async function exportEntry(
  workspaceId: number | string,
  entry: KnowledgeEntry,
  format: ExportFormat = "md",
): Promise<void> {
  const response = await api.get(
    `/workspaces/${workspaceId}/entries/${entry.id}/export`,
    { params: { format }, responseType: "blob" },
  );

  const url = URL.createObjectURL(response.data as Blob);
  const link = window.document.createElement("a");

  link.href = url;
  link.download = `${slugify(entry.title) || "entry"}.${EXPORT_EXTENSIONS[format]}`;

  window.document.body.appendChild(link);
  link.click();
  link.remove();

  URL.revokeObjectURL(url);
}

function slugify(value: string): string {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}