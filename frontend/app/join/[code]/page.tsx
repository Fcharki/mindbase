import { JoinView } from "./JoinView";

export default async function JoinPage({
  params,
}: {
  params: Promise<{ code: string }>;
}) {
  const { code } = await params;

  return <JoinView inviteCode={code} />;
}