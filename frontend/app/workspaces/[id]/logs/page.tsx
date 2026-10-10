import { AuditLogsView } from "./AuditLogsView";

export default async function WorkspaceLogsPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  return <AuditLogsView workspaceId={id} />;
}
