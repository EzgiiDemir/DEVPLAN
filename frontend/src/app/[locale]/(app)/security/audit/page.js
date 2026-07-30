"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { apiFetch } from "@/lib/api";
import { useProject } from "@/lib/project-context";

const ACTIONS = [
  "auth.login", "auth.login_failed", "auth.logout", "auth.register",
  "auth.mfa_enabled", "auth.mfa_disabled",
  "team.member_invited", "team.member_joined", "team.role_changed", "team.member_removed",
  "project.deleted",
  "feature.plan_approved", "feature.diff_approved", "feature.applied",
  "companion.command_executed", "companion.file_deleted",
];

export default function AuditHistoryPage() {
  const t = useTranslations("SecurityAudit");
  const { projects } = useProject();

  const [projectId, setProjectId] = useState("");
  const [action, setAction] = useState("");
  const [entries, setEntries] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (projects.length === 0 || projectId) return;
    void Promise.resolve().then(() => setProjectId(String(projects[0].id)));
  }, [projects, projectId]);

  useEffect(() => {
    if (!projectId) return;
    void Promise.resolve().then(() => setEntries(null));
    const query = action ? `?action=${encodeURIComponent(action)}` : "";
    apiFetch(`/projects/${projectId}/audit${query}`)
      .then(setEntries)
      .catch((err) => setError(err.message));
  }, [projectId, action]);

  return (
    <div className="max-w-3xl mx-auto w-full px-4 sm:px-6 py-10 sm:py-14">
      <h1 className="text-2xl sm:text-3xl font-bold tracking-tight mb-1">{t("heading")}</h1>
      <p className="text-sm text-dp-muted mb-6">{t("subheading")}</p>

      <div className="flex flex-col sm:flex-row gap-3 mb-6">
        <div className="flex-1">
          <label className="text-xs text-dp-muted mb-1 block">{t("projectFilter")}</label>
          <select
            value={projectId}
            onChange={(e) => setProjectId(e.target.value)}
            className="w-full rounded-xl border border-dp-border bg-dp-faint px-3 py-2.5 text-sm outline-none"
          >
            {projects.map((p) => (
              <option key={p.id} value={p.id}>
                {p.title}
              </option>
            ))}
          </select>
        </div>
        <div className="flex-1">
          <label className="text-xs text-dp-muted mb-1 block">{t("actionFilter")}</label>
          <select
            value={action}
            onChange={(e) => setAction(e.target.value)}
            className="w-full rounded-xl border border-dp-border bg-dp-faint px-3 py-2.5 text-sm outline-none"
          >
            <option value="">{t("allActions")}</option>
            {ACTIONS.map((a) => (
              <option key={a} value={a}>
                {a}
              </option>
            ))}
          </select>
        </div>
      </div>

      {error && <p className="text-xs text-red-500 mb-4">{error}</p>}
      {entries === null && !error && <p className="text-sm text-dp-muted">{t("loading")}</p>}
      {entries?.length === 0 && <p className="text-sm text-dp-muted italic">{t("empty")}</p>}

      <div className="flex flex-col gap-2">
        {entries?.map((entry) => (
          <div key={entry.id} className="bg-dp-panel rounded-xl border border-dp-border p-4">
            <div className="flex items-center justify-between mb-1">
              <span className="font-mono text-xs font-semibold">{entry.action}</span>
              <span className="text-xs text-dp-muted">{new Date(entry.created_at).toLocaleString()}</span>
            </div>
            <div className="text-xs text-dp-muted">{t("by", { name: entry.user?.name || t("unknownUser") })}</div>
            {entry.metadata && Object.keys(entry.metadata).length > 0 && (
              <pre className="text-[11px] font-mono text-dp-muted mt-2 overflow-x-auto">{JSON.stringify(entry.metadata, null, 2)}</pre>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}
