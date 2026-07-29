"use client";

import Editor from "@monaco-editor/react";
import { useTheme } from "@/lib/theme-context";

const EXT_LANGUAGE = {
  js: "javascript", jsx: "javascript", mjs: "javascript", cjs: "javascript",
  ts: "typescript", tsx: "typescript",
  php: "php", py: "python", json: "json", css: "css", scss: "scss",
  md: "markdown", yml: "yaml", yaml: "yaml", html: "html", htm: "html",
  sql: "sql", sh: "shell", go: "go", rb: "ruby", java: "java",
  c: "c", cpp: "cpp", cs: "csharp", rs: "rust", xml: "xml",
};

export function languageForPath(path) {
  const ext = (path || "").split(".").pop().toLowerCase();
  return EXT_LANGUAGE[ext] || "plaintext";
}

// Monaco's own bundled TypeScript/JavaScript language service gives real
// autocomplete for these languages for free — no LSP, no extra
// infrastructure. Everything else gets syntax highlighting and word-based
// suggestions only, same as Monaco would give anyone without an LSP wired up.
export function CodeEditor({ path, value, onChange, onSave, onCursorChange, initialPosition }) {
  const { theme } = useTheme();

  return (
    <Editor
      path={path}
      language={languageForPath(path)}
      value={value}
      theme={theme === "dark" ? "vs-dark" : "vs"}
      onChange={(v) => onChange(v ?? "")}
      onMount={(editor, monaco) => {
        editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => onSave());
        if (initialPosition) {
          editor.setPosition(initialPosition);
          editor.revealPositionInCenter(initialPosition);
        }
        editor.onDidChangeCursorPosition((e) => {
          onCursorChange?.({ line: e.position.lineNumber, column: e.position.column });
        });
      }}
      options={{
        minimap: { enabled: false },
        fontSize: 13,
        automaticLayout: true,
        scrollBeyondLastLine: false,
      }}
    />
  );
}
