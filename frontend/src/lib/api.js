const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL || "http://localhost:8010/api/v1";
// Strips the versioned /api/v1 (or bare /api) suffix — Sanctum's CSRF
// cookie route lives under web.php, unversioned, not under /api at all.
const API_HOST = API_BASE_URL.replace(/\/api(\/v\d+)?\/?$/, "");

function readCookie(name) {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
  return match ? decodeURIComponent(match[1]) : null;
}

export async function ensureCsrfCookie() {
  // Always refresh: the backend rotates the session (and its CSRF token) on
  // login/register (`session()->regenerate()`), so a cached XSRF-TOKEN cookie
  // from before that point is stale and causes a 419 on the next mutation.
  await fetch(`${API_HOST}/sanctum/csrf-cookie`, { credentials: "include" });
}

export async function apiFetch(path, options = {}) {
  const method = (options.method || "GET").toUpperCase();

  if (method !== "GET" && method !== "HEAD") {
    await ensureCsrfCookie();
  }

  const res = await fetch(`${API_BASE_URL}${path}`, {
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      "X-Locale": document.documentElement.lang,
      ...(method !== "GET" && method !== "HEAD"
        ? { "X-XSRF-TOKEN": readCookie("XSRF-TOKEN") }
        : {}),
      ...options.headers,
    },
    ...options,
  });

  if (!res.ok) {
    const body = await res.json().catch(() => null);
    const error = new Error(body?.message || `İstek başarısız: ${res.status}`);
    error.status = res.status;
    throw error;
  }

  return res.status === 204 ? null : res.json();
}
