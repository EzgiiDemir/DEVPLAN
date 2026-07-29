"use client";

import { createContext, useContext, useEffect, useState } from "react";

const ThemeContext = createContext(null);
const THEME_KEY = "devplan.theme";

export function ThemeProvider({ children }) {
  // Always starts as "light" so the very first client render matches what
  // the server rendered — resolving localStorage/matchMedia here (even in a
  // lazy useState initializer) runs during the client's first render too,
  // before hydration, and produced a server/client mismatch whenever the
  // real theme was "dark". The real value is resolved client-only, below.
  const [theme, setThemeState] = useState("light");

  useEffect(() => {
    void Promise.resolve().then(() => {
      const stored = localStorage.getItem(THEME_KEY);
      if (stored === "light" || stored === "dark") {
        setThemeState(stored);
        document.documentElement.setAttribute("data-theme", stored);
        return;
      }
      // No explicit choice yet — CSS follows the OS preference on its own;
      // this just keeps the toggle icon in sync with what's actually showing.
      const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
      setThemeState(prefersDark ? "dark" : "light");
    });
  }, []);

  function setTheme(next) {
    setThemeState(next);
    localStorage.setItem(THEME_KEY, next);
    document.documentElement.setAttribute("data-theme", next);
  }

  function toggleTheme() {
    setTheme(theme === "light" ? "dark" : "light");
  }

  return (
    <ThemeContext.Provider value={{ theme, setTheme, toggleTheme }}>{children}</ThemeContext.Provider>
  );
}

export function useTheme() {
  const ctx = useContext(ThemeContext);
  if (!ctx) throw new Error("useTheme must be used within ThemeProvider");
  return ctx;
}
