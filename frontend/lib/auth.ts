import { api } from "./api";

export interface User {
  id: number;
  name: string;
  email: string;
  email_verified_at: string | null;
}


export async function login(
  email: string,
  password: string,
) {
  const response = await api.post<{
    message: string;
    user: User;
    token: string;
  }>("/auth/login", {
    email,
    password,
  });

  localStorage.setItem("auth_token", response.data.token);

  return response.data.user;
}

export async function loginWithGoogle() {
  window.location.href = `${process.env.NEXT_PUBLIC_API_URL}/auth/google`;
}

export async function register(
  name: string,
  email: string,
  password: string,
  passwordConfirmation: string,
) {

  await api.post("/auth/register", {
    name,
    email,
    password,
    password_confirmation: passwordConfirmation,
  });
}

export async function logout() {
  await api.post("/auth/logout");
  localStorage.removeItem("auth_token");
}

export async function getCurrentUser(): Promise<User> {
  const response = await api.get<{ user: User }>("/auth/user");

  return response.data.user;
}