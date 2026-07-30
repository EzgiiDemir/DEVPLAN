"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { CheckCircle2, RotateCw, ShieldAlert, Sparkles, XCircle } from "lucide-react";
import { useCompanion } from "@/lib/companion-context";
import { apiFetch } from "@/lib/api";
import { classifyCommand } from "@/lib/riskClassification";

function relayCommandExecution(projectId, command, exitCode) {
  apiFetch(`/projects/${projectId}/audit/commands`, {
    method: "POST",
    body: JSON.stringify({ type: "command", command, risk_level: classifyCommand(command), exit_code: exitCode }),
  }).catch(() => {
    // Best-effort — the command already ran either way.
  });
}

const PYTEST_CONFIG_NAMES = ["pytest.ini", "pyproject.toml", "setup.cfg"];
const ESLINT_CONFIG_NAMES = [".eslintrc.json", ".eslintrc.js", ".eslintrc.cjs", "eslint.config.js", "eslint.config.mjs"];

async function readIfExists(companion, path) {
  try {
    return (await companion.readFile(path)).content;
  } catch {
    return null;
  }
}

async function existsAny(companion, names) {
  for (const name of names) {
    if ((await readIfExists(companion, name)) !== null) return true;
  }
  return false;
}

export function TestingPanel({ projectId, localPath, canAct = true }) {
  const t = useTranslations("StudioTesting");
  const companion = useCompanion();

  const [detected, setDetected] = useState(null);
  const [detecting, setDetecting] = useState(true);
  const [running, setRunning] = useState(false);
  const [latestRun, setLatestRun] = useState(null);
  const [suggestingFixIndex, setSuggestingFixIndex] = useState(null);
  const [fixRequested, setFixRequested] = useState(new Set());
  const [quality, setQuality] = useState(null);
  const [scanningQuality, setScanningQuality] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    void Promise.resolve().then(async () => {
      try {
        const history = await apiFetch(`/projects/${projectId}/tests`);
        if (history.length) setLatestRun(history[0]);
      } catch {
        // No history yet — fine.
      }
      try {
        setQuality(await apiFetch(`/projects/${projectId}/quality`));
      } catch {
        // Not scanned yet — fine.
      }
    });
  }, [projectId]);

  useEffect(() => {
    void Promise.resolve().then(async () => {
      if (!companion.paired) {
        setDetecting(false);
        return;
      }
      setDetecting(true);
      try {
        const packageJsonContent = await readIfExists(companion, "package.json");
        const composerJsonContent = await readIfExists(companion, "composer.json");
        const hasPytestConfig = await existsAny(companion, PYTEST_CONFIG_NAMES);

        const result = await apiFetch(`/projects/${projectId}/tests/detect`, {
          method: "POST",
          body: JSON.stringify({
            package_json_content: packageJsonContent,
            composer_json_content: composerJsonContent,
            has_pytest_config: hasPytestConfig,
          }),
        });
        setDetected(result);
      } catch (err) {
        setError(err.message);
      } finally {
        setDetecting(false);
      }
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId, companion.paired]);

  async function runTests() {
    if (!detected?.framework || running || !canAct) return;
    setRunning(true);
    setError(null);
    setFixRequested(new Set());
    try {
      // Test runners' --outputFile/--log-junit flags don't create their
      // parent directory themselves (confirmed against real Jest — it fails
      // with ENOENT otherwise); writeFile already creates parent directories,
      // so writing an empty placeholder first guarantees .devplan/ exists
      // before the real command runs.
      try {
        await companion.writeFile(detected.resultFilePath, "");
      } catch {
        // Best-effort — if this fails, the run command below will too, and
        // that failure surfaces naturally.
      }

      const execResult = await companion.runCommand(detected.runCommand, localPath);
      relayCommandExecution(projectId, detected.runCommand, execResult.exitCode);
      const resultFileContent = await readIfExists(companion, detected.resultFilePath);
      const coverageFileContent = detected.coverageFilePath
        ? await readIfExists(companion, detected.coverageFilePath)
        : null;

      const testRun = await apiFetch(`/projects/${projectId}/tests/record`, {
        method: "POST",
        body: JSON.stringify({
          framework: detected.framework,
          result_file_content: resultFileContent,
          exit_code: execResult.exitCode,
          coverage_file_content: coverageFileContent,
        }),
      });
      setLatestRun(testRun);
    } catch (err) {
      setError(err.message);
    } finally {
      setRunning(false);
    }
  }

  async function suggestFix(index) {
    if (!canAct) return;
    setSuggestingFixIndex(index);
    setError(null);
    try {
      await apiFetch(`/projects/${projectId}/tests/${latestRun.id}/suggest-fix`, {
        method: "POST",
        body: JSON.stringify({ failure_index: index }),
      });
      setFixRequested((prev) => new Set(prev).add(index));
    } catch (err) {
      setError(err.message);
    } finally {
      setSuggestingFixIndex(null);
    }
  }

  async function rescanQuality() {
    if (!canAct) return;
    setScanningQuality(true);
    setError(null);
    try {
      const hasPackageJson = (await readIfExists(companion, "package.json")) !== null;
      const hasComposerJson = (await readIfExists(companion, "composer.json")) !== null;
      const hasEslintConfig = await existsAny(companion, ESLINT_CONFIG_NAMES);

      const detectResult = await apiFetch(`/projects/${projectId}/quality/detect`, {
        method: "POST",
        body: JSON.stringify({
          has_package_json: hasPackageJson,
          has_composer_json: hasComposerJson,
          has_eslint_config: hasEslintConfig,
        }),
      });

      const outputs = {};
      for (const [key, command] of Object.entries(detectResult.commands)) {
        const result = await companion.runCommand(command, localPath);
        relayCommandExecution(projectId, command, result.exitCode);
        outputs[key] = result.output;
      }

      const snapshot = await apiFetch(`/projects/${projectId}/quality/scan`, {
        method: "POST",
        body: JSON.stringify({
          npm_audit_json: outputs.npm_audit || null,
          composer_audit_json: outputs.composer_audit || null,
          eslint_json: outputs.eslint || null,
        }),
      });
      setQuality(snapshot);
    } catch (err) {
      setError(err.message);
    } finally {
      setScanningQuality(false);
    }
  }

  return (
    <div className="h-full overflow-y-auto p-3 flex flex-col gap-4 text-dp-editor-text">
      <section>
        <div className="flex items-center justify-between mb-2">
          <h3 className="text-[11px] font-semibold uppercase tracking-wider text-dp-editor-muted">{t("testsHeading")}</h3>
          <button
            type="button"
            onClick={runTests}
            disabled={!detected?.framework || running || !companion.paired || !canAct}
            className="text-xs font-semibold px-3 py-1.5 rounded-lg bg-dp-accent text-white disabled:opacity-40 disabled:cursor-not-allowed"
          >
            {running ? t("running") : t("runTests")}
          </button>
        </div>

        {detecting && <p className="text-[12px] text-dp-editor-muted">{t("detecting")}</p>}
        {!detecting && !detected?.framework && companion.paired && (
          <p className="text-[12px] text-dp-editor-muted italic">{t("noFrameworkDetected")}</p>
        )}
        {!companion.paired && <p className="text-[12px] text-dp-editor-muted italic">{t("needsCompanion")}</p>}
        {detected?.framework && (
          <p className="text-[11px] text-dp-editor-muted mb-2">{t("frameworkDetected", { framework: detected.framework })}</p>
        )}

        {latestRun && (
          <div className="flex flex-col gap-2">
            <div className="flex items-center gap-2 text-[12px]">
              {latestRun.status === "passed" ? (
                <CheckCircle2 size={14} className="text-green-500" />
              ) : (
                <XCircle size={14} className="text-red-400" />
              )}
              <span>
                {t("summary", { passed: latestRun.passed_count, total: latestRun.total_count })}
              </span>
              {latestRun.coverage_percent != null && (
                <span className="text-dp-editor-muted">· {t("coverage", { pct: latestRun.coverage_percent })}</span>
              )}
            </div>

            {latestRun.status === "error" && (
              <p className="text-[11px] text-amber-500 italic">{t("errorRun")}</p>
            )}

            {(latestRun.failures || []).map((failure, i) => (
              <div key={i} className="border border-dp-editor-border rounded-lg p-2 text-[11px]">
                <p className="font-semibold text-dp-editor-text">{failure.name}</p>
                {failure.file && <p className="text-dp-editor-muted font-mono">{failure.file}</p>}
                <pre className="text-red-400 whitespace-pre-wrap mt-1">{failure.message}</pre>
                <button
                  type="button"
                  onClick={() => suggestFix(i)}
                  disabled={suggestingFixIndex === i || fixRequested.has(i) || !companion.paired || !canAct}
                  className="mt-1.5 flex items-center gap-1 text-[11px] font-semibold px-2 py-1 rounded bg-dp-accent/20 text-dp-accent-strong disabled:opacity-40"
                >
                  <Sparkles size={11} />
                  {fixRequested.has(i) ? t("fixRequested") : suggestingFixIndex === i ? t("suggestingFix") : t("suggestFix")}
                </button>
              </div>
            ))}
          </div>
        )}
      </section>

      <section className="border-t border-dp-editor-border pt-3">
        <div className="flex items-center justify-between mb-2">
          <h3 className="text-[11px] font-semibold uppercase tracking-wider text-dp-editor-muted">{t("qualityHeading")}</h3>
          <button
            type="button"
            onClick={rescanQuality}
            disabled={scanningQuality || !companion.paired || !canAct}
            className="flex items-center gap-1 text-xs font-semibold px-2 py-1 rounded-lg bg-dp-editor-overlay text-dp-editor-text disabled:opacity-40"
          >
            <RotateCw size={11} className={scanningQuality ? "animate-spin" : ""} />
            {t("rescanQuality")}
          </button>
        </div>

        {quality?.scanned_at ? (
          <div className="flex flex-col gap-2 text-[12px]">
            <div className="flex items-center gap-1.5">
              <ShieldAlert size={13} className={quality.security?.critical || quality.security?.high ? "text-red-400" : "text-dp-editor-muted"} />
              <span>
                {t("securitySummary", {
                  critical: quality.security?.critical ?? 0,
                  high: quality.security?.high ?? 0,
                  moderate: quality.security?.moderate ?? 0,
                  low: quality.security?.low ?? 0,
                })}
              </span>
            </div>
            <p>{t("qualitySummary", { errors: quality.code_quality?.errors ?? 0, warnings: quality.code_quality?.warnings ?? 0 })}</p>
            <p>
              {quality.coverage_percent != null
                ? t("coverage", { pct: quality.coverage_percent })
                : t("noCoverageYet")}
            </p>
            <p className="text-dp-editor-muted italic">{t("performanceNotAvailable")}</p>
          </div>
        ) : (
          <p className="text-[12px] text-dp-editor-muted italic">{t("neverScanned")}</p>
        )}
      </section>

      {error && <p className="text-[11px] text-red-400">{error}</p>}
    </div>
  );
}
