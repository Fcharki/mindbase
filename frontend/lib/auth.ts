import { api, fetchCsrfCookie } from "./api";

export interface User {
  id: number;
  username: string;
  email: string;
  email_verified_at: string | null;
  has_password: boolean;
  bio: string | null;
  location: string | null;
  photo_url: string | null;
}

export async function login(
  email: string,
  password: string,
) {
  await fetchCsrfCookie();

  const response = await api.post<{
    message: string;
    user: User;
  }>("/auth/login", {
    email,
    password,
  });

  return response.data.user;
}

export async function loginWithGoogle() {
  window.open(`${process.env.NEXT_PUBLIC_API_URL}/auth/google`, "_self");
}

export async function register(
  username: string,
  email: string,
  password: string,
  passwordConfirmation: string,
) {
  await fetchCsrfCookie();

  await api.post("/auth/register", {
    username,
    email,
    password,
    password_confirmation: passwordConfirmation,
  });
}

export async function forgotPassword(email: string) {
  await fetchCsrfCookie();

  await api.post("/auth/forgot-password", {
    email,
  });
}

export async function resetPassword(
  token: string,
  email: string,
  password: string,
  passwordConfirmation: string,
) {
  await fetchCsrfCookie();

  await api.post("/auth/reset-password", {
    token,
    email,
    password,
    password_confirmation: passwordConfirmation,
  });
}

export async function logout() {
  await fetchCsrfCookie();

  await api.post("/auth/logout");
}

export async function getCurrentUser(): Promise<User> {
  const response = await api.get<{ user: User }>("/auth/user");

  return response.data.user;
}