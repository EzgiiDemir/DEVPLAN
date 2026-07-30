"use client";

import { useCallback, useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Brain, RotateCw, TriangleAlert } from "lucide-react";
import { useCompanion } from "@/lib/companion-context";
import { apiFetch } from "@/lib/api";

const INDEX_BATCH_SIZE = 20;

// Best-effort — no local git repo yet, or Companion not paired, just means
// the staleness check is skipped rather than the whole panel failing.
async function currentGitHead(companion, localPath) {
  if (!companion.paired || !localPath) return null;
  try {
    const result = await companion.runCommand("git rev-parse HEAD", localPath);
    return result.exitCode === 0 ? result.output.trim() : null;
  } catch {
    return null;
  }
}

export function ProjectBrainPanel({ projectId, localPath }) {
  const t = useTranslations("StudioProjectBrain");
  const companion = useCompanion();

  const [status, setStatus] = useState(null);
  const [scanning, setScanning] = useState(false);
  const [progress, setProgress] = useState(null);
  const [error, setError] = useState(null);

  const loadStatus = useCallback(async () => {
    const head = await currentGitHead(companion, localPath);
    const query = head ? `?current_head=${encodeURIComponent(head)}` : "";
    return apiFetch(`/projects/${projectId}/codebase/status${query}`);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId, localPath]);

  useEffect(() => {
    if (!projectId) return;
    let cancelled = false;
    loadStatus()
      .then((data) => {
        if (!cancelled) setStatus(data);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [projectId, loadStatus]);

  async function runScan() {
    setScanning(true);
    setError(null);
    setProgress(null);

    try {
      const listing = await companion.listFiles();
      const hashByPath = new Map(listing.files.map((f) => [f.path, f.hash]));
      const gitHead = await currentGitHead(companion, localPath);
      const diffResult = await apiFetch(`/projects/${projectId}/codebase/diff`, {
        method: "POST",
        body: JSON.stringify({
          files: listing.files.map((f) => ({ path: f.path, hash: f.hash })),
          git_head: gitHead,
        }),
      });

      const needsContent = (diffResult.needsContent || []).filter((path) => hashByPath.get(path));
      setProgress({ done: 0, total: needsContent.length });

      for (let i = 0; i < needsContent.length; i += INDEX_BATCH_SIZE) {
        const batchPaths = needsContent.slice(i, i + INDEX_BATCH_SIZE);
        const batchFiles = [];

        for (const path of batchPaths) {
          try {
            const { content } = await companion.readFile(path);
            batchFiles.push({ path, hash: hashByPath.get(path), content });
          } catch {
            // Binary, oversized, or otherwise unreadable — skip it, not fatal to the scan.
          }
        }

        if (batchFiles.length) {
          await apiFetch(`/projects/${projectId}/codebase/index`, {
            method: "POST",
            body: JSON.stringify({ files: batchFiles }),
          });
        }

        setProgress({ done: Math.min(i + INDEX_BATCH_SIZE, needsContent.length), total: needsContent.length });
      }

      setStatus(await loadStatus());
    } catch (err) {
      setError(err.message);
    } finally {
      setScanning(false);
      setProgress(null);
    }
  }

  const disabled = scanning || !companion.paired;

  return (
    <div className="px-3 py-2.5 border-b border-dp-editor-border">
      <div className="flex items-center justify-between mb-1.5">
        <div className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-dp-editor-muted">
          <Brain size={12} />
          {t("heading")}
        </div>
        <button
          type="button"
          onClick={runScan}
          disabled={disabled}
          title={!companion.paired ? t("needsCompanion") : undefined}
          className="text-dp-editor-muted hover:text-dp-editor-text disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
        >
          <RotateCw size={12} className={scanning ? "animate-spin" : ""} />
        </button>
      </div>

      {scanning ? (
        <p className="text-[11px] text-dp-editor-muted">
          {progress && progress.total > 0
            ? t("scanningProgress", { done: progress.done, total: progress.total })
            : t("scanning")}
        </p>
      ) : error ? (
        <p className="text-[11px] text-red-400">{error}</p>
      ) : status && status.file_count > 0 ? (
        <div className="text-[11px] text-dp-editor-muted space-y-0.5">
          <p>{t("fileCount", { count: status.file_count })}</p>
          <p>{t("dependencyCount", { count: status.dependency_count })}</p>
          {status.stale && (
            <p className="flex items-center gap-1 text-amber-500">
              <TriangleAlert size={10} className="flex-shrink-0" />
              {t("stale")}
            </p>
          )}
        </div>
      ) : (
        <p className="text-[11px] text-dp-editor-muted italic">{t("neverScanned")}</p>
      )}
    </div>
  );
}
