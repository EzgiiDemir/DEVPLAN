"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Sun, Moon, LogOut, CreditCard, FolderKanban, Check, ShieldCheck, Monitor, Trash2 } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { useProject } from "@/lib/project-context";
import { useTheme } from "@/lib/theme-context";
import { GithubIcon } from "@/components/icons/GithubIcon";

const PLANS = ["free", "pro", "team"];

function SettingsSection({ title, children }) {
  return (
    <section className="bg-dp-panel rounded-2xl border border-dp-border p-6 mb-5">
      <h2 className="text-sm font-semibold uppercase tracking-wider text-dp-muted mb-4">{title}</h2>
      {children}
    </section>
  );
}

function TwoFactorSection() {
  const t = useTranslations("Settings");
  const [status, setStatus] = useState(null);
  const [setup, setSetup] = useState(null);
  const [code, setCode] = useState("");
  const [recoveryCodes, setRecoveryCodes] = useState(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  function load() {
    apiFetch("/security/two-factor").then(setStatus).catch(() => setStatus({ enabled: false }));
  }

  useEffect(() => {
    load();
  }, []);

  async function startEnable() {
    setError("");
    setBusy(true);
    try {
      setSetup(await apiFetch("/security/two-factor/generate", { method: "POST" }));
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setBusy(false);
    }
  }

  async function confirm(e) {
    e.preventDefault();
    setError("");
    setBusy(true);
    try {
      const result = await apiFetch("/security/two-factor/confirm", {
        method: "POST",
        body: JSON.stringify({ code }),
      });
      setRecoveryCodes(result.recovery_codes);
      setCode("");
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setBusy(false);
    }
  }

  function finishSetup() {
    setSetup(null);
    setRecoveryCodes(null);
    load();
  }

  async function disable() {
    setBusy(true);
    try {
      await apiFetch("/security/two-factor", { method: "DELETE" });
      load();
    } finally {
      setBusy(false);
    }
  }

  if (!status) return null;

  return (
    <SettingsSection title={t("twoFactorHeading")}>
      <p className="text-xs text-dp-muted mb-4">{t("twoFactorBody")}</p>

      {recoveryCodes ? (
        <div className="flex flex-col gap-3">
          <p className="text-sm font-semibold text-dp-green">{t("twoFactorConfirmed")}</p>
          <div className="bg-dp-faint rounded-xl p-4">
            <p className="text-xs font-semibold mb-2">{t("recoveryCodesHeading")}</p>
            <p className="text-xs text-dp-muted mb-3">{t("recoveryCodesBody")}</p>
            <div className="grid grid-cols-2 gap-2 font-mono text-sm">
              {recoveryCodes.map((c) => (
                <span key={c}>{c}</span>
              ))}
            </div>
          </div>
          <button
            onClick={finishSetup}
            className="self-start text-sm font-semibold px-4 py-2.5 rounded-full bg-dp-solid text-dp-on-solid hover:opacity-90 transition-colors"
          >
            {t("done")}
          </button>
        </div>
      ) : setup ? (
        <form onSubmit={confirm} className="flex flex-col gap-3">
          <div className="bg-dp-faint rounded-xl p-4 text-xs font-mono break-all">
            <p className="text-dp-muted mb-1 font-sans">{t("twoFactorSecretLabel")}</p>
            {setup.secret}
          </div>
          <input
            autoFocus
            required
            placeholder={t("twoFactorCodeLabel")}
            value={code}
            onChange={(e) => setCode(e.target.value)}
            className="rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-2.5 text-sm w-full outline-none transition-colors"
          />
          {error && <p className="text-xs text-red-500">{error}</p>}
          <button
            type="submit"
            disabled={busy}
            className="self-start text-sm font-semibold px-4 py-2.5 rounded-full bg-dp-solid text-dp-on-solid hover:opacity-90 disabled:opacity-50 transition-colors"
          >
            {t("confirm")}
          </button>
        </form>
      ) : (
        <div className="flex items-center gap-3">
          <ShieldCheck size={16} className={status.enabled ? "text-dp-green" : "text-dp-muted-2"} />
          <span className="text-sm flex-1">{status.enabled ? t("twoFactorEnabled") : t("twoFactorDisabled")}</span>
          <button
            onClick={status.enabled ? disable : startEnable}
            disabled={busy}
            className={`text-sm font-semibold px-4 py-2 rounded-full transition-colors disabled:opacity-50 ${
              status.enabled ? "text-red-500 hover:bg-red-500/10" : "bg-dp-solid text-dp-on-solid hover:opacity-90"
            }`}
          >
            {status.enabled ? t("disable") : t("enable")}
          </button>
        </div>
      )}
    </SettingsSection>
  );
}

function SessionsSection() {
  const t = useTranslations("Settings");
  const [sessions, setSessions] = useState(null);

  function load() {
    apiFetch("/security/sessions").then(setSessions).catch(() => setSessions([]));
  }

  useEffect(() => {
    load();
  }, []);

  async function revoke(id) {
    await apiFetch(`/security/sessions/${id}`, { method: "DELETE" });
    load();
  }

  async function revokeOthers() {
    await apiFetch("/security/sessions/others", { method: "DELETE" });
    load();
  }

  if (!sessions) return null;

  return (
    <SettingsSection title={t("sessionsHeading")}>
      {sessions.length === 0 ? (
        <p className="text-xs text-dp-muted">{t("sessionsEmpty")}</p>
      ) : (
        <div className="flex flex-col gap-2 mb-3">
          {sessions.map((s) => (
            <div key={s.id} className="flex items-center gap-3 p-3 rounded-xl bg-dp-faint">
              <Monitor size={16} className="text-dp-muted-2 flex-shrink-0" />
              <div className="flex-1 min-w-0">
                <div className="text-sm font-medium truncate">
                  {s.user_agent || t("currentSession")} {s.is_current && <span className="text-dp-accent-strong">· {t("currentSession")}</span>}
                </div>
                <div className="text-xs text-dp-muted">{t("lastActive", { when: new Date(s.last_active_at).toLocaleString() })}</div>
              </div>
              {!s.is_current && (
                <button onClick={() => revoke(s.id)} className="text-dp-muted hover:text-red-500 transition-colors flex-shrink-0">
                  <Trash2 size={14} />
                </button>
              )}
            </div>
          ))}
        </div>
      )}
      {sessions.some((s) => !s.is_current) && (
        <button onClick={revokeOthers} className="text-xs font-semibold text-red-500 hover:text-red-600 transition-colors">
          {t("revokeAllOthers")}
        </button>
      )}
    </SettingsSection>
  );
}

function ProfileForm() {
  const t = useTranslations("Settings");
  const { user, updateName } = useAuth();
  const [name, setName] = useState(user?.name ?? "");
  const [submitting, setSubmitting] = useState(false);
  const [status, setStatus] = useState("");

  async function handleSubmit(e) {
    e.preventDefault();
    setStatus("");
    setSubmitting(true);
    try {
      await updateName(name);
      setStatus("success");
    } catch (err) {
      setStatus(err.message || t("genericError"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-3 mb-3">
      <div>
        <div className="text-xs text-dp-muted mb-1">{t("nameLabel")}</div>
        <input
          value={name}
          onChange={(e) => setName(e.target.value)}
          required
          className="rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-2.5 text-sm w-full outline-none transition-colors"
        />
      </div>
      <div>
        <div className="text-xs text-dp-muted mb-1">{t("emailLabel")}</div>
        <div className="text-sm font-medium px-4 py-2.5">{user?.email}</div>
      </div>
      <div className="flex items-center gap-3">
        <button
          type="submit"
          disabled={submitting || name === user?.name}
          className="text-sm font-semibold px-4 py-2.5 rounded-full bg-dp-solid text-dp-on-solid hover:opacity-90 disabled:opacity-50 transition-colors"
        >
          {submitting ? t("saving") : t("save")}
        </button>
        {status === "success" && <span className="text-xs text-dp-green">{t("saved")}</span>}
        {status && status !== "success" && <span className="text-xs text-red-500">{status}</span>}
      </div>
    </form>
  );
}

function PasswordForm() {
  const t = useTranslations("Settings");
  const { updatePassword } = useAuth();
  const [currentPassword, setCurrentPassword] = useState("");
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [status, setStatus] = useState("");

  async function handleSubmit(e) {
    e.preventDefault();
    setStatus("");
    setSubmitting(true);
    try {
      await updatePassword(currentPassword, password, confirm);
      setCurrentPassword("");
      setPassword("");
      setConfirm("");
      setStatus("success");
    } catch (err) {
      setStatus(err.message || t("genericError"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-3">
      <input
        type="password"
        required
        placeholder={t("currentPassword")}
        value={currentPassword}
        onChange={(e) => setCurrentPassword(e.target.value)}
        className="rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-2.5 text-sm w-full outline-none transition-colors"
      />
      <input
        type="password"
        required
        minLength={8}
        placeholder={t("newPassword")}
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        className="rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-2.5 text-sm w-full outline-none transition-colors"
      />
      <input
        type="password"
        required
        placeholder={t("confirmPassword")}
        value={confirm}
        onChange={(e) => setConfirm(e.target.value)}
        className="rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-2.5 text-sm w-full outline-none transition-colors"
      />
      <div className="flex items-center gap-3">
        <button
          type="submit"
          disabled={submitting}
          className="text-sm font-semibold px-4 py-2.5 rounded-full bg-dp-solid text-dp-on-solid hover:opacity-90 disabled:opacity-50 transition-colors"
        >
          {submitting ? t("saving") : t("changePassword")}
        </button>
        {status === "success" && <span className="text-xs text-dp-green">{t("saved")}</span>}
        {status && status !== "success" && <span className="text-xs text-red-500">{status}</span>}
      </div>
    </form>
  );
}

export default function SettingsPage() {
  const t = useTranslations("Settings");
  const { logout, user } = useAuth();
  const { project, projects, switchProject } = useProject();
  const { theme, setTheme } = useTheme();
  const [subscription, setSubscription] = useState(null);
  const [planUpdating, setPlanUpdating] = useState(false);

  useEffect(() => {
    apiFetch("/subscription").then(setSubscription).catch(() => setSubscription(null));
  }, []);

  async function selectPlan(plan) {
    if (plan === subscription?.plan) return;
    setPlanUpdating(true);
    try {
      const updated = await apiFetch("/subscription", {
        method: "PATCH",
        body: JSON.stringify({ plan }),
      });
      setSubscription(updated);
    } finally {
      setPlanUpdating(false);
    }
  }

  return (
    <div className="max-w-2xl mx-auto w-full px-4 sm:px-6 py-10 sm:py-14">
      <h1 className="text-2xl sm:text-3xl font-bold tracking-tight mb-8">{t("heading")}</h1>

      <SettingsSection title={t("profileHeading")}>
        <ProfileForm />
      </SettingsSection>

      <SettingsSection title={t("passwordHeading")}>
        <PasswordForm />
      </SettingsSection>

      {user?.oauth_provider === "github" && (
        <SettingsSection title={t("githubHeading")}>
          <div className="flex items-center gap-3">
            <GithubIcon size={16} className="text-dp-muted-2 flex-shrink-0" />
            <span className="text-sm">{t("githubConnected")}</span>
          </div>
        </SettingsSection>
      )}

      <TwoFactorSection />
      <SessionsSection />

      <SettingsSection title={t("appearanceHeading")}>
        <p className="text-xs text-dp-muted mb-4">{t("appearanceBody")}</p>
        <div className="flex gap-2">
          <button
            onClick={() => setTheme("light")}
            className={`flex items-center gap-2 px-4 py-2.5 rounded-full text-sm font-medium border transition-colors ${
              theme === "light" ? "bg-dp-solid text-dp-on-solid border-dp-solid" : "border-dp-border text-dp-muted hover:text-dp-ink"
            }`}
          >
            <Sun size={15} /> {t("themeLight")}
          </button>
          <button
            onClick={() => setTheme("dark")}
            className={`flex items-center gap-2 px-4 py-2.5 rounded-full text-sm font-medium border transition-colors ${
              theme === "dark" ? "bg-dp-solid text-dp-on-solid border-dp-solid" : "border-dp-border text-dp-muted hover:text-dp-ink"
            }`}
          >
            <Moon size={15} /> {t("themeDark")}
          </button>
        </div>
      </SettingsSection>

      <SettingsSection title={t("projectsHeading")}>
        {projects.length > 0 ? (
          <div className="flex flex-col gap-2">
            {projects.map((p) => (
              <button
                key={p.id}
                onClick={() => switchProject(p.id)}
                className={`flex items-center gap-3 p-3 rounded-xl text-left transition-colors ${
                  p.id === project?.id ? "bg-dp-faint" : "hover:bg-dp-faint"
                }`}
              >
                <FolderKanban size={16} className="text-dp-muted-2 flex-shrink-0" />
                <span className="text-sm font-medium flex-1">{p.title}</span>
                {p.id === project?.id && <Check size={15} className="text-dp-accent-strong flex-shrink-0" />}
              </button>
            ))}
          </div>
        ) : (
          <p className="text-xs text-dp-muted">{t("projectsEmpty")}</p>
        )}
      </SettingsSection>

      <SettingsSection title={t("billingHeading")}>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
          {PLANS.map((plan) => {
            const active = subscription?.plan === plan;
            return (
              <button
                key={plan}
                onClick={() => selectPlan(plan)}
                disabled={planUpdating}
                className={`text-left p-4 rounded-xl border transition-colors disabled:opacity-60 ${
                  active ? "border-dp-solid bg-dp-faint" : "border-dp-border hover:border-dp-accent/40"
                }`}
              >
                <div className="flex items-center justify-between mb-1">
                  <span className="text-sm font-semibold">{t(`plans.${plan}.name`)}</span>
                  {active && <Check size={14} className="text-dp-accent-strong" />}
                </div>
                <div className="text-xs text-dp-muted mb-1">{t(`plans.${plan}.price`)}</div>
                <div className="text-[11px] text-dp-muted leading-relaxed">{t(`plans.${plan}.body`)}</div>
              </button>
            );
          })}
        </div>
        <div className="flex items-center gap-3 p-3 rounded-xl bg-dp-faint mb-3">
          <CreditCard size={16} className="text-dp-muted-2 flex-shrink-0" />
          <span className="text-sm font-medium flex-1">{t("paymentMethodEmpty")}</span>
          <span className="text-xs font-semibold text-dp-muted">{t("comingSoon")}</span>
        </div>
        <p className="text-xs text-dp-muted italic">{t("billingHistoryEmpty")}</p>
      </SettingsSection>

      <button
        onClick={logout}
        className="flex items-center gap-2 text-sm font-medium text-red-500 hover:text-red-600 transition-colors px-1"
      >
        <LogOut size={15} /> {t("logout")}
      </button>
    </div>
  );
}
