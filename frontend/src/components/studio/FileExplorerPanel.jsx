"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronDown, ChevronRight, File, FilePlus, Folder, Pencil, RotateCw, Search, Sparkles, Trash2, TriangleAlert } from "lucide-react";
import { useCompanion } from "@/lib/companion-context";
import { apiFetch } from "@/lib/api";

function buildTree(files) {
  const root = { name: "", type: "folder", children: [] };
  for (const file of files) {
    const parts = file.path.split("/");
    let node = root;
    parts.forEach((name, i) => {
      const isFile = i === parts.length - 1;
      if (isFile) {
        node.children.push({ name, type: "file", path: file.path, unresolvedImports: file.unresolvedImports });
        return;
      }
      let child = node.children.find((c) => c.type === "folder" && c.name === name);
      if (!child) {
        child = { name, type: "folder", children: [] };
        node.children.push(child);
      }
      node = child;
    });
  }
  (function sortTree(node) {
    node.children.sort((a, b) => (a.type !== b.type ? (a.type === "folder" ? -1 : 1) : a.name.localeCompare(b.name)));
    node.children.forEach((c) => c.type === "folder" && sortTree(c));
  })(root);
  return root;
}

function ExplorerNode({ node, depth, activeFile, onSelectFile, onRename, onDelete, onGenerateTests }) {
  const [open, setOpen] = useState(depth < 1);

  if (node.type === "folder") {
    return (
      <div>
        <button
          type="button"
          onClick={() => setOpen((v) => !v)}
          className="flex items-center gap-1.5 w-full text-left px-2 py-1 rounded text-[13px] text-dp-editor-muted hover:bg-dp-editor-overlay"
          style={{ paddingLeft: `${depth * 14 + 8}px` }}
        >
          {open ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
          <Folder size={14} className="text-dp-accent" />
          {node.name}
        </button>
        {open && node.children.map((child) => (
          <ExplorerNode
            key={child.type + child.name}
            node={child}
            depth={depth + 1}
            activeFile={activeFile}
            onSelectFile={onSelectFile}
            onRename={onRename}
            onDelete={onDelete}
            onGenerateTests={onGenerateTests}
          />
        ))}
      </div>
    );
  }

  const isActive = node.path === activeFile;
  return (
    <div
      className={`group flex items-center gap-1.5 w-full text-[13px] rounded ${
        isActive ? "bg-dp-editor-overlay text-dp-editor-text" : "text-dp-editor-muted hover:bg-dp-editor-overlay"
      }`}
      style={{ paddingLeft: `${depth * 14 + 26}px` }}
    >
      <button type="button" onClick={() => onSelectFile(node.path)} className="flex items-center gap-1.5 flex-1 min-w-0 py-1 text-left">
        <File size={13} className="flex-shrink-0" />
        <span className="truncate">{node.name}</span>
        {node.unresolvedImports?.length > 0 && (
          <TriangleAlert size={11} className="text-amber-500 flex-shrink-0" title={node.unresolvedImports.join(", ")} />
        )}
      </button>
      <button type="button" onClick={() => onGenerateTests(node.path)} className="opacity-0 group-hover:opacity-100 pr-1 flex-shrink-0">
        <Sparkles size={11} />
      </button>
      <button type="button" onClick={() => onRename(node.path)} className="opacity-0 group-hover:opacity-100 pr-1 flex-shrink-0">
        <Pencil size={11} />
      </button>
      <button type="button" onClick={() => onDelete(node.path)} className="opacity-0 group-hover:opacity-100 pr-2 flex-shrink-0">
        <Trash2 size={11} />
      </button>
    </div>
  );
}

export function FileExplorerPanel({ projectId, activeFile, onSelectFile }) {
  const t = useTranslations("StudioFileExplorer");
  const companion = useCompanion();

  const [files, setFiles] = useState([]);
  const [query, setQuery] = useState("");
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(null);

  async function refresh() {
    try {
      const result = await companion.listFiles();
      // Merge in unresolved_imports from the Project Brain scan (Companion's
      // own listing has no idea what an import even is) — best-effort, a
      // project that's never been scanned just shows no warning badges.
      let unresolvedByPath = {};
      try {
        const scanned = await apiFetch(`/projects/${projectId}/codebase/files`);
        unresolvedByPath = Object.fromEntries(scanned.map((f) => [f.path, f.unresolved_imports]));
      } catch {
        // Not scanned yet, or scan endpoint unreachable — badges just won't show.
      }
      setFiles(result.files.map((f) => ({ ...f, unresolvedImports: unresolvedByPath[f.path] || null })));
      setError(null);
    } catch (err) {
      setError(err.message);
    }
  }

  useEffect(() => {
    void Promise.resolve().then(() => refresh());
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function createFile() {
    const path = window.prompt(t("newFilePrompt"));
    if (!path) return;
    try {
      await companion.writeFile(path, "");
      await refresh();
      onSelectFile(path);
    } catch (err) {
      setError(err.message);
    }
  }

  async function renameFile(path) {
    const newPath = window.prompt(t("renamePrompt", { path }), path);
    if (!newPath || newPath === path) return;
    try {
      await companion.renameFile(path, newPath);
      await refresh();
      if (activeFile === path) onSelectFile(newPath);
    } catch (err) {
      setError(err.message);
    }
  }

  async function deleteFile(path) {
    if (!window.confirm(t("confirmDelete", { path }))) return;
    try {
      await companion.deleteFile(path);
      await refresh();
      if (activeFile === path) onSelectFile(null);
      apiFetch(`/projects/${projectId}/audit/commands`, {
        method: "POST",
        body: JSON.stringify({ type: "file_delete", path, risk_level: "sensitive" }),
      }).catch(() => {
        // Best-effort — the delete already happened; a failed audit relay
        // shouldn't surface as if the delete itself failed.
      });
    } catch (err) {
      setError(err.message);
    }
  }

  async function generateTests(path) {
    setError(null);
    setNotice(null);
    try {
      await apiFetch(`/projects/${projectId}/tests/generate`, {
        method: "POST",
        body: JSON.stringify({ path }),
      });
      setNotice(t("testsGenerated", { path }));
    } catch (err) {
      setError(err.message);
    }
  }

  const filtered = query.trim()
    ? files.filter((f) => f.path.toLowerCase().includes(query.trim().toLowerCase()))
    : files;
  const tree = buildTree(filtered);

  return (
    <div className="flex flex-col">
      <div className="flex items-center justify-between px-3 pt-2 pb-1.5">
        <span className="text-[11px] font-semibold uppercase tracking-wider text-dp-editor-muted">{t("filesLabel")}</span>
        <div className="flex items-center gap-2 text-dp-editor-muted">
          <button type="button" onClick={createFile} title={t("newFile")} className="hover:text-dp-editor-text">
            <FilePlus size={13} />
          </button>
          <button type="button" onClick={refresh} title={t("refresh")} className="hover:text-dp-editor-text">
            <RotateCw size={12} />
          </button>
        </div>
      </div>
      <div className="px-3 pb-1.5">
        <div className="flex items-center gap-1.5 bg-dp-editor-overlay rounded px-2 py-1">
          <Search size={11} className="text-dp-editor-muted flex-shrink-0" />
          <input
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={t("searchPlaceholder")}
            className="flex-1 min-w-0 bg-transparent text-[11px] text-dp-editor-text placeholder:text-dp-editor-muted outline-none"
          />
        </div>
      </div>
      {error && <p className="px-3 pb-1.5 text-[10px] text-red-400">{error}</p>}
      {notice && <p className="px-3 pb-1.5 text-[10px] text-dp-accent-strong">{notice}</p>}
      <div>
        {tree.children.map((child) => (
          <ExplorerNode
            key={child.type + child.name}
            node={child}
            depth={0}
            activeFile={activeFile}
            onSelectFile={onSelectFile}
            onRename={renameFile}
            onDelete={deleteFile}
            onGenerateTests={generateTests}
          />
        ))}
      </div>
    </div>
  );
}
