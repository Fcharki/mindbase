import { api } from "./api";

export interface User {
  id: number;
  name: string;
  email: string;
  email_verified_at: string | null;
}

export async function getCsrfCookie() {
  await api.get("/sanctum/csrf-cookie");
}

export async function login(
  email: string,
  password: string,
) {
  await getCsrfCookie();

  await api.post("/login", {
    email,
    password,
  });
}

export async function register(
  name: string,
  email: string,
  password: string,
  passwordConfirmation: string,
) {
  await getCsrfCookie();

  await api.post("/register", {
    name,
    email,
    password,
    password_confirmation: passwordConfirmation,
  });
}

export async function logout() {
  await api.post("/logout");
}

export async function getCurrentUser(): Promise<User> {
  const response = await api.get<{ user: User }>("/api/user");

  return response.data.user;
}