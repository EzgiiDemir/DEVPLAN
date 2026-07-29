"use client";

import { useEffect, useState } from "react";
import { useTranslations, useLocale } from "next-intl";
import { Check, Circle, Rocket } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { countTree } from "@/lib/countTree";

const APPROVAL_MODULES = [
  { key: "architecture", moduleType: "tech_stack" },
  { key: "api", moduleType: "api_design" },
  { key: "database", moduleType: "design" },
  { key: "folderStructure", moduleType: "folder_structure" },
  { key: "sprint", moduleType: "task_plan" },
];

export function ExecutionPlan({ project, onStart }) {
  const t = useTranslations("StudioExecutionPlan");
  const tCommon = useTranslations("Common");
  const locale = useLocale();
  const [loading, setLoading] = useState(true);
  const [fileCounts, setFileCounts] = useState({ files: 0, folders: 0 });
  const [packages, setPackages] = useState(null);
  const [loadingPackages, setLoadingPackages] = useState(false);
  const [database, setDatabase] = useState(null);
  const [hasDocker, setHasDocker] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;

    async function load() {
      const modules = project.modules || [];
      const scaffoldModule = modules.find((m) => m.module_type === "folder_structure");
      const envModule = modules.find((m) => m.module_type === "environment");
      const stackModule = modules.find((m) => m.module_type === "tech_stack");

      if (scaffoldModule) {
        const items = await apiFetch(`/modules/${scaffoldModule.id}/items`);
        const scaffoldItem = items.find((i) => i.item_type === "scaffold_tree");
        if (scaffoldItem) setFileCounts(countTree(scaffoldItem.content.tree));
      }

      if (envModule) {
        const items = await apiFetch(`/modules/${envModule.id}/items`);
        const envItem = items.find((i) => i.item_type === "env_files");
        setHasDocker(!!envItem?.content?.files?.dockerCompose);
      }

      if (stackModule) {
        const items = await apiFetch(`/modules/${stackModule.id}/items`);
        const stackItem = items.find((i) => i.item_type === "tech_stack");
        if (stackItem) setDatabase(stackItem.content.database?.selected);
      }

      if (cancelled) return;
      setLoading(false);
    }

    load();
    return () => {
      cancelled = true;
    };
  }, [project]);

  async function generatePackages() {
    setError("");
    setLoadingPackages(true);
    try {
      const modules = project.modules || [];
      const stackModule = modules.find((m) => m.module_type === "tech_stack");
      const items = stackModule ? await apiFetch(`/modules/${stackModule.id}/items`) : [];
      const stackItem = items.find((i) => i.item_type === "tech_stack");
      const frontend = stackItem?.content?.frontend?.selected || "Next.js";
      const backend = stackItem?.content?.backend?.selected || "Laravel";

      const result = await apiFetch("/ai/package-list", {
        method: "POST",
        body: JSON.stringify({ module_id: stackModule.id, frontend, backend, locale }),
      });
      setPackages(result.packages || []);
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setLoadingPackages(false);
    }
  }

  if (loading) {
    return <p className="text-sm text-dp-muted p-8">{tCommon("loading")}</p>;
  }

  const moduleByType = Object.fromEntries((project.modules || []).map((m) => [m.module_type, m]));
  const packageCount = packages?.length ?? null;
  const estimatedMinutes = Math.max(2, Math.ceil((packageCount ?? 20) / 15));

  return (
    <div className="max-w-2xl mx-auto w-full px-4 sm:px-6 py-12 sm:py-16">
      <div className="text-[11px] font-semibold text-dp-accent-strong uppercase tracking-wider mb-3">
        {t("kicker")}
      </div>
      <h1 className="text-2xl sm:text-3xl font-bold tracking-tight mb-8">{t("heading")}</h1>

      <div className="bg-dp-panel rounded-2xl border border-dp-border p-5 mb-5">
        <div className="text-[11px] font-semibold text-dp-muted uppercase tracking-wider mb-3">
          {t("approvalsHeading")}
        </div>
        <div className="flex flex-col gap-2">
          {APPROVAL_MODULES.map(({ key, moduleType }) => {
            const done = moduleByType[moduleType]?.status === "completed";
            return (
              <div key={key} className="flex items-center gap-2 text-sm">
                {done ? (
                  <Check size={15} className="text-dp-green flex-shrink-0" />
                ) : (
                  <Circle size={15} className="text-dp-border flex-shrink-0" />
                )}
                <span className={done ? "text-dp-ink" : "text-dp-muted"}>{t(`approvals.${key}`)}</span>
              </div>
            );
          })}
        </div>
      </div>

      <div className="bg-dp-panel rounded-2xl border border-dp-border p-5 mb-5">
        <div className="text-[11px] font-semibold text-dp-muted uppercase tracking-wider mb-3">
          {t("willCreateHeading")}
        </div>
        <ul className="flex flex-col gap-1.5 text-sm text-dp-ink">
          <li>{t("filesCount", { count: fileCounts.files })}</li>
          <li>{t("foldersCount", { count: fileCounts.folders })}</li>
          <li>
            {packageCount === null ? (
              <button type="button" onClick={generatePackages} disabled={loadingPackages} className="text-dp-accent-strong font-medium underline">
                {loadingPackages ? t("packagesLoading") : t("packagesGenerate")}
              </button>
            ) : (
              t("packagesCount", { count: packageCount })
            )}
          </li>
          {database && <li>{t("databaseLine", { database })}</li>}
          <li className={hasDocker ? "" : "text-dp-muted italic"}>
            {hasDocker ? t("dockerYes") : t("dockerNo")}
          </li>
          <li>{t("gitLine")}</li>
        </ul>
      </div>

      {error && <p className="text-xs text-red-500 mb-3">{error}</p>}

      <p className="text-xs text-dp-muted mb-6">{t("estimatedTime", { minutes: estimatedMinutes })}</p>

      <button
        type="button"
        onClick={() => onStart(packages || [])}
        className="inline-flex items-center gap-1.5 px-6 py-3.5 rounded-full bg-dp-solid text-dp-on-solid text-sm font-semibold hover:opacity-90 transition-colors"
      >
        <Rocket size={15} /> {t("start")}
      </button>
    </div>
  );
}
