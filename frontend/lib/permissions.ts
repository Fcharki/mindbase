import type { WorkspaceRole } from "./workspaces";

/**
 * What a user may do in a workspace, derived from their role.
 *
 * This mirrors backend/app/Services/WorkspacePermissions.php. The server is
 * still the authority — these flags only decide what to render — but keeping the
 * two in step means the UI never offers an action the API will refuse, and never
 * hides one it will allow.
 */
export interface WorkspacePermissions {
  isOwner: boolean;
  isMember: boolean;
  /** Create or change workspace content: entries, tags, document uploads. */
  canContribute: boolean;
  /** Start a discussion thread, or reply inside one. Viewers can. */
  canDiscuss: boolean;
  /** Retrieve the stored bytes behind a document. Viewers cannot. */
  canDownload: boolean;
  /** Remove content belonging to other people, for moderation. */
  canModerate: boolean;
}

const MATRIX: Record<WorkspaceRole, WorkspacePermissions> = {
  owner: {
    isOwner: true,
    isMember: true,
    canContribute: true,
    canDiscuss: true,
    canDownload: true,
    canModerate: true,
  },
  member: {
    isOwner: false,
    isMember: true,
    canContribute: true,
    canDiscuss: true,
    canDownload: true,
    canModerate: false,
  },
  viewer: {
    isOwner: false,
    isMember: true,
    canContribute: false,
    canDiscuss: true,
    canDownload: false,
    canModerate: false,
  },
};

const NO_ACCESS: WorkspacePermissions = {
  isOwner: false,
  isMember: false,
  canContribute: false,
  canDiscuss: false,
  canDownload: false,
  canModerate: false,
};

/**
 * Resolve the permissions for a role.
 *
 * `isMember` is passed separately from the role because the API reports a
 * stranger as role "viewer" too. Without it a non-member is indistinguishable
 * from a real viewer, which is exactly how a non-member ends up staring at a
 * 403 with no explanation.
 */
export function permissionsFor(
  role: WorkspaceRole | undefined,
  isMember = true,
): WorkspacePermissions {
  if (!role || !isMember) {
    return NO_ACCESS;
  }

  return MATRIX[role];
}