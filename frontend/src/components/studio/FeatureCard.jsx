"use client";

import { useTranslations } from "next-intl";
import { FileRow } from "./FileRow";
import { useFeatureFlow } from "./useFeatureFlow";

export function FeatureCard({ featureRequest, projectId, localPath, onApplied }) {
  const t = useTranslations("StudioFeatureBuilder");
  const {
    featureRequest: fr,
    stage,
    planFiles,
    checkedPaths,
    applyCheckedPaths,
    oldContentByPath,
    appliedCommits,
    error,
    canApply,
    companionPaired,
    toggleFile,
    toggleApplyFile,
    approveAndGenerate,
    applyChanges,
  } = useFeatureFlow({ featureRequest, projectId, localPath, onApplied });

  return (
    <div className="flex flex-col gap-2 border border-dp-editor-border rounded-xl p-3 bg-dp-editor-bg/40">
      {fr?.change_set?.summary && <p className="text-[12px] text-dp-editor-text">{fr.change_set.summary}</p>}

      <p className="text-[10px] font-semibold uppercase tracking-wider text-dp-editor-muted">
        {stage === "plan-review" ? t("planHeading") : t("diffHeading")}
      </p>

      {planFiles.map((file) => (
        <FileRow
          key={file.path}
          file={file}
          checked={stage === "plan-review" ? checkedPaths.has(file.path) : applyCheckedPaths.has(file.path)}
          onToggle={() => (stage === "plan-review" ? toggleFile(file.path) : toggleApplyFile(file.path))}
          editable={stage === "plan-review" || stage === "diff-review"}
          oldContent={oldContentByPath[file.path]}
        />
      ))}

      {stage === "plan-review" && (
        <button
          type="button"
          onClick={approveAndGenerate}
          disabled={checkedPaths.size === 0 || !companionPaired}
          title={!companionPaired ? t("needsCompanion") : undefined}
          className="text-xs font-semibold px-3 py-2 rounded-lg bg-dp-accent text-white disabled:opacity-40 disabled:cursor-not-allowed self-start"
        >
          {t("approveAndGenerate")}
        </button>
      )}

      {stage === "generating" && <p className="text-[12px] text-dp-editor-muted">{t("generating")}</p>}

      {stage === "diff-review" && (
        <button
          type="button"
          onClick={applyChanges}
          disabled={!canApply}
          title={!canApply ? t("needsCompanion") : undefined}
          className="text-xs font-semibold px-3 py-2 rounded-lg bg-dp-accent text-white disabled:opacity-40 disabled:cursor-not-allowed self-start"
        >
          {t("applyChanges")}
        </button>
      )}

      {stage === "applying" && <p className="text-[12px] text-dp-editor-muted">{t("applying")}</p>}

      {stage === "applied" && (
        <div>
          <p className="text-[12px] text-dp-editor-text">{t("appliedSuccess")}</p>
          {appliedCommits && (
            <p className="text-[11px] font-mono text-dp-editor-muted">
              {appliedCommits.before.slice(0, 7)} → {appliedCommits.after.slice(0, 7)}
            </p>
          )}
        </div>
      )}

      {error && <p className="text-[12px] text-red-400">{error}</p>}
    </div>
  );
}
