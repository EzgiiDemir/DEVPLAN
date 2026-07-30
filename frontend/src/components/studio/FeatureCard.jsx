"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { useCompanion } from "@/lib/companion-context";
import { prepareRevert, performSafeRevert } from "@/lib/safeRevert";
import { FileRow } from "./FileRow";
import { RevertConfirmDialog } from "./RevertConfirmDialog";
import { useFeatureFlow } from "./useFeatureFlow";

export function FeatureCard({ featureRequest, projectId, localPath, onApplied, canAct = true }) {
  const t = useTranslations("StudioFeatureBuilder");
  const companion = useCompanion();
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

  const [pendingUndo, setPendingUndo] = useState(null); // { dirty, diffStat }
  const [undoing, setUndoing] = useState(false);
  const [undoError, setUndoError] = useState(null);
  const [undone, setUndone] = useState(false);

  // "AI Undo" — narrowly scoped to exactly the commit this one feature
  // applied (appliedCommits.before), as opposed to CheckpointHistoryPanel's
  // general "User Undo", which can revert to any point in project history.
  // Shares the same safe-revert core (stash-if-dirty, diff preview, explicit
  // confirmation, stash restore) so neither path can silently destroy work.
  async function startUndoAiChange() {
    setUndoError(null);
    try {
      const { dirty, diffStat } = await prepareRevert({
        companion,
        localPath,
        targetHash: appliedCommits.before,
      });
      setPendingUndo({ dirty, diffStat });
    } catch (err) {
      setUndoError(err.message);
    }
  }

  async function confirmUndoAiChange() {
    setUndoing(true);
    setUndoError(null);
    try {
      const result = await performSafeRevert({
        companion,
        localPath,
        targetHash: appliedCommits.before,
        dirty: pendingUndo.dirty,
        stashLabel: `DevPlan: auto-stash before undoing "${(fr?.prompt || "").slice(0, 60)}"`,
      });
      if (result.partial) setUndoError(t("undoPartial"));
      else if (result.stashConflict) setUndoError(t("undoStashConflict"));
      else setUndone(true);
    } catch (err) {
      setUndoError(err.message);
    } finally {
      setUndoing(false);
      setPendingUndo(null);
    }
  }

  return (
    <div className="flex flex-col gap-2 border border-dp-editor-border rounded-xl p-3 bg-dp-editor-bg/40">
      {fr?.change_set?.summary && <p className="text-[12px] text-dp-editor-text">{fr.change_set.summary}</p>}

      {fr?.change_set?.duplicate_warning && (
        <p className="text-[11px] text-amber-500 bg-amber-500/10 rounded-lg px-2.5 py-1.5">
          {fr.change_set.duplicate_warning.type === "similar_prior_request"
            ? t("duplicateWarningPriorRequest", { prompt: fr.change_set.duplicate_warning.prompt })
            : t("duplicateWarningExistingSymbol", {
                symbol: fr.change_set.duplicate_warning.symbol,
                path: fr.change_set.duplicate_warning.path,
              })}
        </p>
      )}

      <div className="flex items-center gap-2">
        <p className="text-[10px] font-semibold uppercase tracking-wider text-dp-editor-muted">
          {stage === "plan-review" ? t("planHeading") : t("diffHeading")}
        </p>
        {fr?.change_set?.confidence_level && (
          <span
            title={t("confidenceHint")}
            className={`text-[9px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded ${
              fr.change_set.confidence_level === "high"
                ? "bg-green-500/20 text-green-400"
                : fr.change_set.confidence_level === "low"
                  ? "bg-red-500/20 text-red-400"
                  : "bg-amber-500/20 text-amber-500"
            }`}
          >
            {t(`confidence.${fr.change_set.confidence_level}`)}
          </span>
        )}
      </div>

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
          disabled={checkedPaths.size === 0 || !companionPaired || !canAct}
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
          disabled={!canApply || !canAct}
          title={!canApply ? t("needsCompanion") : undefined}
          className="text-xs font-semibold px-3 py-2 rounded-lg bg-dp-accent text-white disabled:opacity-40 disabled:cursor-not-allowed self-start"
        >
          {t("applyChanges")}
        </button>
      )}

      {stage === "applying" && <p className="text-[12px] text-dp-editor-muted">{t("applying")}</p>}

      {stage === "applied" && (
        <div className="flex flex-col gap-1.5 items-start">
          <p className="text-[12px] text-dp-editor-text">{t("appliedSuccess")}</p>
          {appliedCommits && (
            <p className="text-[11px] font-mono text-dp-editor-muted">
              {appliedCommits.before.slice(0, 7)} → {appliedCommits.after.slice(0, 7)}
            </p>
          )}
          {appliedCommits && !undone && (
            <button
              type="button"
              onClick={startUndoAiChange}
              disabled={!companionPaired || undoing}
              className="text-[11px] font-medium px-2.5 py-1 rounded-lg border border-dp-editor-border text-dp-editor-text disabled:opacity-40 disabled:cursor-not-allowed"
            >
              {t("undoAiChange")}
            </button>
          )}
          {undone && <p className="text-[11px] text-dp-editor-muted">{t("undoAiChangeDone")}</p>}
          {undoError && <p className="text-[11px] text-red-400">{undoError}</p>}
        </div>
      )}

      {error && <p className="text-[12px] text-red-400">{error}</p>}

      {pendingUndo && (
        <RevertConfirmDialog
          title={t("confirmUndoAiChange")}
          diffStat={pendingUndo.diffStat}
          dirty={pendingUndo.dirty}
          stashNotice={t("undoWillStash")}
          noDiffLabel={t("noDifferences")}
          onCancel={() => setPendingUndo(null)}
          onConfirm={confirmUndoAiChange}
          confirming={undoing}
          confirmLabel={t("undoAiChange")}
          cancelLabel={t("cancel")}
        />
      )}
    </div>
  );
}
