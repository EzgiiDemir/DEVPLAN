"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Brain, RotateCw } from "lucide-react";
import { useCompanion } from "@/lib/companion-context";
import { apiFetch } from "@/lib/api";

const INDEX_BATCH_SIZE = 20;

export function ProjectBrainPanel({ projectId }) {
  const t = useTranslations("StudioProjectBrain");
  const companion = useCompanion();

  const [status, setStatus] = useState(null);
  const [scanning, setScanning] = useState(false);
  const [progress, setProgress] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!projectId) return;
    let cancelled = false;
    apiFetch(`/projects/${projectId}/codebase/status`)
      .then((data) => {
        if (!cancelled) setStatus(data);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [projectId]);

  async function runScan() {
    setScanning(true);
    setError(null);
    setProgress(null);

    try {
      const listing = await companion.listFiles();
      const hashByPath = new Map(listing.files.map((f) => [f.path, f.hash]));
      const diffResult = await apiFetch(`/projects/${projectId}/codebase/diff`, {
        method: "POST",
        body: JSON.stringify({
          files: listing.files.map((f) => ({ path: f.path, hash: f.hash })),
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

      const freshStatus = await apiFetch(`/projects/${projectId}/codebase/status`);
      setStatus(freshStatus);
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
        </div>
      ) : (
        <p className="text-[11px] text-dp-editor-muted italic">{t("neverScanned")}</p>
      )}
    </div>
  );
}
