"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronRight, Sparkles, X } from "lucide-react";
import { Link } from "@/i18n/navigation";
import { MODULES } from "@/lib/constants";
import { useAuth } from "@/lib/auth-context";
import { useProject } from "@/lib/project-context";
import { MayaAvatar } from "@/components/MayaAvatar";
import { ProjectSwitcher } from "@/components/ProjectSwitcher";
import { WelcomeModal } from "@/components/WelcomeModal";
import { OnboardingChecklist } from "@/components/OnboardingChecklist";

function CreateProjectForm({ firstName, onDone }) {
  const t = useTranslations("Dashboard.createProject");
  const { createProject, createDemoProject } = useProject();
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [demoLoading, setDemoLoading] = useState(false);
  const [error, setError] = useState("");

  async function handleSubmit(e) {
    e.preventDefault();
    setError("");
    setSubmitting(true);
    try {
      await createProject(title, description);
      onDone?.();
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDemo() {
    setError("");
    setDemoLoading(true);
    try {
      await createDemoProject();
      onDone?.();
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setDemoLoading(false);
    }
  }

  return (
    <div className="bg-dp-panel rounded-3xl shadow-[0_8px_30px_rgba(0,0,0,0.04)] p-8">
      <MayaAvatar className="w-16 h-16 mb-4" />
      <div className="text-xl font-bold mb-1 tracking-tight">{t("heading", { name: firstName })}</div>
      <p className="text-sm text-dp-muted mb-6">{t("subheading")}</p>
      <form onSubmit={handleSubmit} className="flex flex-col gap-3">
        <input
          required
          placeholder={t("titlePlaceholder")}
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          className="rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-3 text-sm w-full outline-none transition-colors"
        />
        <textarea
          placeholder={t("descriptionPlaceholder")}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          className="rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-3 text-sm w-full outline-none transition-colors"
          rows={3}
        />
        {error && <p className="text-xs text-red-500">{error}</p>}
        <button
          type="submit"
          disabled={submitting || demoLoading}
          className="text-sm font-semibold px-5 py-3 rounded-full mt-1 bg-dp-solid text-dp-on-solid hover:opacity-90 disabled:opacity-50 transition-colors"
        >
          {submitting ? t("submitting") : t("submit")}
        </button>
      </form>
      <div className="flex items-center gap-3 my-4">
        <div className="flex-1 h-px bg-dp-faint" />
        <span className="text-xs text-dp-muted uppercase tracking-wider">{t("orDivider")}</span>
        <div className="flex-1 h-px bg-dp-faint" />
      </div>
      <button
        type="button"
        onClick={handleDemo}
        disabled={submitting || demoLoading}
        className="flex items-center justify-center gap-2 text-sm font-semibold px-5 py-3 rounded-full border border-dp-border hover:bg-dp-faint transition-colors w-full disabled:opacity-50"
      >
        <Sparkles size={15} />
        {demoLoading ? t("demoLoading") : t("tryDemo")}
      </button>
    </div>
  );
}

export default function DashboardPage() {
  const t = useTranslations("Dashboard");
  const tCommon = useTranslations("Common");
  const tModules = useTranslations("Modules");
  const tMaya = useTranslations("Dashboard.maya");
  const { user, completeOnboarding } = useAuth();
  const { project, loading: projectLoading } = useProject();
  const [creating, setCreating] = useState(false);
  const [dismissingWelcome, setDismissingWelcome] = useState(false);

  if (!user || projectLoading) {
    return (
      <div className="max-w-5xl mx-auto w-full px-4 sm:px-6 pt-20 text-sm text-dp-muted">
        {tCommon("loading")}
      </div>
    );
  }

  const firstName = user.name.split(" ")[0];

  async function dismissWelcome() {
    setDismissingWelcome(true);
    try {
      await completeOnboarding();
    } finally {
      setDismissingWelcome(false);
    }
  }

  const welcomeModal = !user.onboarding_completed_at && (
    <WelcomeModal firstName={firstName} onDismiss={dismissWelcome} dismissing={dismissingWelcome} />
  );

  if (!project) {
    return (
      <div className="max-w-md mx-auto w-full px-4 sm:px-6 pt-16 sm:pt-20">
        <CreateProjectForm firstName={firstName} />
        {welcomeModal}
      </div>
    );
  }

  if (creating) {
    return (
      <div className="fixed inset-0 z-50 bg-black/40 flex items-center justify-center px-4">
        <div className="w-full max-w-md relative">
          <button
            onClick={() => setCreating(false)}
            className="absolute -top-11 right-0 text-white/80 hover:text-white transition-colors"
            aria-label={tCommon("close")}
          >
            <X size={22} />
          </button>
          <CreateProjectForm firstName={firstName} onDone={() => setCreating(false)} />
        </div>
      </div>
    );
  }

  const completedCount = project.modules.filter((m) => m.status === "completed").length;
  const pct = Math.round((completedCount / MODULES.length) * 100);
  const moduleByType = Object.fromEntries(project.modules.map((m) => [m.module_type, m]));
  const nextModule = MODULES.find((m) => moduleByType[m.id]?.status !== "completed") || MODULES[0];
  const allDone = pct === 100;

  return (
    <div className="max-w-5xl mx-auto w-full px-4 sm:px-6 pt-8">
      <div className="mb-8 flex items-start justify-between gap-4 flex-wrap">
        <div>
          <div className="text-[11px] font-semibold text-dp-muted uppercase tracking-wider">{t("kicker")}</div>
          <div className="text-2xl font-bold tracking-tight mt-0.5">{project.title}</div>
        </div>
        <ProjectSwitcher onCreateClick={() => setCreating(true)} />
      </div>

      <div className="bg-dp-panel rounded-[2rem] p-6 sm:p-8 shadow-[0_8px_30px_rgba(0,0,0,0.04)] mb-10 flex flex-col sm:flex-row items-center gap-6 text-center sm:text-left">
        <MayaAvatar className="w-20 h-20 sm:w-24 sm:h-24 flex-shrink-0" />
        <div className="flex-1">
          <h1 className="text-xl sm:text-2xl font-bold tracking-tight mb-1.5">{tMaya("heading", { name: firstName })}</h1>
          <p className="text-sm text-dp-muted mb-4 max-w-md">
            {allDone ? tMaya("allDoneBody") : pct > 0 ? tMaya("progressBody", { pct }) : tMaya("startBody")}
          </p>
          <div className="w-full sm:w-64 h-1.5 bg-dp-faint rounded-full overflow-hidden mb-5">
            <div className="h-full bg-dp-accent rounded-full transition-[width] duration-300" style={{ width: `${pct}%` }} />
          </div>
          <Link
            href={allDone ? "/review" : `/modules/${nextModule.id}`}
            className="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-full bg-dp-solid text-dp-on-solid text-sm font-semibold hover:opacity-90 transition-colors"
          >
            {allDone ? tMaya("ctaStudio") : pct > 0 ? tMaya("ctaContinue") : tMaya("ctaStart")} <ChevronRight size={16} />
          </Link>
        </div>
      </div>

      <OnboardingChecklist project={project} modulesDone={completedCount} totalModules={MODULES.length} />

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 pb-10">
        {MODULES.map((m) => {
          const backendModule = moduleByType[m.id];
          const isDone = backendModule?.status === "completed";
          return (
            <Link
              key={m.id}
              href={`/modules/${m.id}`}
              className={`group block text-left bg-dp-panel rounded-2xl border p-5 transition-all hover:-translate-y-0.5 hover:shadow-[0_4px_20px_rgba(0,0,0,0.05)] ${
                isDone ? "border-dp-green/30" : "border-dp-border"
              }`}
            >
              <div className="flex justify-between items-start mb-4">
                <div
                  className={`w-10 h-10 rounded-xl flex items-center justify-center ${
                    isDone ? "bg-dp-green-bg text-dp-green" : "bg-dp-faint text-dp-muted-2"
                  }`}
                >
                  <m.Icon size={20} strokeWidth={1.6} />
                </div>
                <span className="text-[11px] font-bold text-dp-border">{String(m.n).padStart(2, "0")}</span>
              </div>
              <div className="text-base font-semibold tracking-tight mb-1">{tModules(`${m.id}.title`)}</div>
              <div className="text-xs text-dp-muted leading-relaxed mb-4">{tModules(`${m.id}.sub`)}</div>
              <div className="flex items-center justify-between">
                <span
                  className={`text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-md ${
                    isDone ? "bg-dp-green-bg text-dp-green" : "bg-dp-faint text-dp-muted"
                  }`}
                >
                  {isDone ? t("statusDone") : t("statusTodo")}
                </span>
                <ChevronRight
                  size={16}
                  className={`transition-transform group-hover:translate-x-1 ${isDone ? "text-dp-green" : "text-dp-border"}`}
                />
              </div>
            </Link>
          );
        })}
      </div>
      {welcomeModal}
    </div>
  );
}
