import { NewEntryPageView } from "./NewEntryPageView";

export default async function NewEntryPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  return <NewEntryPageView workspaceId={id} />;
}