"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { useCompanion } from "@/lib/companion-context";
import { apiFetch } from "@/lib/api";
import { pollAiJob } from "@/lib/aiJobs";

function sanitizeForShell(text) {
  return (text || "").replace(/[^A-Za-z0-9 .:_-]/g, "").trim().slice(0, 72) || "update";
}

async function sha256Hex(text) {
  const bytes = new TextEncoder().encode(text ?? "");
  const digest = await crypto.subtle.digest("SHA-256", bytes);
  return Array.from(new Uint8Array(digest))
    .map((byte) => byte.toString(16).padStart(2, "0"))
    .join("");
}

// Deterministic, no AI needed — this is the "low-risk, auto-applied" half of
// documentation updates (the README half goes through the same AI-generated
// diff-approval flow as code, appearing as an ordinary file in planFiles).
function buildChangelogUpdate(existingContent, prompt, changedPaths) {
  const date = new Date().toISOString().slice(0, 10);
  const fileList = changedPaths.map((p) => `  - ${p}`).join("\n");
  const entry = `## ${date}\n\n- ${prompt}\n${fileList}\n\n`;

  const trimmed = (existingContent || "").trim();
  if (!trimmed) return `# Changelog\n\n${entry}`;

  const headerMatch = trimmed.match(/^# Changelog\s*/);
  if (headerMatch) {
    return `# Changelog\n\n${entry}${trimmed.slice(headerMatch[0].length)}\n`;
  }
  return `# Changelog\n\n${entry}${trimmed}\n`;
}

/**
 * The approve/generate/apply/git-commit/CHANGELOG-patch lifecycle for a
 * single feature request — extracted so both a standalone flow and an
 * embedded chat card (FeatureCard) can drive it identically. Starts at
 * "plan-review" since by the time this hook is used, the plan has already
 * been generated (either by the old prompt-box flow or by Maya's chat).
 */
export function useFeatureFlow({ featureRequest: initialFeatureRequest, projectId, localPath, onApplied }) {
  const t = useTranslations("StudioFeatureBuilder");
  const companion = useCompanion();

  const [featureRequest, setFeatureRequest] = useState(initialFeatureRequest);
  // plan-review | generating | diff-review | applying | applied
  // Reloading history should reflect real persisted progress rather than
  // always resetting to plan-review, e.g. after the apply already happened
  // in an earlier session.
  const [stage, setStage] = useState(() => {
    if (initialFeatureRequest?.status === "applied") return "applied";
    if (initialFeatureRequest?.status === "awaiting_diff_approval") return "diff-review";
    return "plan-review";
  });
  const [checkedPaths, setCheckedPaths] = useState(
    () => new Set((initialFeatureRequest?.change_set?.files || []).map((f) => f.path)),
  );
  const [applyCheckedPaths, setApplyCheckedPaths] = useState(() => {
    const startsAtDiffReview = initialFeatureRequest?.status === "awaiting_diff_approval";
    return new Set(startsAtDiffReview ? (initialFeatureRequest?.change_set?.files || []).map((f) => f.path) : []);
  });
  const [oldContentByPath, setOldContentByPath] = useState({});
  const [appliedCommits, setAppliedCommits] = useState(null);
  const [error, setError] = useState(null);

  function toggleFile(path) {
    setCheckedPaths((prev) => {
      const next = new Set(prev);
      if (next.has(path)) next.delete(path);
      else next.add(path);
      return next;
    });
  }

  function toggleApplyFile(path) {
    setApplyCheckedPaths((prev) => {
      const next = new Set(prev);
      if (next.has(path)) next.delete(path);
      else next.add(path);
      return next;
    });
  }

  async function approveAndGenerate() {
    setStage("generating");
    setError(null);
    try {
      const approvedPaths = Array.from(checkedPaths);
      const approveResult = await apiFetch(
        `/projects/${projectId}/features/${featureRequest.id}/plan/approve`,
        { method: "POST", body: JSON.stringify({ approved_paths: approvedPaths }) },
      );

      const filesForGenerate = [];
      const fetchedOldContent = {};
      for (const path of approveResult.needsContent || []) {
        try {
          const { content } = await companion.readFile(path);
          filesForGenerate.push({ path, content });
          fetchedOldContent[path] = content;
        } catch {
          // File may not exist on disk despite being flagged "modify" — the
          // agent will generate without that grounding rather than blocking.
        }
      }
      setOldContentByPath(fetchedOldContent);

      const dispatched = await apiFetch(
        `/projects/${projectId}/features/${featureRequest.id}/generate`,
        { method: "POST", body: JSON.stringify({ files: filesForGenerate }) },
      );
      await pollAiJob(dispatched.job_id);
      // generateContent() returns only { change_set_id } via the job — the
      // hydrated change set (with files) comes from the existing show()
      // endpoint, which already eager-loads changeSet.files.
      const refreshed = await apiFetch(`/projects/${projectId}/features/${featureRequest.id}`);
      const updatedChangeSet = refreshed.change_set;
      setFeatureRequest((prev) => ({ ...prev, change_set: updatedChangeSet }));
      setApplyCheckedPaths(new Set((updatedChangeSet.files || []).map((f) => f.path)));
      setStage("diff-review");
    } catch (err) {
      setError(err.message);
      setStage("plan-review");
    }
  }

  async function runGit(command) {
    const result = await companion.runCommand(command, localPath);
    if (result.exitCode !== 0) {
      throw new Error(`${command} → ${result.output || t("gitFailed")}`);
    }
    return result.output.trim();
  }

  async function applyChanges() {
    setStage("applying");
    setError(null);
    try {
      const approvedPaths = Array.from(applyCheckedPaths);

      await apiFetch(`/projects/${projectId}/features/${featureRequest.id}/diff/approve`, {
        method: "POST",
        body: JSON.stringify({ approved_paths: approvedPaths }),
      });

      // Idempotent safety net — Setup Mode already runs this for new
      // projects, but older projects created before that fix may not have it.
      await runGit("git init").catch(() => {});
      await runGit("git add -A");
      const beforeMessage = `DevPlan before: ${sanitizeForShell(featureRequest.prompt)}`;
      await runGit(`git commit -m "${beforeMessage}" --allow-empty`);
      const beforeHash = await runGit("git rev-parse HEAD");

      const planFiles = featureRequest?.change_set?.files || [];
      const approvedFiles = planFiles.filter((f) => approvedPaths.includes(f.path));

      // Optimistic-concurrency check (Subsystem 9 — ChangeSet Conflict
      // Detection): base_content_hash is what each diff was generated
      // against. If the file on disk no longer matches it — someone else
      // (another feature, a manual edit) changed it since — applying this
      // diff would silently clobber that change. Only 'create'/'modify'
      // carry a base_content_hash; 'delete' is never checked (see
      // FeatureAgentService::generateContent()'s note on why).
      const conflictedPaths = [];
      for (const file of approvedFiles) {
        if (!file.base_content_hash) continue;
        let onDiskContent = "";
        try {
          onDiskContent = (await companion.readFile(file.path)).content;
        } catch {
          // Doesn't exist on disk — expected for a 'create' whose baseline is "".
        }
        const onDiskHash = await sha256Hex(onDiskContent);
        if (onDiskHash !== file.base_content_hash) {
          conflictedPaths.push(file.path);
        }
      }

      if (conflictedPaths.length > 0) {
        throw new Error(t("applyConflict", { paths: conflictedPaths.join(", ") }));
      }

      for (const file of approvedFiles) {
        if (file.action === "delete") {
          await companion.deleteFile(file.path);
        } else {
          await companion.writeFile(file.path, file.new_content || "");
        }
      }

      try {
        let existingChangelog = "";
        try {
          existingChangelog = (await companion.readFile("CHANGELOG.md")).content;
        } catch {
          // No CHANGELOG.md yet — buildChangelogUpdate creates one from scratch.
        }
        await companion.writeFile(
          "CHANGELOG.md",
          buildChangelogUpdate(existingChangelog, featureRequest.prompt, approvedPaths),
        );
      } catch {
        // Non-fatal — a failed changelog update shouldn't block the actual feature apply.
      }

      await runGit("git add -A");
      const afterMessage = `DevPlan feat: ${sanitizeForShell(featureRequest.prompt)}`;
      await runGit(`git commit -m "${afterMessage}" --allow-empty`);
      const afterHash = await runGit("git rev-parse HEAD");

      await apiFetch(`/projects/${projectId}/features/${featureRequest.id}/apply`, {
        method: "POST",
        body: JSON.stringify({
          applied_paths: approvedPaths,
          before: { hash: beforeHash, message: beforeMessage },
          after: { hash: afterHash, message: afterMessage },
        }),
      });

      setAppliedCommits({ before: beforeHash, after: afterHash });
      setStage("applied");
      onApplied?.();
    } catch (err) {
      setError(err.message);
      setStage("diff-review");
    }
  }

  const planFiles = featureRequest?.change_set?.files || [];
  const canApply = applyCheckedPaths.size > 0 && companion.paired && !!localPath;

  return {
    featureRequest,
    stage,
    planFiles,
    checkedPaths,
    applyCheckedPaths,
    oldContentByPath,
    appliedCommits,
    error,
    canApply,
    companionPaired: companion.paired,
    toggleFile,
    toggleApplyFile,
    approveAndGenerate,
    applyChanges,
  };
}
