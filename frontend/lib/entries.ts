import { api } from "./api";

export interface EntryCreator {
  id: number;
  name: string;
}

export interface KnowledgeEntry {
  id: number;
  title: string;
  content: string | null;
  created_by: number;
  workspace_id: number;
  created_at: string;
  updated_at: string;
  creator?: EntryCreator;
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