"use client";

import { useTranslations } from "next-intl";
import { X } from "lucide-react";

export function EditorTabs({ openPaths, activePath, dirtyPaths, onSelect, onClose }) {
  const t = useTranslations("StudioIde");

  function closeTab(e, path) {
    e.stopPropagation();
    if (dirtyPaths.has(path) && !window.confirm(t("confirmDiscard", { path }))) return;
    onClose(path);
  }

  return (
    <div className="flex items-center overflow-x-auto border-b border-dp-editor-border bg-dp-editor-panel flex-shrink-0">
      {openPaths.map((path) => {
        const isActive = path === activePath;
        const name = path.split("/").pop();
        return (
          <button
            key={path}
            type="button"
            onClick={() => onSelect(path)}
            title={path}
            className={`flex items-center gap-1.5 px-3 py-2 text-xs border-r border-dp-editor-border flex-shrink-0 ${
              isActive ? "bg-dp-editor-bg text-dp-editor-text" : "text-dp-editor-muted hover:bg-dp-editor-overlay"
            }`}
          >
            <span className="truncate max-w-[140px]">{name}</span>
            {dirtyPaths.has(path) && <span className="w-1.5 h-1.5 rounded-full bg-dp-accent flex-shrink-0" />}
            <span
              role="button"
              tabIndex={-1}
              onClick={(e) => closeTab(e, path)}
              className="hover:text-dp-editor-text flex-shrink-0"
            >
              <X size={12} />
            </span>
          </button>
        );
      })}
    </div>
  );
}
