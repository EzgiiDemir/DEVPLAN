"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronDown, ChevronRight, Plus, Trash2, UserPlus, Users } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";

const ROLES = ["viewer", "developer", "admin", "owner"];

function SettingsSection({ title, children }) {
  return (
    <section className="bg-dp-panel rounded-2xl border border-dp-border p-6 mb-5">
      <h2 className="text-sm font-semibold uppercase tracking-wider text-dp-muted mb-4">{title}</h2>
      {children}
    </section>
  );
}

function InviteForm({ teamId, onInvited }) {
  const t = useTranslations("Teams");
  const [email, setEmail] = useState("");
  const [role, setRole] = useState("developer");
  const [submitting, setSubmitting] = useState(false);
  const [pending, setPending] = useState(null);
  const [error, setError] = useState("");

  async function handleSubmit(e) {
    e.preventDefault();
    setSubmitting(true);
    setError("");
    try {
      const invitation = await apiFetch(`/teams/${teamId}/members/invite`, {
        method: "POST",
        body: JSON.stringify({ email, role }),
      });
      setPending(invitation);
      setEmail("");
      onInvited?.();
    } catch (err) {
      setError(err.message || t("error"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col sm:flex-row gap-2">
      <input
        type="email"
        required
        placeholder={t("emailPlaceholder")}
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        className="flex-1 rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-3.5 py-2.5 text-sm outline-none transition-colors"
      />
      <select
        value={role}
        onChange={(e) => setRole(e.target.value)}
        className="rounded-xl border border-dp-border bg-dp-faint px-3 py-2.5 text-sm outline-none"
      >
        {ROLES.map((r) => (
          <option key={r} value={r}>
            {t(`roleLabel.${r}`)}
          </option>
        ))}
      </select>
      <button
        type="submit"
        disabled={submitting}
        className="flex items-center justify-center gap-1.5 text-sm font-semibold px-4 py-2.5 rounded-full bg-dp-solid text-dp-on-solid hover:opacity-90 disabled:opacity-50 transition-colors"
      >
        <UserPlus size={14} /> {t("invite")}
      </button>
      {pending && <p className="text-xs text-dp-green sm:col-span-2">{t("invitePending", { email: pending.email, role: t(`roleLabel.${pending.role}`) })}</p>}
      {error && <p className="text-xs text-red-500 sm:col-span-2">{error}</p>}
    </form>
  );
}

function MemberRow({ teamId, member, canManage, currentUserId, onChanged }) {
  const t = useTranslations("Teams");
  const [busy, setBusy] = useState(false);

  async function changeRole(role) {
    setBusy(true);
    try {
      await apiFetch(`/teams/${teamId}/members/${member.id}`, {
        method: "PATCH",
        body: JSON.stringify({ role }),
      });
      onChanged();
    } catch (err) {
      window.alert(err.message || t("error"));
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (!window.confirm(t("confirmRemove", { name: member.user?.name }))) return;
    setBusy(true);
    try {
      await apiFetch(`/teams/${teamId}/members/${member.id}`, { method: "DELETE" });
      onChanged();
    } catch (err) {
      window.alert(err.message || t("error"));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex items-center gap-3 py-2.5 border-b border-dp-border last:border-0">
      <div className="flex-1 min-w-0">
        <div className="text-sm font-medium truncate">
          {member.user?.name} {member.user_id === currentUserId && <span className="text-dp-muted">{t("youLabel")}</span>}
        </div>
        <div className="text-xs text-dp-muted truncate">{member.user?.email}</div>
      </div>
      {canManage ? (
        <>
          <select
            value={member.role}
            disabled={busy}
            onChange={(e) => changeRole(e.target.value)}
            className="rounded-lg border border-dp-border bg-dp-faint px-2 py-1.5 text-xs outline-none disabled:opacity-50"
          >
            {ROLES.map((r) => (
              <option key={r} value={r}>
                {t(`roleLabel.${r}`)}
              </option>
            ))}
          </select>
          <button
            onClick={remove}
            disabled={busy}
            className="text-dp-muted hover:text-red-500 transition-colors disabled:opacity-50"
            aria-label={t("removeMember")}
          >
            <Trash2 size={14} />
          </button>
        </>
      ) : (
        <span className="text-xs font-semibold text-dp-muted">{t(`roleLabel.${member.role}`)}</span>
      )}
    </div>
  );
}

function TeamCard({ team, currentUserId, onDeleted }) {
  const t = useTranslations("Teams");
  const [open, setOpen] = useState(false);
  const [members, setMembers] = useState(null);
  const [loadingMembers, setLoadingMembers] = useState(false);

  const canManage = team.role === "admin" || team.role === "owner";

  async function loadMembers() {
    setLoadingMembers(true);
    try {
      setMembers(await apiFetch(`/teams/${team.id}/members`));
    } catch {
      setMembers([]);
    } finally {
      setLoadingMembers(false);
    }
  }

  function toggle() {
    const next = !open;
    setOpen(next);
    if (next && !members) loadMembers();
  }

  async function deleteTeam() {
    if (!window.confirm(t("confirmDeleteTeam"))) return;
    try {
      await apiFetch(`/teams/${team.id}`, { method: "DELETE" });
      onDeleted();
    } catch (err) {
      window.alert(err.message || t("error"));
    }
  }

  return (
    <div className="border border-dp-border rounded-2xl overflow-hidden mb-3">
      <button onClick={toggle} className="w-full flex items-center gap-3 p-4 text-left hover:bg-dp-faint transition-colors">
        {open ? <ChevronDown size={15} className="text-dp-muted flex-shrink-0" /> : <ChevronRight size={15} className="text-dp-muted flex-shrink-0" />}
        <Users size={16} className="text-dp-muted-2 flex-shrink-0" />
        <span className="text-sm font-semibold flex-1">{team.name}</span>
        {team.personal ? (
          <span className="text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded-md bg-dp-faint text-dp-muted">
            {t("personalBadge")}
          </span>
        ) : (
          <span className="text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded-md bg-dp-accent-tint text-dp-accent-strong">
            {t(`roleLabel.${team.role}`)}
          </span>
        )}
        <span className="text-xs text-dp-muted">{team.members_count}</span>
      </button>

      {open && (
        <div className="p-4 pt-0">
          {loadingMembers && <p className="text-xs text-dp-muted">{t("loading")}</p>}
          {members && (
            <div className="mb-4">
              {members.map((m) => (
                <MemberRow key={m.id} teamId={team.id} member={m} canManage={canManage} currentUserId={currentUserId} onChanged={loadMembers} />
              ))}
            </div>
          )}

          {canManage && (
            <>
              <h3 className="text-xs font-semibold uppercase tracking-wider text-dp-muted mb-2">{t("inviteHeading")}</h3>
              <InviteForm teamId={team.id} onInvited={loadMembers} />
            </>
          )}

          {team.role === "owner" && (
            <button
              onClick={deleteTeam}
              className="mt-4 flex items-center gap-1.5 text-xs font-medium text-red-500 hover:text-red-600 transition-colors"
            >
              <Trash2 size={12} /> {t("deleteTeam")}
            </button>
          )}
        </div>
      )}
    </div>
  );
}

export default function TeamsPage() {
  const t = useTranslations("Teams");
  const { user } = useAuth();
  const [teams, setTeams] = useState(null);
  const [creating, setCreating] = useState(false);
  const [newName, setNewName] = useState("");
  const [error, setError] = useState("");

  async function loadTeams() {
    try {
      setTeams(await apiFetch("/teams"));
    } catch (err) {
      setError(err.message || t("error"));
    }
  }

  useEffect(() => {
    void Promise.resolve().then(loadTeams);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function createTeam(e) {
    e.preventDefault();
    setCreating(true);
    setError("");
    try {
      await apiFetch("/teams", { method: "POST", body: JSON.stringify({ name: newName }) });
      setNewName("");
      await loadTeams();
    } catch (err) {
      setError(err.message || t("error"));
    } finally {
      setCreating(false);
    }
  }

  return (
    <div className="max-w-2xl mx-auto w-full px-4 sm:px-6 py-10 sm:py-14">
      <h1 className="text-2xl sm:text-3xl font-bold tracking-tight mb-1">{t("heading")}</h1>
      <p className="text-sm text-dp-muted mb-8">{t("subheading")}</p>

      {teams === null && <p className="text-sm text-dp-muted">{t("loading")}</p>}

      {teams?.map((team) => (
        <TeamCard key={team.id} team={team} currentUserId={user?.id} onDeleted={loadTeams} />
      ))}

      <SettingsSection title={t("createTeam")}>
        <form onSubmit={createTeam} className="flex gap-2">
          <input
            required
            placeholder={t("teamNamePlaceholder")}
            value={newName}
            onChange={(e) => setNewName(e.target.value)}
            className="flex-1 rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-2.5 text-sm outline-none transition-colors"
          />
          <button
            type="submit"
            disabled={creating}
            className="flex items-center gap-1.5 text-sm font-semibold px-4 py-2.5 rounded-full bg-dp-solid text-dp-on-solid hover:opacity-90 disabled:opacity-50 transition-colors"
          >
            <Plus size={14} /> {t("create")}
          </button>
        </form>
        {error && <p className="text-xs text-red-500 mt-2">{error}</p>}
      </SettingsSection>
    </div>
  );
}
