import axios from "axios";

const API_ROOT = (process.env.NEXT_PUBLIC_API_URL ?? "").replace(
  /\/api\/?$/,
  "",
);

export const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL,
  withCredentials: true,
  headers: {
    Accept: "application/json",
    "Content-Type": "application/json",
  },
});

export async function fetchCsrfCookie() {
  await api.get(`${API_ROOT}/sanctum/csrf-cookie`);
}