import { api } from "./api";

export interface DocumentUploader {
  id: number;
  name: string;
}

export interface KnowledgeDocument {
  id: number;
  knowledge_entry_id: number;
  uploaded_by: number | null;
  disk: string;
  original_name: string;
  mime_type: string | null;
  size: number;
  created_at: string;
  updated_at: string;
  human_size: string;
  uploader?: DocumentUploader;
}

interface ResourceResponse<T> {
  data: T;
}

function documentsPath(
  workspaceId: number | string,
  entryId: number | string,
) {
  return `/workspaces/${workspaceId}/entries/${entryId}/documents`;
}

export async function listDocuments(
  workspaceId: number | string,
  entryId: number | string,
): Promise<KnowledgeDocument[]> {
  const { data } = await api.get<ResourceResponse<KnowledgeDocument[]>>(
    documentsPath(workspaceId, entryId),
  );

  return data.data;
}

export async function uploadDocument(
  workspaceId: number | string,
  entryId: number | string,
  file: File,
  onProgress?: (percent: number) => void,
): Promise<KnowledgeDocument> {
  const body = new FormData();
  body.append("file", file);

  const { data } = await api.post<ResourceResponse<KnowledgeDocument>>(
    documentsPath(workspaceId, entryId),
    body,
    {
      // The shared client defaults to Content-Type: application/json. Left
      // in place, axios takes its FormData -> JSON.stringify branch, which
      // flattens the File to "{}" and the backend receives an empty upload.
      // null deletes the header entirely so the browser can set the
      // multipart boundary. Do NOT set "multipart/form-data" by hand here:
      // axios' xhr adapter does not add a boundary, so PHP cannot parse it.
      headers: { "Content-Type": null },
      onUploadProgress: (event) => {
        if (!onProgress || !event.total) {
          return;
        }

        onProgress(Math.round((event.loaded / event.total) * 100));
      },
    },
  );

  return data.data;
}

export async function deleteDocument(
  workspaceId: number | string,
  entryId: number | string,
  documentId: number | string,
): Promise<void> {
  await api.delete(
    `${documentsPath(workspaceId, entryId)}/${documentId}`,
  );
}

/**
 * Download through axios rather than a plain link so the Sanctum session
 * cookie is always sent, including when the frontend and backend sit on
 * different domains (where a SameSite=Lax navigation would drop it).
 */
export async function downloadDocument(
  workspaceId: number | string,
  entryId: number | string,
  document: KnowledgeDocument,
): Promise<void> {
  const response = await api.get(
    `${documentsPath(workspaceId, entryId)}/${document.id}/download`,
    { responseType: "blob" },
  );

  const url = URL.createObjectURL(response.data as Blob);
  const link = window.document.createElement("a");

  link.href = url;
  link.download = document.original_name;

  window.document.body.appendChild(link);
  link.click();
  link.remove();

  URL.revokeObjectURL(url);
}
