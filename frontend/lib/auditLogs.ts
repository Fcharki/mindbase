import { api } from "./api";

export interface AuditLogUser {
  id: number;
  username: string;
  email: string;
}

export interface AuditLog {
  id: number;
  action: string;
  entity_type: string;
  entity_id: number | null;
  metadata: Record<string, unknown> | null;
  ip_address: string | null;
  user_agent: string | null;
  occurred_at: string;
  created_at: string;
  updated_at: string | null;
  user?: AuditLogUser;
}

export interface AuditLogFilterOptions {
  actions: string[];
  entities: string[];
  users: AuditLogUser[];
}

export interface AuditLogPaginationMeta {
  current_page: number;
  from: number | null;
  last_page: number;
  per_page: number;
  to: number | null;
  total: number;
}

export interface AuditLogPaginationLinks {
  first: string | null;
  last: string | null;
  prev: string | null;
  next: string | null;
}

export interface PaginatedAuditLogs {
  data: AuditLog[];
  links: AuditLogPaginationLinks;
  meta: AuditLogPaginationMeta;
  filters: AuditLogFilterOptions;
}

export interface AuditLogQuery {
  page?: number;
  per_page?: number;
  action?: string;
  entity?: string;
  user_id?: number;
  date?: string;
  from?: string;
  to?: string;
}

export async function listAuditLogs(
  workspaceId: number | string,
  query: AuditLogQuery = {},
): Promise<PaginatedAuditLogs> {
  const { data } = await api.get<PaginatedAuditLogs>(
    `/workspaces/${workspaceId}/audit-logs`,
    { params: query },
  );

  return data;
}
