import { EntryDetailPageView } from "./EntryDetailPageView";

export default async function EntryPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string; entryId: string }>;
  searchParams: Promise<{ [key: string]: string | string[] | undefined }>;
}) {
  const { id, entryId } = await params;

  // Read here and passed down rather than with useSearchParams inside the view,
  // which would opt the client tree out of prerendering. A notification link
  // names the comment, or the reply and its comment, it wants to show.
  const query = await searchParams;

  const commentId =
    typeof query.comment === "string" ? query.comment : undefined;
  const replyId = typeof query.reply === "string" ? query.reply : undefined;

  return (
    <EntryDetailPageView
      workspaceId={id}
      entryId={entryId}
      highlightCommentId={commentId}
      highlightReplyId={replyId}
    />
  );
}