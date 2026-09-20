import { api } from "./api";

export interface EntryCreator {
  id: number;
  name: string;
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
  created_at: string;
  updated_at: string;
  creator?: EntryCreator;
  tags?: EntryTag[];
  tags_count?: number;
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