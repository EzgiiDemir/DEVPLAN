"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronDown, ChevronRight } from "lucide-react";

const ACTION_LABEL_KEY = { create: "actionCreate", modify: "actionModify", delete: "actionDelete" };

export function FileRow({ file, checked, onToggle, editable, oldContent }) {
  const [expanded, setExpanded] = useState(false);
  const t = useTranslations("StudioFeatureBuilder");

  return (
    <div className="border border-dp-editor-border rounded-lg overflow-hidden">
      <div className="flex items-center gap-2 px-2.5 py-2 bg-dp-editor-overlay">
        {editable && (
          <input type="checkbox" checked={checked} onChange={onToggle} className="flex-shrink-0" />
        )}
        <button
          type="button"
          onClick={() => setExpanded((v) => !v)}
          className="flex items-center gap-1.5 flex-1 min-w-0 text-left"
        >
          {expanded ? <ChevronDown size={12} className="flex-shrink-0" /> : <ChevronRight size={12} className="flex-shrink-0" />}
          <span className="text-[11px] font-mono truncate">{file.path}</span>
        </button>
        <span
          className={`text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded flex-shrink-0 ${
            file.action === "delete"
              ? "bg-red-500/20 text-red-400"
              : file.action === "create"
                ? "bg-green-500/20 text-green-400"
                : "bg-dp-accent/20 text-dp-accent-strong"
          }`}
        >
          {t(ACTION_LABEL_KEY[file.action] ?? "actionModify")}
        </span>
      </div>
      {expanded && (
        <div className="px-2.5 py-2 space-y-1.5">
          {file.reason && <p className="text-[11px] text-dp-editor-muted italic">{file.reason}</p>}
          {file.new_content && (
            <pre className="text-[10px] font-mono bg-dp-editor-bg rounded p-2 overflow-auto max-h-40 whitespace-pre-wrap">
              {file.new_content}
            </pre>
          )}
          {file.action === "modify" && oldContent && (
            <details className="text-[10px]">
              <summary className="cursor-pointer text-dp-editor-muted">{t("showOriginal")}</summary>
              <pre className="font-mono bg-dp-editor-bg rounded p-2 overflow-auto max-h-40 whitespace-pre-wrap mt-1">
                {oldContent}
              </pre>
            </details>
          )}
        </div>
      )}
    </div>
  );
}
