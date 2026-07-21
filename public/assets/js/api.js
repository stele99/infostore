/**
 * Zentraler API-Client. Einheitliche Fehlerbehandlung, CSRF-Header,
 * keine sensiblen Daten in URLs.
 */

let csrfToken = null;

export function setCsrf(token) {
  csrfToken = token;
}

export class ApiException extends Error {
  constructor(status, code, message, requestId) {
    super(message);
    this.status = status;
    this.code = code;
    this.requestId = requestId;
  }
}

async function call(method, path, body) {
  const headers = { Accept: "application/json" };
  if (body !== undefined) {
    headers["Content-Type"] = "application/json";
  }
  if (csrfToken) {
    headers["X-CSRF-Token"] = csrfToken;
  }
  let res;
  try {
    res = await fetch("api.php" + path, {
      method,
      headers,
      credentials: "same-origin",
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new ApiException(0, "network", "Netzwerkfehler - bitte Verbindung pruefen.", "");
  }
  let payload = null;
  try {
    payload = await res.json();
  } catch {
    /* leerer oder kaputter Body -> unten generischer Fehler */
  }
  if (!res.ok) {
    const err = payload && payload.error ? payload.error : {};
    throw new ApiException(res.status, err.code || "unknown", err.message || `HTTP ${res.status}`, err.requestId || "");
  }
  return payload ? payload.data : null;
}

export const api = {
  get: (path) => call("GET", path),
  post: (path, body) => call("POST", path, body ?? {}),
  put: (path, body) => call("PUT", path, body),
  del: (path) => call("DELETE", path),
};
