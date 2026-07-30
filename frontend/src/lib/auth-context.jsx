"use client";

import { createContext, useContext, useEffect, useState } from "react";
import { apiFetch } from "./api";

const AuthContext = createContext(null);
const SESSION_HINT_KEY = "devplan.hadSession";

function hadPriorSession() {
  return typeof window !== "undefined" && localStorage.getItem(SESSION_HINT_KEY) === "1";
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  // Skip the /user probe entirely for a visitor who has never logged in on
  // this browser — avoids a guaranteed 401 (and its console noise) on every
  // fresh visit. Once someone logs in we always re-check, so expiry still
  // resolves to logged-out normally.
  const [loading, setLoading] = useState(hadPriorSession);

  useEffect(() => {
    if (!hadPriorSession()) return;

    apiFetch("/user")
      .then(setUser)
      .catch(() => setUser(null))
      .finally(() => setLoading(false));
  }, []);

  async function register(name, email, password) {
    const newUser = await apiFetch("/register", {
      method: "POST",
      body: JSON.stringify({ name, email, password }),
    });
    localStorage.setItem(SESSION_HINT_KEY, "1");
    setUser(newUser);
    return newUser;
  }

  /**
   * A response of {requires_mfa: true} means the password checked out but
   * the session isn't authenticated yet — the caller must collect a real
   * code and call loginWithMfa() to finish. Only a real user object here
   * means login is actually complete.
   */
  async function login(email, password) {
    const result = await apiFetch("/login", {
      method: "POST",
      body: JSON.stringify({ email, password }),
    });
    if (result?.requires_mfa) return result;

    localStorage.setItem(SESSION_HINT_KEY, "1");
    setUser(result);
    return result;
  }

  async function loginWithMfa(code) {
    const loggedInUser = await apiFetch("/login/mfa", {
      method: "POST",
      body: JSON.stringify({ code }),
    });
    localStorage.setItem(SESSION_HINT_KEY, "1");
    setUser(loggedInUser);
    return loggedInUser;
  }

  async function logout() {
    await apiFetch("/logout", { method: "POST" });
    localStorage.removeItem(SESSION_HINT_KEY);
    setUser(null);
  }

  async function updateName(name) {
    const updated = await apiFetch("/profile", {
      method: "PATCH",
      body: JSON.stringify({ name }),
    });
    setUser(updated);
    return updated;
  }

  async function updatePassword(currentPassword, password, passwordConfirmation) {
    await apiFetch("/profile/password", {
      method: "PUT",
      body: JSON.stringify({
        current_password: currentPassword,
        password,
        password_confirmation: passwordConfirmation,
      }),
    });
  }

  // For when the backend says 401 on a request that assumed we were logged
  // in (e.g. session pointed at a user that no longer exists). No network
  // call — the server has already told us the session is dead.
  function clearSession() {
    localStorage.removeItem(SESSION_HINT_KEY);
    setUser(null);
  }

  return (
    <AuthContext.Provider
      value={{ user, loading, register, login, loginWithMfa, logout, clearSession, updateName, updatePassword }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}
