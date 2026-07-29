const { exec } = require("child_process");
const fs = require("fs");

// Only these command prefixes may run. This is the single most important
// safety boundary in the whole companion — without it, a compromised or
// malicious paired browser session could run anything on the user's machine.
const ALLOWED_PREFIXES = [
  "npm ", "npx ", "pnpm ", "yarn ",
  "git ",
  "composer ",
  "pip ", "pip3 ", "python ", "python3 ",
  "php ",
  "dotnet ",
  "go ",
  "cargo ",
  "flutter ",
  "docker ",
  // Deployment CLIs (Phase 9) — each already requires the user to have
  // authenticated locally (vercel login / railway login / amplify configure)
  // entirely outside DevPlan; this list only gates *which tool* can run, not
  // what it's allowed to do with the user's own already-established auth.
  "vercel ", "railway ", "amplify ",
];

function isAllowed(command) {
  const trimmed = String(command || "").trim();
  return ALLOWED_PREFIXES.some((prefix) => trimmed.startsWith(prefix));
}

function runCommand({ command, cwd }) {
  return new Promise((resolve, reject) => {
    if (!isAllowed(command)) {
      reject(new Error(`Command not allowed: "${command}". Only npm/pnpm/yarn/git/composer/pip/python/php/dotnet/go/cargo/flutter/docker commands can run here.`));
      return;
    }
    if (!cwd || !fs.existsSync(cwd) || !fs.statSync(cwd).isDirectory()) {
      reject(new Error("The project folder does not exist. Create the project first."));
      return;
    }

    exec(command, { cwd, timeout: 120000, windowsHide: true, maxBuffer: 5 * 1024 * 1024 }, (error, stdout, stderr) => {
      resolve({
        command,
        exitCode: error ? (error.code ?? 1) : 0,
        output: `${stdout}\n${stderr}`.trim(),
        timedOut: error?.killed && error?.signal === "SIGTERM",
      });
    });
  });
}

module.exports = { runCommand, isAllowed };
