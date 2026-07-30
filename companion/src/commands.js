const crossSpawn = require("cross-spawn");
const fs = require("fs");
const { validateCommand } = require("./commandPolicy");

const TIMEOUT_MS = 120000;
const MAX_OUTPUT_BYTES = 5 * 1024 * 1024;

// Kept for backward compatibility — processes.js and any external caller
// still import { isAllowed } expecting a boolean given the raw command
// string. Internally it's now backed by the same real parser/schema
// validateCommand() uses, not a prefix check.
function isAllowed(command) {
  return validateCommand(command).ok;
}

/**
 * Runs one command to completion and resolves with its result. No shell is
 * ever invoked: the command string is parsed into argv and validated against
 * an explicit per-binary schema (see commandPolicy.js) before
 * cross-spawn.sync launches the real binary directly with that argv array.
 * cross-spawn (not plain child_process.execFile/spawn) is required
 * specifically for Windows, where npm/npx/yarn/pnpm are `.cmd` shims the OS
 * can only launch through cmd.exe — cross-spawn does that safely, with each
 * argument escaped as a literal value, which is a fundamentally different
 * (and safe) thing from handing the whole original string to a shell for
 * interpretation the way the previous exec()-based implementation did.
 */
function runCommand({ command, cwd }) {
  return new Promise((resolve, reject) => {
    const validated = validateCommand(command);
    if (!validated.ok) {
      reject(new Error(validated.reason));
      return;
    }
    if (!cwd || !fs.existsSync(cwd) || !fs.statSync(cwd).isDirectory()) {
      reject(new Error("The project folder does not exist. Create the project first."));
      return;
    }

    const child = crossSpawn(validated.binary, validated.args, {
      cwd,
      windowsHide: true,
      timeout: TIMEOUT_MS,
    });

    let output = "";
    let truncated = false;
    const append = (chunk) => {
      if (truncated) return;
      output += chunk.toString();
      if (output.length > MAX_OUTPUT_BYTES) {
        output = output.slice(0, MAX_OUTPUT_BYTES) + "\n[output truncated]";
        truncated = true;
      }
    };
    child.stdout?.on("data", append);
    child.stderr?.on("data", append);

    child.on("error", (err) => {
      resolve({ command, exitCode: 1, output: `${output}\n[${err.message}]`.trim(), timedOut: false });
    });
    child.on("close", (code, signal) => {
      resolve({
        command,
        exitCode: code ?? 1,
        output: output.trim(),
        timedOut: signal === "SIGTERM",
      });
    });
  });
}

module.exports = { runCommand, isAllowed };
