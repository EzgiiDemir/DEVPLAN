"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { apiFetch } from "@/lib/api";

export function ActivityFeedPanel({ projectId }) {
  const t = useTranslations("StudioActivity");

  const [entries, setEntries] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    apiFetch(`/projects/${projectId}/activity`)
      .then(setEntries)
      .catch((err) => setError(err.message));
  }, [projectId]);

  return (
    <div className="h-full overflow-y-auto p-3 text-dp-editor-text flex flex-col gap-2">
      {entries === null && !error && <p className="text-[12px] text-dp-editor-muted">{t("empty")}</p>}
      {entries?.length === 0 && <p className="text-[12px] text-dp-editor-muted italic">{t("empty")}</p>}

      {entries?.map((entry) => (
        <div key={entry.id} className="flex items-start gap-2 text-[12px] border-b border-dp-editor-border pb-2 last:border-0">
          <span className="text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded bg-dp-editor-overlay text-dp-editor-muted flex-shrink-0 mt-0.5">
            {t(`type.${entry.type}`)}
          </span>
          <div className="flex-1">
            <p className="text-dp-editor-text">{entry.summary}</p>
            <p className="text-[10px] text-dp-editor-muted">
              {t("by", { name: entry.user || t("unknownUser") })} · {new Date(entry.created_at).toLocaleString()}
            </p>
          </div>
        </div>
      ))}

      {error && <p className="text-[11px] text-red-400">{error}</p>}
    </div>
  );
}
