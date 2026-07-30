"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { History, Rocket, Undo2 } from "lucide-react";
import { useCompanion } from "@/lib/companion-context";
import { apiFetch } from "@/lib/api";
import { prepareRevert, performSafeRevert } from "@/lib/safeRevert";
import { RevertConfirmDialog } from "./RevertConfirmDialog";

export function CheckpointHistoryPanel({ projectId, localPath, refreshKey }) {
  const t = useTranslations("StudioCheckpoints");
  const companion = useCompanion();

  const [checkpoints, setCheckpoints] = useState([]);
  const [reverting, setReverting] = useState(null);
  const [error, setError] = useState(null);
  const [pendingRevert, setPendingRevert] = useState(null); // { checkpoint, dirty, diffStat }
  const [confirming, setConfirming] = useState(false);

  useEffect(() => {
    if (!projectId) return;
    let cancelled = false;
    apiFetch(`/projects/${projectId}/checkpoints`)
      .then((data) => {
        if (!cancelled) setCheckpoints(data);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [projectId, refreshKey]);

  async function startRevert(checkpoint) {
    setError(null);
    setReverting(checkpoint.id);
    try {
      const { dirty, diffStat } = await prepareRevert({
        companion,
        localPath,
        targetHash: checkpoint.git_commit_hash,
      });
      setPendingRevert({ checkpoint, dirty, diffStat });
    } catch (err) {
      setError(err.message);
      setReverting(null);
    }
  }

  async function confirmRevert() {
    const { checkpoint, dirty } = pendingRevert;
    setConfirming(true);
    setError(null);
    try {
      const result = await performSafeRevert({
        companion,
        localPath,
        targetHash: checkpoint.git_commit_hash,
        dirty,
        stashLabel: `DevPlan: auto-stash before revert to ${checkpoint.git_commit_hash.slice(0, 7)}`,
      });

      if (result.partial) setError(t("revertPartial"));
      if (result.stashConflict) setError(t("revertStashConflict"));

      // Rollback is "go back to a checkpoint, then deploy again" — not a
      // platform-specific undo API. If this checkpoint came from a
      // deployment, the code is now back to that state; redeploying (via
      // the same Deploy button) is a separate, deliberate next step.
      if (!result.partial && !result.stashConflict && checkpoint.deployment) {
        window.alert(t("revertedDeployNotice", { platform: checkpoint.deployment.platform }));
      }
    } catch (err) {
      setError(err.message);
    } finally {
      setConfirming(false);
      setReverting(null);
      setPendingRevert(null);
    }
  }

  function cancelRevert() {
    setPendingRevert(null);
    setReverting(null);
  }

  if (checkpoints.length === 0) return null;

  return (
    <div className="px-3 py-2.5 border-b border-dp-editor-border">
      <div className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-dp-editor-muted mb-1.5">
        <History size={12} />
        {t("heading")}
      </div>
      <div className="flex flex-col gap-1 max-h-40 overflow-y-auto">
        {checkpoints.map((cp) => (
          <div key={cp.id} className="flex items-center gap-1.5 text-[11px] group">
            <span className="font-mono text-dp-editor-muted flex-shrink-0">{cp.git_commit_hash.slice(0, 7)}</span>
            <span className="truncate flex-1 text-dp-editor-text">{cp.message}</span>
            {cp.deployment && (
              <span
                title={t("deployedTo", { platform: cp.deployment.platform, status: cp.deployment.status })}
                className={`flex items-center gap-0.5 flex-shrink-0 ${cp.deployment.status === "success" ? "text-green-500" : "text-amber-500"}`}
              >
                <Rocket size={10} />
              </span>
            )}
            <button
              type="button"
              onClick={() => startRevert(cp)}
              disabled={!companion.paired || reverting === cp.id}
              title={t("revert")}
              className="text-dp-editor-muted hover:text-dp-editor-text disabled:opacity-30 flex-shrink-0"
            >
              <Undo2 size={11} />
            </button>
          </div>
        ))}
      </div>
      {error && <p className="text-[10px] text-red-400 mt-1">{error}</p>}

      {pendingRevert && (
        <RevertConfirmDialog
          title={t("confirmRevert", { message: pendingRevert.checkpoint.message })}
          diffStat={pendingRevert.diffStat}
          dirty={pendingRevert.dirty}
          stashNotice={t("willStash")}
          noDiffLabel={t("noDifferences")}
          onCancel={cancelRevert}
          onConfirm={confirmRevert}
          confirming={confirming}
          confirmLabel={t("revert")}
          cancelLabel={t("cancel")}
        />
      )}
    </div>
  );
}
