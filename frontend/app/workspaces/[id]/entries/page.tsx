import { EntriesPageView } from "./EntriesPageView";

export default async function EntriesPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  return <EntriesPageView workspaceId={id} />;
}