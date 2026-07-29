"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronRight, Download, FileJson, FileText } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { MODULES } from "@/lib/constants";
import { TinyBtn } from "@/components/ui/Buttons";
import { exportRoadmapJson, exportRoadmapMarkdown, exportRoadmapPdf } from "@/lib/exportRoadmap";

export function RoadmapPanel({ project, onContinue }) {
  const t = useTranslations("StudioRoadmap");
  const tCommon = useTranslations("Common");
  const [loading, setLoading] = useState(true);
  const [stack, setStack] = useState(null);
  const [sprintProgress, setSprintProgress] = useState({ done: 0, total: 0 });

  useEffect(() => {
    let cancelled = false;

    async function load() {
      const modules = project.modules || [];
      const stackModule = modules.find((m) => m.module_type === "tech_stack");
      const taskModule = modules.find((m) => m.module_type === "task_plan");

      let realStack = null;
      let progress = { done: 0, total: 0 };

      if (stackModule) {
        const items = await apiFetch(`/modules/${stackModule.id}/items`);
        const stackItem = items.find((i) => i.item_type === "tech_stack");
        if (stackItem) {
          realStack = {
            frontend: stackItem.content.frontend?.selected,
            backend: stackItem.content.backend?.selected,
            database: stackItem.content.database?.selected,
            hosting: stackItem.content.hosting?.selected,
          };
        }
      }

      if (taskModule) {
        const items = await apiFetch(`/modules/${taskModule.id}/items`);
        const planItem = items.find((i) => i.item_type === "sprint_plan");
        if (planItem) {
          const sprints = planItem.content.sprints || [];
          const allTasks = sprints.flatMap((s) => s.tasks || []);
          progress = { done: allTasks.filter((task) => task.done).length, total: allTasks.length };
        }
      }

      if (cancelled) return;
      setStack(realStack);
      setSprintProgress(progress);
      setLoading(false);
    }

    load();
    return () => {
      cancelled = true;
    };
  }, [project]);

  if (loading) {
    return <p className="text-sm text-dp-muted">{tCommon("loading")}</p>;
  }

  const completedModules = (project.modules || []).filter((m) => m.status === "completed").length;
  const totalModules = MODULES.length;
  const remainingTasks = Math.max(0, sprintProgress.total - sprintProgress.done);
  const estimatedWeeks = sprintProgress.total > 0 ? Math.max(1, Math.ceil(remainingTasks / 5)) : null;
  const estimatedTime = estimatedWeeks
    ? t("estimatedWeeks", { weeks: estimatedWeeks })
    : t("estimatedUnknown");

  const summary = {
    projectName: project.title,
    frontend: stack?.frontend,
    backend: stack?.backend,
    database: stack?.database,
    hosting: stack?.hosting,
    completedModules,
    totalModules,
    remainingTasks,
    estimatedTime,
    sprintProgress,
  };

  const exportLabels = {
    techStack: t("techStack"),
    frontend: t("frontend"),
    backend: t("backend"),
    database: t("database"),
    hosting: t("hosting"),
    progress: t("progress"),
    completedModules: t("completedModules"),
    remainingTasks: t("remainingTasks"),
    estimatedTime: t("estimatedTime"),
    sprintProgress: t("sprintProgress"),
  };

  return (
    <div className="max-w-3xl mx-auto w-full px-4 sm:px-6 py-12 sm:py-16">
      <div className="text-[11px] font-semibold text-dp-accent-strong uppercase tracking-wider mb-3">
        {t("kicker")}
      </div>
      <h1 className="text-2xl sm:text-3xl font-bold tracking-tight mb-1">{project.title}</h1>
      <p className="text-sm text-dp-muted mb-8">{t("subtitle")}</p>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-6">
        <div className="bg-dp-panel rounded-2xl border border-dp-border p-5">
          <div className="text-[11px] font-semibold text-dp-muted uppercase tracking-wider mb-3">
            {t("techStack")}
          </div>
          {stack ? (
            <dl className="flex flex-col gap-1.5 text-sm">
              <div className="flex justify-between">
                <dt className="text-dp-muted">{t("frontend")}</dt>
                <dd className="font-medium">{stack.frontend || "-"}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-dp-muted">{t("backend")}</dt>
                <dd className="font-medium">{stack.backend || "-"}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-dp-muted">{t("database")}</dt>
                <dd className="font-medium">{stack.database || "-"}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-dp-muted">{t("hosting")}</dt>
                <dd className="font-medium">{stack.hosting || "-"}</dd>
              </div>
            </dl>
          ) : (
            <p className="text-xs text-dp-muted italic">{t("stackMissing")}</p>
          )}
        </div>

        <div className="bg-dp-panel rounded-2xl border border-dp-border p-5">
          <div className="text-[11px] font-semibold text-dp-muted uppercase tracking-wider mb-3">
            {t("progress")}
          </div>
          <dl className="flex flex-col gap-1.5 text-sm">
            <div className="flex justify-between">
              <dt className="text-dp-muted">{t("completedModules")}</dt>
              <dd className="font-medium">
                {completedModules} / {totalModules}
              </dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-dp-muted">{t("remainingTasks")}</dt>
              <dd className="font-medium">{remainingTasks}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-dp-muted">{t("estimatedTime")}</dt>
              <dd className="font-medium">{estimatedTime}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-dp-muted">{t("sprintProgress")}</dt>
              <dd className="font-medium">
                {sprintProgress.done} / {sprintProgress.total}
              </dd>
            </div>
          </dl>
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2 mb-10">
        <span className="text-xs font-semibold text-dp-muted mr-1">{t("exportLabel")}</span>
        <TinyBtn onClick={() => exportRoadmapPdf(summary, exportLabels)}>
          <Download size={13} /> PDF
        </TinyBtn>
        <TinyBtn onClick={() => exportRoadmapMarkdown(summary, exportLabels)}>
          <FileText size={13} /> Markdown
        </TinyBtn>
        <TinyBtn onClick={() => exportRoadmapJson(summary)}>
          <FileJson size={13} /> JSON
        </TinyBtn>
      </div>

      <button
        type="button"
        onClick={onContinue}
        className="inline-flex items-center gap-1.5 px-6 py-3.5 rounded-full bg-dp-solid text-dp-on-solid text-sm font-semibold hover:opacity-90 transition-colors"
      >
        {t("continue")} <ChevronRight size={16} />
      </button>
    </div>
  );
}
