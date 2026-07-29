"use client";

import { useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { File as FileIcon } from "lucide-react";
import { useCompanion } from "@/lib/companion-context";
import { apiFetch } from "@/lib/api";
import { EditorTabs } from "./EditorTabs";
import { CodeEditor } from "./CodeEditor";

const SAVE_STATE_DEBOUNCE_MS = 1000;

export function IdeWorkspace({ projectId, fileToOpen, onActiveFileChange, initialWorkspaceState }) {
  const t = useTranslations("StudioIde");
  const companion = useCompanion();

  const [openPaths, setOpenPaths] = useState(() => initialWorkspaceState?.openTabs || []);
  const [activePath, setActivePathState] = useState(() => initialWorkspaceState?.activeTab || null);
  const [contentByPath, setContentByPath] = useState({});
  const [dirtyPaths, setDirtyPaths] = useState(new Set());
  const [cursorPositions, setCursorPositions] = useState(() => initialWorkspaceState?.cursorPositions || {});
  const [error, setError] = useState(null);

  const saveStateTimer = useRef(null);
  const restoredRef = useRef(false);

  function setActivePath(path) {
    setActivePathState(path);
    onActiveFileChange?.(path);
  }

  async function openFile(path) {
    if (!path) return;
    if (!openPaths.includes(path)) {
      try {
        const { content } = await companion.readFile(path);
        setContentByPath((prev) => ({ ...prev, [path]: content }));
        setOpenPaths((prev) => [...prev, path]);
      } catch (err) {
        setError(err.message);
        return;
      }
    }
    setActivePath(path);
  }

  // Restore previously-open tabs once, when we first have a real Companion
  // connection to fetch their content from.
  useEffect(() => {
    if (restoredRef.current || !companion.paired) return;
    restoredRef.current = true;
    const tabs = initialWorkspaceState?.openTabs || [];
    if (tabs.length === 0) return;

    (async () => {
      const restored = {};
      for (const path of tabs) {
        try {
          const { content } = await companion.readFile(path);
          restored[path] = content;
        } catch {
          // File may have been deleted/moved outside DevPlan since — skip it.
        }
      }
      const stillValid = tabs.filter((p) => p in restored);
      setContentByPath(restored);
      setOpenPaths(stillValid);
      const activeTab = initialWorkspaceState?.activeTab;
      setActivePath(stillValid.includes(activeTab) ? activeTab : stillValid[0] || null);
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companion.paired]);

  useEffect(() => {
    if (fileToOpen) void Promise.resolve().then(() => openFile(fileToOpen));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fileToOpen]);

  function closeTab(path) {
    setOpenPaths((prev) => prev.filter((p) => p !== path));
    setDirtyPaths((prev) => {
      const next = new Set(prev);
      next.delete(path);
      return next;
    });
    if (activePath === path) {
      const remaining = openPaths.filter((p) => p !== path);
      setActivePath(remaining[remaining.length - 1] || null);
    }
  }

  function handleChange(path, value) {
    setContentByPath((prev) => ({ ...prev, [path]: value }));
    setDirtyPaths((prev) => new Set(prev).add(path));
  }

  async function save(path) {
    try {
      await companion.writeFile(path, contentByPath[path] ?? "");
      setDirtyPaths((prev) => {
        const next = new Set(prev);
        next.delete(path);
        return next;
      });
      setError(null);
    } catch (err) {
      setError(err.message);
    }
  }

  function handleCursorChange(path, position) {
    setCursorPositions((prev) => ({ ...prev, [path]: position }));
  }

  // Debounced persistence — every tab switch/edit/cursor move shouldn't hit
  // the network directly, only settle after activity pauses briefly.
  useEffect(() => {
    if (!projectId) return;
    if (saveStateTimer.current) clearTimeout(saveStateTimer.current);
    saveStateTimer.current = setTimeout(() => {
      apiFetch(`/projects/${projectId}/workspace-state`, {
        method: "PATCH",
        body: JSON.stringify({
          workspace_state: {
            openTabs: openPaths,
            activeTab: activePath,
            cursorPositions,
            lastActiveFile: activePath,
          },
        }),
      }).catch(() => {});
    }, SAVE_STATE_DEBOUNCE_MS);
    return () => clearTimeout(saveStateTimer.current);
  }, [projectId, openPaths, activePath, cursorPositions]);

  if (openPaths.length === 0) {
    return (
      <div className="flex-1 flex items-center justify-center text-sm text-dp-editor-muted">
        {t("noFileOpen")}
      </div>
    );
  }

  return (
    <div className="flex-1 flex flex-col min-w-0">
      <EditorTabs openPaths={openPaths} activePath={activePath} dirtyPaths={dirtyPaths} onSelect={setActivePath} onClose={closeTab} />
      <div className="flex items-center gap-2 px-4 py-1.5 text-[11px] border-b border-dp-editor-border bg-dp-editor-bg text-dp-editor-muted flex-shrink-0">
        <FileIcon size={11} />
        {activePath}
        {dirtyPaths.has(activePath) && <span className="text-dp-accent-strong">{t("unsaved")}</span>}
      </div>
      <div className="flex-1 min-h-0">
        {activePath && (
          <CodeEditor
            path={activePath}
            value={contentByPath[activePath] ?? ""}
            onChange={(v) => handleChange(activePath, v)}
            onSave={() => save(activePath)}
            onCursorChange={(pos) => handleCursorChange(activePath, pos)}
            initialPosition={cursorPositions[activePath]}
          />
        )}
      </div>
      {error && <p className="px-4 py-1 text-[11px] text-red-400 flex-shrink-0">{error}</p>}
    </div>
  );
}
