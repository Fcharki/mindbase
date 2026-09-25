import { api } from "./api";
import type { KnowledgeEntry } from "./entries";

export interface SearchTag {
  id: number;
  name: string;
  workspace_id: number;
  entries_count: number;
}

export interface SearchResults {
  query: string;
  entries: KnowledgeEntry[];
  tags: SearchTag[];
}

export async function searchWorkspace(
  workspaceId: number | string,
  q: string,
  tag?: string,
): Promise<SearchResults> {
  const { data } = await api.get<{ data: SearchResults }>(
    `/workspaces/${workspaceId}/search`,
    { params: { q, tag: tag || undefined } },
  );

  return data.data;
}