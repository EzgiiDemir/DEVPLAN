"use client";

import { useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { CheckCircle2, Loader2, Rocket, Sparkles, X, XCircle } from "lucide-react";
import { useCompanion } from "@/lib/companion-context";
import { apiFetch } from "@/lib/api";
import { classifyCommand } from "@/lib/riskClassification";
import { useFocusTrap } from "@/lib/useFocusTrap";
import { EnvironmentManagerPanel } from "./EnvironmentManagerPanel";

function relayCommandExecution(projectId, command, exitCode) {
  apiFetch(`/projects/${projectId}/audit/commands`, {
    method: "POST",
    body: JSON.stringify({ type: "command", command, risk_level: classifyCommand(command), exit_code: exitCode }),
  }).catch(() => {
    // Best-effort — the command already ran either way.
  });
}

const PLATFORMS = ["vercel", "railway", "render", "docker", "aws_amplify"];
const STEPS = ["prepare", "build", "configure", "deploy", "verify"];
const POLL_INTERVAL_MS = 500;
const URL_PATTERN = /https?:\/\/[^\s'"<>]+/g;

async function readIfExists(companion, path) {
  try {
    return (await companion.readFile(path)).content;
  } catch {
    return null;
  }
}

// Returns a list of steps to run in sequence, never a single shell-chained
// string — Companion no longer runs commands through a shell at all (each
// command is parsed into argv and executed directly), so a chained
// `build && push` string can no longer work. Two sequential commands, the
// second only started once the first exits 0, is the same real behavior.
function deployCommandFor(platform, dockerImageName) {
  switch (platform) {
    case "vercel":
      return ["vercel --prod --yes"];
    case "railway":
      return ["railway up"];
    case "aws_amplify":
      return ["amplify publish --yes"];
    case "render":
      // Render's own workflow is "connect the repo once in its dashboard,
      // then redeploy on every push" — DevPlan's part is just the push.
      return ["git push"];
    case "docker":
      return dockerImageName
        ? [`docker build -t ${dockerImageName} .`, `docker push ${dockerImageName}`]
        : ["docker build -t devplan-app ."];
    default:
      return null;
  }
}

function lastUrlIn(text) {
  const matches = text.match(URL_PATTERN);
  return matches?.length ? matches[matches.length - 1].replace(/[.,)]+$/, "") : null;
}

export function DeploymentWizard({ projectId, localPath, onClose, onDeployed }) {
  const t = useTranslations("StudioDeployment");
  const companion = useCompanion();

  const [platform, setPlatform] = useState(null);
  const [step, setStep] = useState("prepare");
  const [analysis, setAnalysis] = useState(null);
  const [analyzing, setAnalyzing] = useState(false);
  const [generatingPath, setGeneratingPath] = useState(null);
  const [generatedPaths, setGeneratedPaths] = useState(new Set());

  const [building, setBuilding] = useState(false);
  const [buildOutput, setBuildOutput] = useState(null);
  const [buildSkipped, setBuildSkipped] = useState(false);

  const [dockerImageName, setDockerImageName] = useState("");

  const [deployment, setDeployment] = useState(null);
  const [deploying, setDeploying] = useState(false);
  const [deployOutput, setDeployOutput] = useState("");
  const [liveUrl, setLiveUrl] = useState(null);

  const [verifying, setVerifying] = useState(false);
  const [verified, setVerified] = useState(null);

  const [error, setError] = useState(null);
  const pollTimerRef = useRef(null);
  const dialogRef = useRef(null);
  useFocusTrap(dialogRef, onClose);

  useEffect(() => () => clearInterval(pollTimerRef.current), []);

  async function choosePlatform(p) {
    setPlatform(p);
    setAnalyzing(true);
    setError(null);
    try {
      const hasVercelJson = (await readIfExists(companion, "vercel.json")) !== null;
      const hasRailwayJson = (await readIfExists(companion, "railway.json")) !== null;
      const hasAmplifyYml = (await readIfExists(companion, "amplify.yml")) !== null;
      const hasDockerfile = (await readIfExists(companion, "Dockerfile")) !== null;

      const result = await apiFetch(`/projects/${projectId}/deployments/analyze`, {
        method: "POST",
        body: JSON.stringify({
          platform: p,
          has_vercel_json: hasVercelJson,
          has_railway_json: hasRailwayJson,
          has_amplify_yml: hasAmplifyYml,
          has_dockerfile: hasDockerfile,
        }),
      });
      setAnalysis(result);
    } catch (err) {
      setError(err.message);
    } finally {
      setAnalyzing(false);
    }
  }

  async function generateConfig(path) {
    setGeneratingPath(path);
    setError(null);
    try {
      await apiFetch(`/projects/${projectId}/deployments/generate-config`, {
        method: "POST",
        body: JSON.stringify({ path, platform }),
      });
      setGeneratedPaths((prev) => new Set(prev).add(path));
    } catch (err) {
      setError(err.message);
    } finally {
      setGeneratingPath(null);
    }
  }

  async function runBuild() {
    setBuilding(true);
    setError(null);
    try {
      const packageJsonContent = await readIfExists(companion, "package.json");
      const pkg = packageJsonContent ? JSON.parse(packageJsonContent) : null;
      if (!pkg?.scripts?.build) {
        setBuildSkipped(true);
        setStep("configure");
        return;
      }
      const result = await companion.runCommand("npm run build", localPath);
      relayCommandExecution(projectId, "npm run build", result.exitCode);
      setBuildOutput(result.output);
      if (result.exitCode !== 0) {
        setError(t("buildFailed"));
        return;
      }
      setStep("configure");
    } catch (err) {
      setError(err.message);
    } finally {
      setBuilding(false);
    }
  }

  // Runs one command to completion via Companion's background-process
  // polling, streaming its output into `onChunk`. Resolves {exitCode}.
  // `onProcessStarted(id)` fires as soon as the process exists, so the
  // caller can record it against the deployment — letting a dropped
  // browser tab reattach to the same still-running process later instead
  // of losing track of an in-progress deployment entirely.
  function runStepToCompletion(command, onChunk, onProcessStarted) {
    return new Promise((resolve, reject) => {
      companion
        .startProcess(command, localPath)
        .then(({ id }) => {
          onProcessStarted?.(id);
          let cursor = 0;
          pollTimerRef.current = setInterval(async () => {
            try {
              const result = await companion.getProcessOutput(id, cursor);
              if (result.output) {
                cursor = result.cursor;
                onChunk(result.output);
              }
              if (!result.running) {
                clearInterval(pollTimerRef.current);
                relayCommandExecution(projectId, command, result.exitCode);
                resolve({ exitCode: result.exitCode });
              }
            } catch (err) {
              clearInterval(pollTimerRef.current);
              reject(err);
            }
          }, POLL_INTERVAL_MS);
        })
        .catch(reject);
    });
  }

  async function startDeploy() {
    setDeploying(true);
    setError(null);
    setDeployOutput("");
    try {
      const created = await apiFetch(`/projects/${projectId}/deployments`, {
        method: "POST",
        body: JSON.stringify({ platform }),
      });
      setDeployment(created);
      await apiFetch(`/projects/${projectId}/deployments/${created.id}`, {
        method: "PATCH",
        body: JSON.stringify({ status: "deploying" }),
      });

      const steps = deployCommandFor(platform, dockerImageName.trim() || null);
      let fullOutput = "";
      let exitCode = 0;
      for (const step of steps) {
        const result = await runStepToCompletion(
          step,
          (chunk) => {
            fullOutput += chunk;
            setDeployOutput(fullOutput);
          },
          (processId) => {
            apiFetch(`/projects/${projectId}/deployments/${created.id}`, {
              method: "PATCH",
              body: JSON.stringify({ status: "deploying", companion_process_id: String(processId) }),
            }).catch(() => {
              // Best-effort — worst case a dropped tab can't reattach to this step.
            });
          },
        );
        exitCode = result.exitCode;
        if (exitCode !== 0) break; // don't run the next step (e.g. push) after a failed build
      }

      const detectedUrl = lastUrlIn(fullOutput);
      setLiveUrl(detectedUrl);

      let gitCommitHash = null;
      try {
        const hashResult = await companion.runCommand("git rev-parse HEAD", localPath);
        if (hashResult.exitCode === 0) gitCommitHash = hashResult.output.trim();
      } catch {
        // Non-fatal — the deployment record just won't link to a checkpoint.
      }

      const finished = await apiFetch(`/projects/${projectId}/deployments/${created.id}`, {
        method: "PATCH",
        body: JSON.stringify({
          status: exitCode === 0 ? "success" : "failed",
          log_output: fullOutput,
          git_commit_hash: gitCommitHash,
          live_url: detectedUrl,
          error_message: exitCode === 0 ? null : t("deployFailed"),
        }),
      });
      setDeployment(finished);
      onDeployed?.();
      if (exitCode === 0) setStep("verify");
      else setError(t("deployFailed"));
    } catch (err) {
      setError(err.message);
    } finally {
      setDeploying(false);
    }
  }

  // A real server-side check (Subsystem 10 — Deployment Hardening) instead
  // of the browser fetching the URL itself: a no-cors browser fetch can
  // only ever tell you whether the request threw, never the actual status
  // code, so it can't distinguish "serving traffic" from "server returned a
  // 500" — the backend's Http client can see the real response.
  async function verifyLive() {
    if (!deployment) return;
    setVerifying(true);
    try {
      const result = await apiFetch(`/projects/${projectId}/deployments/${deployment.id}/health-check`, {
        method: "POST",
      });
      setVerified(result.health_status === "healthy");
    } catch {
      setVerified(false);
    } finally {
      setVerifying(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="deployment-wizard-title"
        tabIndex={-1}
        className="bg-dp-editor-bg text-dp-editor-text rounded-xl border border-dp-editor-border w-full max-w-2xl max-h-[85vh] overflow-y-auto flex flex-col outline-none"
      >
        <div className="flex items-center justify-between px-4 py-3 border-b border-dp-editor-border">
          <span id="deployment-wizard-title" className="flex items-center gap-2 text-sm font-semibold">
            <Rocket size={15} className="text-dp-accent" />
            {t("heading")}
          </span>
          <button type="button" onClick={onClose} aria-label={t("close")} className="text-dp-editor-muted hover:text-dp-editor-text">
            <X size={16} />
          </button>
        </div>

        {!platform ? (
          <div className="p-4 flex flex-col gap-2">
            <p className="text-[12px] text-dp-editor-muted mb-1">{t("choosePlatform")}</p>
            {PLATFORMS.map((p) => (
              <button
                key={p}
                type="button"
                onClick={() => choosePlatform(p)}
                className="text-left px-3 py-2.5 rounded-lg border border-dp-editor-border hover:bg-dp-editor-overlay text-sm font-medium"
              >
                {t(`platform.${p}`)}
              </button>
            ))}
          </div>
        ) : (
          <>
            <div className="flex items-center gap-1 px-4 py-2 border-b border-dp-editor-border text-[11px]">
              {STEPS.map((s) => (
                <span
                  key={s}
                  className={`px-2 py-1 rounded ${step === s ? "bg-dp-accent/20 text-dp-accent-strong font-semibold" : "text-dp-editor-muted"}`}
                >
                  {t(`step.${s}`)}
                </span>
              ))}
            </div>

            <div className="p-4 flex flex-col gap-3">
              {step === "prepare" && (
                <>
                  {analyzing && <p className="text-[12px] text-dp-editor-muted">{t("analyzing")}</p>}
                  {analysis && (
                    <>
                      <p className="text-[12px]">{analysis.summary}</p>
                      {analysis.missing_config_files.length > 0 && (
                        <div className="flex flex-col gap-1.5">
                          <p className="text-[11px] font-semibold uppercase tracking-wider text-dp-editor-muted">{t("missingConfig")}</p>
                          {analysis.missing_config_files.map((path) => (
                            <div key={path} className="flex items-center justify-between border border-dp-editor-border rounded-lg px-2.5 py-1.5">
                              <span className="font-mono text-[12px]">{path}</span>
                              <button
                                type="button"
                                onClick={() => generateConfig(path)}
                                disabled={generatingPath === path || generatedPaths.has(path)}
                                className="flex items-center gap-1 text-[11px] font-semibold px-2 py-1 rounded bg-dp-accent/20 text-dp-accent-strong disabled:opacity-40"
                              >
                                <Sparkles size={10} />
                                {generatedPaths.has(path) ? t("generated") : generatingPath === path ? t("generating") : t("generate")}
                              </button>
                            </div>
                          ))}
                          <p className="text-[10px] text-dp-editor-muted italic">{t("generatedNote")}</p>
                        </div>
                      )}
                      {analysis.required_env_vars.length > 0 && (
                        <p className="text-[11px] text-dp-editor-muted">
                          {t("requiredEnvVarsCount", { count: analysis.required_env_vars.length })}
                        </p>
                      )}
                      <button
                        type="button"
                        onClick={() => setStep("build")}
                        className="self-start text-xs font-semibold px-3 py-1.5 rounded-lg bg-dp-accent text-white"
                      >
                        {t("continue")}
                      </button>
                    </>
                  )}
                </>
              )}

              {step === "build" && (
                <>
                  <p className="text-[12px] text-dp-editor-muted">{t("buildDescription")}</p>
                  {platform === "docker" && (
                    <input
                      value={dockerImageName}
                      onChange={(e) => setDockerImageName(e.target.value)}
                      placeholder={t("dockerImagePlaceholder")}
                      className="bg-dp-editor-overlay rounded px-2 py-1.5 text-[12px] font-mono outline-none"
                    />
                  )}
                  {buildSkipped && <p className="text-[11px] text-dp-editor-muted italic">{t("buildSkipped")}</p>}
                  {buildOutput && <pre className="text-[10px] font-mono bg-dp-editor-overlay rounded p-2 max-h-32 overflow-auto whitespace-pre-wrap">{buildOutput}</pre>}
                  <button
                    type="button"
                    onClick={runBuild}
                    disabled={building}
                    className="self-start flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg bg-dp-accent text-white disabled:opacity-40"
                  >
                    {building && <Loader2 size={12} className="animate-spin" />}
                    {building ? t("building") : t("runBuild")}
                  </button>
                </>
              )}

              {step === "configure" && (
                <>
                  <EnvironmentManagerPanel requiredEnvVars={analysis?.required_env_vars || []} />
                  {platform !== "docker" && platform !== "render" && (
                    <p className="text-[10px] text-dp-editor-muted italic">{t("configurePlatformNote", { platform: t(`platform.${platform}`) })}</p>
                  )}
                  <button
                    type="button"
                    onClick={() => setStep("deploy")}
                    className="self-start text-xs font-semibold px-3 py-1.5 rounded-lg bg-dp-accent text-white"
                  >
                    {t("continue")}
                  </button>
                </>
              )}

              {step === "deploy" && (
                <>
                  <p className="text-[12px] text-dp-editor-muted">{t("deployDescription", { platform: t(`platform.${platform}`) })}</p>
                  {deployOutput && (
                    <pre className="text-[10px] font-mono bg-black/90 text-green-400 rounded p-2 max-h-48 overflow-auto whitespace-pre-wrap">{deployOutput}</pre>
                  )}
                  <button
                    type="button"
                    onClick={startDeploy}
                    disabled={deploying || !companion.paired}
                    className="self-start flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg bg-dp-accent text-white disabled:opacity-40"
                  >
                    {deploying && <Loader2 size={12} className="animate-spin" />}
                    {deploying ? t("deploying") : t("startDeploy")}
                  </button>
                </>
              )}

              {step === "verify" && (
                <>
                  {liveUrl ? (
                    <>
                      <p className="text-[12px]">
                        {t("liveUrlLabel")}{" "}
                        <a href={liveUrl} target="_blank" rel="noreferrer" className="text-dp-accent-strong underline">
                          {liveUrl}
                        </a>
                      </p>
                      <button
                        type="button"
                        onClick={verifyLive}
                        disabled={verifying}
                        className="self-start text-xs font-semibold px-3 py-1.5 rounded-lg bg-dp-editor-overlay disabled:opacity-40"
                      >
                        {verifying ? t("verifying") : t("verifyReachable")}
                      </button>
                      {verified === true && (
                        <p className="flex items-center gap-1.5 text-[12px] text-green-500">
                          <CheckCircle2 size={13} /> {t("reachable")}
                        </p>
                      )}
                      {verified === false && (
                        <p className="flex items-center gap-1.5 text-[12px] text-amber-500">
                          <XCircle size={13} /> {t("notYetReachable")}
                        </p>
                      )}
                    </>
                  ) : (
                    <p className="text-[12px] text-dp-editor-muted italic">{t("noUrlDetected")}</p>
                  )}
                  <button type="button" onClick={onClose} className="self-start text-xs font-semibold px-3 py-1.5 rounded-lg bg-dp-editor-overlay">
                    {t("done")}
                  </button>
                </>
              )}

              {error && <p className="text-[11px] text-red-400">{error}</p>}
            </div>
          </>
        )}
      </div>
    </div>
  );
}
