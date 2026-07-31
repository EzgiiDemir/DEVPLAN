"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { CheckCircle2, Circle, ChevronRight, ChevronLeft, ShieldCheck, AlertTriangle, Loader2, Rocket } from "lucide-react";
import { Link, useRouter } from "@/i18n/navigation";
import { useProject } from "@/lib/project-context";
import { apiFetch } from "@/lib/api";
import { pollAiJob } from "@/lib/aiJobs";
import { MODULES } from "@/lib/constants";

const SEVERITY_STYLES = {
  high: "text-red-500",
  medium: "text-amber-500",
  low: "text-dp-muted",
};

export default function ReviewPage() {
  const t = useTranslations("Review");
  const tModules = useTranslations("Modules");
  const router = useRouter();
  const { project } = useProject();
  const [validating, setValidating] = useState(false);
  const [hasValidated, setHasValidated] = useState(false);
  const [issues, setIssues] = useState([]);
  const [error, setError] = useState(null);

  if (!project) return null;

  const moduleByType = Object.fromEntries(project.modules.map((m) => [m.module_type, m]));
  const techStack = moduleByType.tech_stack?.items?.find((i) => i.item_type === "tech_stack")?.content;
  const sprintPlan = moduleByType.task_plan?.items?.find((i) => i.item_type === "sprint_plan")?.content;
  const sprintCount = sprintPlan?.sprints?.length || 0;
  const completedCount = project.modules.filter((m) => m.status === "completed").length;

  const stackChips = [
    techStack?.frontend?.selected,
    techStack?.backend?.selected,
    techStack?.database?.selected,
    techStack?.hosting?.selected,
  ].filter(Boolean);

  async function runValidation() {
    setValidating(true);
    setError(null);
    try {
      const { job_id } = await apiFetch(`/projects/${project.id}/review/validate`, { method: "POST" });
      const result = await pollAiJob(job_id);
      setIssues(result.issues || []);
      setHasValidated(true);
    } catch (err) {
      setError(err.message || t("validationError"));
    } finally {
      setValidating(false);
    }
  }

  return (
    <div className="max-w-4xl mx-auto w-full px-4 sm:px-6 py-10">
      <Link href="/dashboard" className="inline-flex items-center gap-1 text-xs font-semibold text-dp-muted hover:text-dp-ink mb-4">
        <ChevronLeft size={14} /> {t("backToDashboard")}
      </Link>

      <h1 className="text-2xl sm:text-3xl font-bold tracking-tight mb-1.5">{t("heading")}</h1>
      <p className="text-sm text-dp-muted mb-8">{t("subheading")}</p>

      <section className="bg-dp-panel rounded-2xl border border-dp-border p-6 mb-6">
        <div className="text-lg font-bold tracking-tight mb-1">{project.title}</div>
        {project.description && <p className="text-sm text-dp-muted mb-3">{project.description}</p>}
        {stackChips.length > 0 && (
          <div className="flex flex-wrap gap-1.5 mb-3">
            {stackChips.map((label) => (
              <span key={label} className="px-3 py-1.5 rounded-full text-xs font-medium bg-dp-faint text-dp-muted-2">
                {label}
              </span>
            ))}
          </div>
        )}
        <div className="flex gap-4 text-xs text-dp-muted">
          <span>{t("modulesComplete", { done: completedCount, total: MODULES.length })}</span>
          {sprintCount > 0 && <span>{t("sprintCount", { count: sprintCount })}</span>}
        </div>
      </section>

      <section className="mb-6">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-dp-muted mb-3">{t("modulesHeading")}</h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
          {MODULES.map((m) => {
            const backendModule = moduleByType[m.id];
            const isDone = backendModule?.status === "completed";
            return (
              <Link
                key={m.id}
                href={`/modules/${m.id}`}
                className="flex items-center gap-3 p-3.5 rounded-xl border border-dp-border hover:bg-dp-faint transition-colors"
              >
                {isDone ? (
                  <CheckCircle2 size={17} className="text-dp-green flex-shrink-0" />
                ) : (
                  <Circle size={17} className="text-dp-border flex-shrink-0" />
                )}
                <span className="text-sm font-medium flex-1 truncate">{tModules(`${m.id}.title`)}</span>
                <span className="text-xs font-semibold text-dp-accent-strong flex-shrink-0">{t("edit")}</span>
              </Link>
            );
          })}
        </div>
      </section>

      <section className="bg-dp-panel rounded-2xl border border-dp-border p-6 mb-8">
        <div className="flex items-center gap-2 mb-3">
          <ShieldCheck size={17} className="text-dp-accent" />
          <h2 className="text-sm font-semibold">{t("validationHeading")}</h2>
        </div>
        <p className="text-xs text-dp-muted mb-4">{t("validationBody")}</p>

        {!hasValidated && !validating && (
          <button
            type="button"
            onClick={runValidation}
            className="text-sm font-semibold px-4 py-2.5 rounded-full bg-dp-solid text-dp-on-solid hover:opacity-90 transition-colors"
          >
            {t("runValidation")}
          </button>
        )}

        {validating && (
          <div className="flex items-center gap-2 text-sm text-dp-muted">
            <Loader2 size={16} className="animate-spin" /> {t("validating")}
          </div>
        )}

        {error && <p className="text-xs text-red-500">{error}</p>}

        {hasValidated && !validating && (
          <>
            {issues.length === 0 ? (
              <p className="text-sm text-dp-green font-medium">{t("noIssues")}</p>
            ) : (
              <ul className="flex flex-col gap-2.5 mb-3">
                {issues.map((issue, index) => (
                  <li key={index} className="flex items-start gap-2.5 text-sm">
                    <AlertTriangle size={15} className={`mt-0.5 flex-shrink-0 ${SEVERITY_STYLES[issue.severity] || SEVERITY_STYLES.low}`} />
                    <span>{issue.message}</span>
                  </li>
                ))}
              </ul>
            )}
            <button type="button" onClick={runValidation} className="text-xs font-semibold text-dp-accent-strong hover:underline">
              {t("runAgain")}
            </button>
          </>
        )}
      </section>

      <button
        type="button"
        onClick={() => router.push("/studio")}
        className="inline-flex items-center gap-1.5 px-5 py-3 rounded-full bg-dp-solid text-dp-on-solid text-sm font-semibold hover:opacity-90 transition-colors"
      >
        <Rocket size={16} /> {t("continueToStudio")} <ChevronRight size={16} />
      </button>
    </div>
  );
}
