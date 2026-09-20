import { api } from "./api";

export type WorkspaceRole = "owner" | "member" | "viewer";

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

export type MembershipRequestStatus =
  | "pending"
  | "approved"
  | "rejected";

export interface MembershipRequest {
  id: number;
  workspace_id: number;
  user_id: number;
  status: MembershipRequestStatus;
  requested_role: WorkspaceRole;
  created_at: string;
  user?: {
    id: number;
    name: string;
    email: string;
  };
}

export interface Workspace {
  id: number;
  name: string;
  invite_code: string | null;
  owner_id: number;
  created_at: string;
  updated_at: string;
  role: WorkspaceRole | null;
  owner: WorkspaceOwner;
  members?: WorkspaceMember[];
}

export interface JoinableWorkspace {
  id: number;
  name: string;
  invite_code: string;
  owner: {
    id: number;
    name: string;
  };
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

export async function getWorkspaceByInviteCode(
  inviteCode: string,
): Promise<JoinableWorkspace> {
  const { data } = await api.get<ResourceResponse<JoinableWorkspace>>(
    `/join/${inviteCode}`,
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

export async function addWorkspaceMember(
  workspaceId: number | string,
  email: string,
  role: WorkspaceRole,
): Promise<WorkspaceMember> {
  const { data } = await api.post<ResourceResponse<WorkspaceMember>>(
    `/workspaces/${workspaceId}/members`,
    { email, role },
  );

  return data.data;
}

export async function updateWorkspaceMemberRole(
  workspaceId: number | string,
  userId: number,
  role: WorkspaceRole,
): Promise<WorkspaceMember> {
  const { data } = await api.patch<ResourceResponse<WorkspaceMember>>(
    `/workspaces/${workspaceId}/members/${userId}`,
    { role },
  );

  return data.data;
}

export async function removeWorkspaceMember(
  workspaceId: number | string,
  userId: number,
): Promise<void> {
  await api.delete(`/workspaces/${workspaceId}/members/${userId}`);
}

export async function requestJoin(
  workspaceId: number | string,
  role?: WorkspaceRole,
): Promise<void> {
  await api.post(`/workspaces/${workspaceId}/join-requests`, {
    role,
  });
}

export async function listJoinRequests(
  workspaceId: number | string,
): Promise<MembershipRequest[]> {
  const { data } = await api.get<
    ResourceResponse<MembershipRequest[]>
  >(`/workspaces/${workspaceId}/join-requests`);

  return data.data;
}

export async function approveJoinRequest(
  workspaceId: number | string,
  requestId: number,
  role?: WorkspaceRole,
): Promise<void> {
  await api.post(
    `/workspaces/${workspaceId}/join-requests/${requestId}/approve`,
    { role },
  );
}

export async function rejectJoinRequest(
  workspaceId: number | string,
  requestId: number,
): Promise<void> {
  await api.delete(
    `/workspaces/${workspaceId}/join-requests/${requestId}`,
  );
}