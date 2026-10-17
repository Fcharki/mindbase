import { api } from "./api";

export interface WorkspaceTag {
  id: number;
  name: string;
  workspace_id: number;
  parent_id: number | null;
  entries_count?: number;
}

interface ResourceResponse<T> {
  data: T;
}

export async function listTags(
  workspaceId: number | string,
): Promise<WorkspaceTag[]> {
  const { data } = await api.get<ResourceResponse<WorkspaceTag[]>>(
    `/workspaces/${workspaceId}/tags`,
  );

  return data.data;
}

/**
 * Change a tag's name or where it sits in the tree. Passing parent_id: null
 * makes it a root tag again.
 */
export async function updateTag(
  workspaceId: number | string,
  tagId: number | string,
  changes: { name?: string; parent_id?: number | null },
): Promise<WorkspaceTag> {
  const { data } = await api.patch<ResourceResponse<WorkspaceTag>>(
    `/workspaces/${workspaceId}/tags/${tagId}`,
    changes,
  );

  return data.data;
}
