import { api, fetchCsrfCookie } from "./api";
import type { User } from "./auth";

export async function getProfile(): Promise<User> {
  const response = await api.get<{ user: User }>("/profile");

  return response.data.user;
}

export async function updateProfile(input: {
  name: string;
  email: string;
}): Promise<{ user: User; message: string }> {
  await fetchCsrfCookie();

  const response = await api.patch<{ user: User; message: string }>(
    "/profile",
    input,
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
