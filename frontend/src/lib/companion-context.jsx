"use client";

import { createContext, useCallback, useContext, useEffect, useState } from "react";

const CompanionContext = createContext(null);
const COMPANION_URL = "http://127.0.0.1:58347";
const TOKEN_KEY = "devplan.companionToken";

async function companionFetch(path, token, options = {}) {
  const res = await fetch(`${COMPANION_URL}${path}`, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  });
  const body = await res.json().catch(() => null);
  if (!res.ok) {
    const error = new Error(body?.message || `Companion request failed (${res.status})`);
    error.status = res.status;
    throw error;
  }
  return body;
}

export function CompanionProvider({ children }) {
  const [available, setAvailable] = useState(null); // null = unknown, true/false once checked
  const [token, setToken] = useState(() => (typeof window === "undefined" ? null : localStorage.getItem(TOKEN_KEY)));
  const [checking, setChecking] = useState(true);

  const ping = useCallback(async () => {
    try {
      await fetch(`${COMPANION_URL}/health`, { signal: AbortSignal.timeout(2000) });
      setAvailable(true);
    } catch {
      setAvailable(false);
    }
  }, []);

  useEffect(() => {
    void Promise.resolve().then(() => ping().finally(() => setChecking(false)));
  }, [ping]);

  async function pair(code) {
    const result = await companionFetch("/pair", null, {
      method: "POST",
      body: JSON.stringify({ code }),
    });
    setToken(result.token);
    localStorage.setItem(TOKEN_KEY, result.token);
    setAvailable(true);
    return result.token;
  }

  function forgetPairing() {
    setToken(null);
    localStorage.removeItem(TOKEN_KEY);
  }

  // If the companion restarted since we stored a token, its session memory
  // is gone and every authenticated call 401s — fall back to "not paired"
  // instead of leaving the UI stuck on a dead token.
  async function withAuthRecovery(fn) {
    try {
      return await fn();
    } catch (err) {
      if (err.status === 401) forgetPairing();
      throw err;
    }
  }

  async function checkEnvironment() {
    return withAuthRecovery(() => companionFetch("/environment-check", token));
  }

  async function createProject(payload) {
    return withAuthRecovery(() =>
      companionFetch("/project/create", token, {
        method: "POST",
        body: JSON.stringify(payload),
      }),
    );
  }

  async function runCommand(command, cwd) {
    return withAuthRecovery(() =>
      companionFetch("/run", token, {
        method: "POST",
        body: JSON.stringify({ command, cwd }),
      }),
    );
  }

  async function registerProject(path) {
    return withAuthRecovery(() =>
      companionFetch("/project/register", token, {
        method: "POST",
        body: JSON.stringify({ path }),
      }),
    );
  }

  async function listFiles() {
    return withAuthRecovery(() => companionFetch("/project/files", token));
  }

  async function readFile(path) {
    return withAuthRecovery(() =>
      companionFetch("/file/read", token, {
        method: "POST",
        body: JSON.stringify({ path }),
      }),
    );
  }

  async function writeFile(path, content) {
    return withAuthRecovery(() =>
      companionFetch("/file/write", token, {
        method: "POST",
        body: JSON.stringify({ path, content }),
      }),
    );
  }

  async function deleteFile(path) {
    return withAuthRecovery(() =>
      companionFetch("/file/delete", token, {
        method: "POST",
        body: JSON.stringify({ path }),
      }),
    );
  }

  async function renameFile(path, newPath) {
    return withAuthRecovery(() =>
      companionFetch("/file/rename", token, {
        method: "POST",
        body: JSON.stringify({ path, newPath }),
      }),
    );
  }

  async function startProcess(command, cwd) {
    return withAuthRecovery(() =>
      companionFetch("/process/start", token, {
        method: "POST",
        body: JSON.stringify({ command, cwd }),
      }),
    );
  }

  async function getProcessOutput(id, since) {
    return withAuthRecovery(() => companionFetch(`/process/${id}/output?since=${since}`, token));
  }

  async function sendProcessInput(id, data) {
    return withAuthRecovery(() =>
      companionFetch(`/process/${id}/input`, token, {
        method: "POST",
        body: JSON.stringify({ data }),
      }),
    );
  }

  async function stopProcess(id) {
    return withAuthRecovery(() => companionFetch(`/process/${id}/stop`, token, { method: "POST" }));
  }

  // The only two functions that ever call /env/*, which are themselves the
  // only two Companion endpoints allowed to touch .env files — kept
  // deliberately separate from readFile/writeFile above (Phase 9).
  async function readEnvFile(path) {
    return withAuthRecovery(() =>
      companionFetch("/env/read", token, {
        method: "POST",
        body: JSON.stringify({ path }),
      }),
    );
  }

  async function writeEnvFile(path, content) {
    return withAuthRecovery(() =>
      companionFetch("/env/write", token, {
        method: "POST",
        body: JSON.stringify({ path, content }),
      }),
    );
  }

  const paired = !!token;

  return (
    <CompanionContext.Provider
      value={{
        available,
        checking,
        paired,
        ping,
        pair,
        forgetPairing,
        checkEnvironment,
        createProject,
        registerProject,
        listFiles,
        readFile,
        writeFile,
        deleteFile,
        renameFile,
        runCommand,
        startProcess,
        getProcessOutput,
        sendProcessInput,
        stopProcess,
        readEnvFile,
        writeEnvFile,
      }}
    >
      {children}
    </CompanionContext.Provider>
  );
}

export function useCompanion() {
  const ctx = useContext(CompanionContext);
  if (!ctx) throw new Error("useCompanion must be used within CompanionProvider");
  return ctx;
}
