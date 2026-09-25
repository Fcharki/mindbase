import axios, { type AxiosError, type InternalAxiosRequestConfig } from "axios";

// Sanctum's CSRF route lives at the server root, outside the versioned
// API prefix, so strip the whole "/api/vN" base off before using it.
const API_ROOT = (process.env.NEXT_PUBLIC_API_URL ?? "").replace(
  /\/api(?:\/v\d+)?\/?$/,
  "",
);

export const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: "application/json",
    "Content-Type": "application/json",
  },
});

interface RetryableConfig extends InternalAxiosRequestConfig {
  _csrfRetried?: boolean;
}

async function syncCsrfCookie() {
  await api.get(`${API_ROOT}/sanctum/csrf-cookie`);
}

export async function fetchCsrfCookie() {
  await syncCsrfCookie();
}

api.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const config = error.config as RetryableConfig | undefined;
    const method = (config?.method ?? "").toLowerCase();
    const isMutating = ["post", "put", "patch", "delete"].includes(method);

    if (
      error.response?.status === 419 &&
      isMutating &&
      config &&
      !config._csrfRetried
    ) {
      config._csrfRetried = true;

      await syncCsrfCookie();

      return api.request(config);
    }

    return Promise.reject(error);
  },
);