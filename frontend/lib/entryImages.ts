import { api } from "./api";

export interface EntryImage {
  id: number;
  uuid: string;
  url: string;
  original_name: string;
  mime_type: string | null;
  size: number;
  human_size: string;
  created_at: string;
  updated_at: string;
}

interface ResourceResponse<T> {
  data: T;
}

/**
 * Upload an image that will be embedded in entry content.
 *
 * The image is stored immediately and the returned absolute URL is what the
 * editor puts in the <img> tag. It is public but addressed by an unguessable
 * UUID, because an <img> cannot attach the Sanctum session.
 */
export async function uploadEntryImage(
  workspaceId: number | string,
  file: File,
): Promise<EntryImage> {
  const body = new FormData();
  body.append("file", file);

  const { data } = await api.post<ResourceResponse<EntryImage>>(
    `/workspaces/${workspaceId}/images`,
    body,
    {
      // Same FormData/JSON caveat as document uploads: let the browser set the
      // multipart boundary instead of inheriting the client's JSON header.
      headers: { "Content-Type": null },
    },
  );

  return data.data;
}
