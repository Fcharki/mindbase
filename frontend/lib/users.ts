import { api } from "./api";
import type { WorkspaceRole } from "./workspaces";

export interface UserSearchResult {
  id: number;
  username: string;
  bio: string | null;
  location: string | null;
  photo_url: string | null;
  owned_workspaces_count: number;
}

export interface PublicWorkspace {
  id: number;
  name: string;
  entries_count: number;
  role: WorkspaceRole;
  is_member: boolean;
  join_request_status: "pending" | null;
  created_at: string;
}

export interface PublicUser {
  id: number;
  username: string;
  bio: string | null;
  location: string | null;
  photo_url: string | null;
  cover_url: string | null;
  created_at: string;
  owned_workspaces: PublicWorkspace[];
}

interface ResourceResponse<T> {
  data: T;
}

export async function searchUsers(query: string): Promise<UserSearchResult[]> {
  const term = query.trim();

  if (!term) {
    return [];
  }

  const { data } = await api.get<ResourceResponse<UserSearchResult[]>>(
    "/users",
    { params: { q: term } },
  );

  return data.data;
}

export async function getPublicUser(username: string): Promise<PublicUser> {
  const { data } = await api.get<ResourceResponse<PublicUser>>(
    `/users/${encodeURIComponent(username)}`,
  );

  return data.data;
}
