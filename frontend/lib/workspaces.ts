import { api } from "./api";

export type WorkspaceRole = "owner" | "admin" | "member";

export interface WorkspaceOwner {
  id: number;
  name: string;
  email: string;
}

export interface WorkspaceMember {
  id: number;
  name: string;
  email: string;
  pivot: {
    role: WorkspaceRole;
  };
}

export interface Workspace {
  id: number;
  name: string;
  owner_id: number;
  created_at: string;
  updated_at: string;
  role: WorkspaceRole | null;
  owner: WorkspaceOwner;
  members?: WorkspaceMember[];
}

interface ResourceResponse<T> {
  data: T;
}

export async function listWorkspaces(): Promise<Workspace[]> {
  const { data } = await api.get<ResourceResponse<Workspace[]>>(
    "/workspaces",
  );

  return data.data;
}

export async function getWorkspace(
  id: number | string,
): Promise<Workspace> {
  const { data } = await api.get<ResourceResponse<Workspace>>(
    `/workspaces/${id}`,
  );

  return data.data;
}

export async function createWorkspace(
  name: string,
): Promise<Workspace> {
  const { data } = await api.post<ResourceResponse<Workspace>>(
    "/workspaces",
    { name },
  );

  return data.data;
}

export async function updateWorkspace(
  id: number | string,
  name: string,
): Promise<Workspace> {
  const { data } = await api.put<ResourceResponse<Workspace>>(
    `/workspaces/${id}`,
    { name },
  );

  return data.data;
}

export async function deleteWorkspace(
  id: number | string,
): Promise<void> {
  await api.delete(`/workspaces/${id}`);
}