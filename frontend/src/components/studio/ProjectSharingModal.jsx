"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Share2, Trash2, X } from "lucide-react";
import { apiFetch } from "@/lib/api";

const ROLES = ["viewer", "developer", "admin", "owner"];

export function ProjectSharingModal({ projectId, teamId, onClose }) {
  const t = useTranslations("StudioSharing");
  const tRole = useTranslations("Teams.roleLabel");

  const [teamMembers, setTeamMembers] = useState(null);
  const [overrides, setOverrides] = useState(null);
  const [selectedUserId, setSelectedUserId] = useState("");
  const [selectedRole, setSelectedRole] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  async function load() {
    try {
      const [members, projectMembers] = await Promise.all([
        apiFetch(`/teams/${teamId}/members`),
        apiFetch(`/projects/${projectId}/members`),
      ]);
      setTeamMembers(members);
      setOverrides(projectMembers);
    } catch (err) {
      setError(err.message || t("error"));
    }
  }

  useEffect(() => {
    void Promise.resolve().then(load);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId, teamId]);

  async function addMember(e) {
    e.preventDefault();
    if (!selectedUserId) return;
    setBusy(true);
    setError(null);
    try {
      await apiFetch(`/projects/${projectId}/members`, {
        method: "POST",
        body: JSON.stringify({ user_id: Number(selectedUserId), role: selectedRole || undefined }),
      });
      setSelectedUserId("");
      setSelectedRole("");
      await load();
    } catch (err) {
      setError(err.message || t("error"));
    } finally {
      setBusy(false);
    }
  }

  async function removeOverride(memberId) {
    setBusy(true);
    setError(null);
    try {
      await apiFetch(`/projects/${projectId}/members/${memberId}`, { method: "DELETE" });
      await load();
    } catch (err) {
      setError(err.message || t("error"));
    } finally {
      setBusy(false);
    }
  }

  const overriddenUserIds = new Set((overrides || []).map((o) => o.user_id));
  const availableTeamMembers = (teamMembers || []).filter((m) => !overriddenUserIds.has(m.user_id));

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div className="bg-dp-editor-bg text-dp-editor-text rounded-xl border border-dp-editor-border w-full max-w-md max-h-[85vh] overflow-y-auto flex flex-col">
        <div className="flex items-center justify-between px-4 py-3 border-b border-dp-editor-border">
          <span className="flex items-center gap-2 text-sm font-semibold">
            <Share2 size={15} className="text-dp-accent" />
            {t("heading")}
          </span>
          <button type="button" onClick={onClose} className="text-dp-editor-muted hover:text-dp-editor-text">
            <X size={16} />
          </button>
        </div>

        <div className="p-4 flex flex-col gap-4">
          <p className="text-[12px] text-dp-editor-muted">{t("subheading")}</p>

          <div className="text-[12px] font-medium text-dp-editor-text">
            {overrides?.length ? t("restricted", { count: overrides.length }) : t("wholeTeam")}
          </div>

          {overrides === null ? (
            <p className="text-[12px] text-dp-editor-muted">{t("loading")}</p>
          ) : (
            <div className="flex flex-col gap-1.5">
              {overrides.map((o) => (
                <div key={o.id} className="flex items-center gap-2 text-[12px] border border-dp-editor-border rounded-lg px-3 py-2">
                  <span className="flex-1 truncate">{o.user?.name}</span>
                  <span className="text-dp-editor-muted">{o.role ? tRole(o.role) : t("useTeamRole")}</span>
                  <button type="button" disabled={busy} onClick={() => removeOverride(o.id)} className="text-dp-editor-muted hover:text-red-400 disabled:opacity-40">
                    <Trash2 size={13} />
                  </button>
                </div>
              ))}
            </div>
          )}

          <form onSubmit={addMember} className="flex flex-col gap-2 border-t border-dp-editor-border pt-3">
            <h3 className="text-[11px] font-semibold uppercase tracking-wider text-dp-editor-muted">{t("addMember")}</h3>
            {availableTeamMembers.length === 0 ? (
              <p className="text-[12px] text-dp-editor-muted italic">{t("noOtherMembers")}</p>
            ) : (
              <>
                <select
                  value={selectedUserId}
                  onChange={(e) => setSelectedUserId(e.target.value)}
                  className="rounded-lg border border-dp-editor-border bg-dp-editor-overlay px-2.5 py-2 text-[12px] outline-none"
                >
                  <option value="">{t("selectMember")}</option>
                  {availableTeamMembers.map((m) => (
                    <option key={m.user_id} value={m.user_id}>
                      {m.user?.name} — {tRole(m.role)}
                    </option>
                  ))}
                </select>
                <select
                  value={selectedRole}
                  onChange={(e) => setSelectedRole(e.target.value)}
                  className="rounded-lg border border-dp-editor-border bg-dp-editor-overlay px-2.5 py-2 text-[12px] outline-none"
                >
                  <option value="">{t("useTeamRole")}</option>
                  {ROLES.map((r) => (
                    <option key={r} value={r}>
                      {tRole(r)}
                    </option>
                  ))}
                </select>
                <button
                  type="submit"
                  disabled={busy || !selectedUserId}
                  className="self-start text-xs font-semibold px-3 py-1.5 rounded-lg bg-dp-accent text-white disabled:opacity-40"
                >
                  {t("add")}
                </button>
              </>
            )}
          </form>

          {error && <p className="text-[11px] text-red-400">{error}</p>}
        </div>
      </div>
    </div>
  );
}
