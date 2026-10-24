import { api, fetchCsrfCookie } from "./api";
import type { User } from "./auth";

export async function getProfile(): Promise<User> {
  const response = await api.get<{ user: User }>("/profile");

  return response.data.user;
}

export async function updateProfile(input: {
  username: string;
  email: string;
  bio: string | null;
  location: string | null;
}): Promise<{ user: User; message: string }> {
  await fetchCsrfCookie();

  const response = await api.patch<{ user: User; message: string }>(
    "/profile",
    input,
  );

  return response.data;
}

export async function uploadProfilePhoto(
  photo: File,
): Promise<{ user: User; message: string }> {
  await fetchCsrfCookie();

  const body = new FormData();
  body.append("photo", photo);

  const response = await api.post<{ user: User; message: string }>(
    "/profile/photo",
    body,
    {
      // Same FormData/JSON caveat as document uploads: let the browser set
      // the multipart boundary instead of inheriting the client's JSON header.
      headers: { "Content-Type": null },
    },
  );

  return response.data;
}

export async function removeProfilePhoto(): Promise<{
  user: User;
  message: string;
}> {
  await fetchCsrfCookie();

  const response = await api.delete<{ user: User; message: string }>(
    "/profile/photo",
  );

  return response.data;
}

export async function uploadProfileCover(
  cover: File,
): Promise<{ user: User; message: string }> {
  await fetchCsrfCookie();

  const body = new FormData();
  body.append("cover", cover);

  const response = await api.post<{ user: User; message: string }>(
    "/profile/cover",
    body,
    {
      headers: { "Content-Type": null },
    },
  );

  return response.data;
}

export async function removeProfileCover(): Promise<{
  user: User;
  message: string;
}> {
  await fetchCsrfCookie();

  const response = await api.delete<{ user: User; message: string }>(
    "/profile/cover",
  );

  return response.data;
}

export async function updatePassword(input: {
  currentPassword: string;
  password: string;
  passwordConfirmation: string;
}): Promise<string> {
  await fetchCsrfCookie();

  const response = await api.patch<{ message: string }>("/profile/password", {
    current_password: input.currentPassword,
    password: input.password,
    password_confirmation: input.passwordConfirmation,
  });

  return response.data.message;
}

export async function deleteAccount(input: {
  email: string;
  password?: string;
}): Promise<string> {
  await fetchCsrfCookie();

  const response = await api.delete<{ message: string }>("/profile", {
    data: {
      email: input.email,
      password: input.password,
    },
  });

  return response.data.message;
}
