import { TagsPageView } from "./TagsPageView";

export default async function WorkspaceTagsPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  return <TagsPageView workspaceId={id} />;
}
