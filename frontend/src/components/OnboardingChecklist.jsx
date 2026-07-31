"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Check, Circle } from "lucide-react";
import { Link } from "@/i18n/navigation";
import { apiFetch } from "@/lib/api";

/**
 * Tracks real milestones across the *whole* journey (not just the 12-module
 * planning phase the progress bar above already covers) — connecting
 * Companion, generating a first feature, deploying — so a new user has a
 * concrete next step at every stage instead of the checklist going silent
 * once modules are done.
 */
export function OnboardingChecklist({ project, modulesDone, totalModules }) {
  const t = useTranslations("Dashboard.checklist");
  const [featureCount, setFeatureCount] = useState(null);
  const [deploymentCount, setDeploymentCount] = useState(null);

  useEffect(() => {
    let cancelled = false;
    apiFetch(`/projects/${project.id}/features`)
      .then((list) => {
        if (!cancelled) setFeatureCount(list.length);
      })
      .catch(() => {});
    apiFetch(`/projects/${project.id}/deployments`)
      .then((list) => {
        if (!cancelled) setDeploymentCount(list.length);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [project.id]);

  const items = [
    { key: "modules", done: modulesDone === totalModules, href: null },
    { key: "companion", done: Boolean(project.local_path), href: "/studio" },
    { key: "feature", done: (featureCount ?? 0) > 0, href: "/studio" },
    { key: "deploy", done: (deploymentCount ?? 0) > 0, href: "/studio" },
  ];

  if (items.every((i) => i.done)) return null;

  return (
    <div className="bg-dp-panel rounded-2xl border border-dp-border p-5 mb-8">
      <div className="text-[11px] font-semibold text-dp-muted uppercase tracking-wider mb-3">{t("heading")}</div>
      <div className="flex flex-col gap-2.5">
        {items.map((item) => {
          const content = (
            <>
              {item.done ? (
                <Check size={15} className="text-dp-green flex-shrink-0" />
              ) : (
                <Circle size={15} className="text-dp-border flex-shrink-0" />
              )}
              <span className={`text-sm ${item.done ? "text-dp-muted line-through" : "text-dp-ink"}`}>
                {t(`items.${item.key}`)}
              </span>
            </>
          );

          return item.href && !item.done ? (
            <Link key={item.key} href={item.href} className="flex items-center gap-2.5 hover:opacity-80 transition-opacity">
              {content}
            </Link>
          ) : (
            <div key={item.key} className="flex items-center gap-2.5">
              {content}
            </div>
          );
        })}
      </div>
    </div>
  );
}
