import { EntryDetailPageView } from "./EntryDetailPageView";

export default async function EntryPage({
  params,
}: {
  params: Promise<{ id: string; entryId: string }>;
}) {
  const { id, entryId } = await params;

  return (
    <EntryDetailPageView workspaceId={id} entryId={entryId} />
  );
}