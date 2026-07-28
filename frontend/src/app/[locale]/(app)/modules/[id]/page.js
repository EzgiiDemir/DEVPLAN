"use client";

import { useParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { ArrowLeft } from "lucide-react";
import { Link, useRouter } from "@/i18n/navigation";
import { MODULES } from "@/lib/constants";
import { useProject } from "@/lib/project-context";
import { CompleteButton } from "@/components/ui/Buttons";
import { MODULE_COMPONENTS } from "@/components/modules/registry";

export default function ModulePage() {
  const { id } = useParams();
  const router = useRouter();
  const t = useTranslations("ModulePage");
  const tCommon = useTranslations("Common");
  const tModules = useTranslations("Modules");
  const { project, loading: projectLoading, updateModuleStatus } = useProject();
  const activeModule = MODULES.find((m) => m.id === id);

  if (projectLoading) {
    return (
      <div className="max-w-3xl mx-auto w-full px-4 sm:px-6 pt-20 text-sm text-dp-muted">
        {tCommon("loading")}
      </div>
    );
  }

  if (!project) {
    router.push("/dashboard");
    return null;
  }

  if (!activeModule) {
    return (
      <div className="max-w-3xl mx-auto w-full px-4 sm:px-6 pt-7 pb-10">
        <p className="text-sm text-dp-muted">{t("notFound")}</p>
        <Link href="/dashboard" className="font-mono text-xs text-dp-accent">
          {tCommon("back")}
        </Link>
      </div>
    );
  }

  const backendModule = project.modules.find((m) => m.module_type === id);
  const isDone = backendModule?.status === "completed";
  const ModuleComponent = MODULE_COMPONENTS[activeModule.id];

  async function onComplete() {
    await updateModuleStatus(backendModule.id, "completed");

    const moduleByType = Object.fromEntries(project.modules.map((m) => [m.module_type, m]));
    const next = MODULES.find(
      (m) => m.n > activeModule.n && moduleByType[m.id]?.status !== "completed",
    );

    if (next) {
      router.push(`/modules/${next.id}`);
    } else {
      const allDone = MODULES.every(
        (m) => m.id === activeModule.id || moduleByType[m.id]?.status === "completed",
      );
      router.push(allDone ? "/studio" : "/dashboard");
    }
  }

  return (
    <div className="max-w-3xl mx-auto w-full px-4 sm:px-6 pt-7 pb-10">
      <Link
        href="/dashboard"
        className="text-sm font-medium text-dp-muted hover:text-dp-ink inline-flex items-center gap-1.5 mb-5 transition-colors"
      >
        <ArrowLeft size={16} /> {tCommon("back")}
      </Link>

      <div className="text-2xl font-bold tracking-tight mb-1">{tModules(`${activeModule.id}.title`)}</div>
      <p className="text-sm text-dp-muted mb-7">{tModules(`${activeModule.id}.sub`)}</p>

      {ModuleComponent ? (
        <ModuleComponent module={backendModule} isDone={isDone} onComplete={onComplete} />
      ) : (
        <>
          <div className="rounded-2xl border border-dashed border-dp-border bg-dp-panel p-8 text-sm text-dp-muted text-center">
            {t("placeholder", { n: activeModule.n, id: activeModule.id })}
          </div>
          <CompleteButton enabled isDone={isDone} onClick={onComplete} />
        </>
      )}
    </div>
  );
}
