"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronDown, FolderKanban, Plus } from "lucide-react";
import { useProject } from "@/lib/project-context";

export function ProjectSwitcher({ onCreateClick }) {
  const t = useTranslations("Dashboard.switcher");
  const { project, projects, switchProject } = useProject();
  const [open, setOpen] = useState(false);

  if (projects.length <= 1) {
    return (
      <button
        onClick={onCreateClick}
        className="flex items-center gap-1.5 px-3.5 py-2 rounded-full border border-dp-border text-xs font-semibold text-dp-muted hover:text-dp-ink hover:bg-dp-faint transition-colors"
      >
        <Plus size={13} /> {t("newProject")}
      </button>
    );
  }

  return (
    <div className="relative">
      <button
        onClick={() => setOpen((v) => !v)}
        className="flex items-center gap-1.5 px-3.5 py-2 rounded-full border border-dp-border text-xs font-semibold text-dp-ink hover:bg-dp-faint transition-colors"
      >
        <FolderKanban size={13} />
        {project?.title}
        <ChevronDown size={13} />
      </button>

      {open && (
        <>
          <div className="fixed inset-0 z-30" onClick={() => setOpen(false)} />
          <div className="absolute right-0 top-full mt-2 w-64 bg-dp-panel border border-dp-border rounded-2xl shadow-[0_16px_40px_rgba(0,0,0,0.12)] p-2 z-40">
            {projects.map((p) => (
              <button
                key={p.id}
                onClick={() => {
                  switchProject(p.id);
                  setOpen(false);
                }}
                className={`w-full text-left px-3 py-2.5 rounded-xl text-sm font-medium transition-colors ${
                  p.id === project?.id ? "bg-dp-faint text-dp-ink" : "text-dp-muted hover:bg-dp-faint hover:text-dp-ink"
                }`}
              >
                {p.title}
              </button>
            ))}
            <div className="border-t border-dp-border my-1.5" />
            <button
              onClick={() => {
                setOpen(false);
                onCreateClick();
              }}
              className="w-full flex items-center gap-2 text-left px-3 py-2.5 rounded-xl text-sm font-medium text-dp-accent-strong hover:bg-dp-accent-tint transition-colors"
            >
              <Plus size={14} /> {t("newProject")}
            </button>
          </div>
        </>
      )}
    </div>
  );
}
