const { spawn, execFile } = require("child_process");
const { isAllowed } = require("./commands");

const MAX_OUTPUT_BYTES = 200 * 1024;
const MAX_CONCURRENT = 3;
const KILL_GRACE_MS = 3000;

// On Windows, spawn(cmd, {shell: true}) creates a process tree —
// cmd.exe -> npm.cmd -> node.exe for something like "npm run dev" — and
// child.kill() only terminates the top-level cmd.exe, leaving the actual
// dev server running as an orphan still writing to the (now detached)
// stdout pipe. taskkill's /T flag kills the whole tree; POSIX shells don't
// have this problem the same way, so they keep the plain signal-based kill.
function killTree(child, signal) {
  if (process.platform === "win32") {
    execFile("taskkill", ["/pid", String(child.pid), "/T", "/F"], () => {});
  } else {
    child.kill(signal);
  }
}

// Background processes are the one execution mode `/run` (exec-based, waits
// for completion) can't cover — a dev server never finishes. Same allowlist
// as /run, just spawn() instead of exec() so output streams incrementally
// instead of being collected only at the end.
class ProcessManager {
  constructor() {
    this.processes = new Map(); // id -> { child, command, output, cursor, running, exitCode, startedAt }
    this.nextId = 1;
  }

  start({ command, cwd }) {
    if (!isAllowed(command)) {
      throw new Error(`Command not allowed: "${command}". Only npm/pnpm/yarn/git/composer/pip/python/php/dotnet/go/cargo/flutter/docker commands can run here.`);
    }
    const runningCount = [...this.processes.values()].filter((p) => p.running).length;
    if (runningCount >= MAX_CONCURRENT) {
      throw new Error(`Too many background processes already running (max ${MAX_CONCURRENT}). Stop one first.`);
    }

    const id = String(this.nextId++);
    const child = spawn(command, { cwd, shell: true, windowsHide: true });

    const entry = {
      child,
      command,
      output: "",
      running: true,
      exitCode: null,
      startedAt: Date.now(),
    };
    this.processes.set(id, entry);

    const append = (chunk) => {
      entry.output += chunk.toString();
      if (entry.output.length > MAX_OUTPUT_BYTES) {
        entry.output = entry.output.slice(entry.output.length - MAX_OUTPUT_BYTES);
      }
    };

    child.stdout.on("data", append);
    child.stderr.on("data", append);
    child.on("close", (code) => {
      entry.running = false;
      entry.exitCode = code;
    });
    child.on("error", (err) => {
      entry.running = false;
      entry.exitCode = 1;
      append(`\n[process error: ${err.message}]`);
    });

    return { id };
  }

  getOutput(id, since = 0) {
    const entry = this.processes.get(id);
    if (!entry) {
      throw new Error("No such process.");
    }
    const cursor = Math.min(since, entry.output.length);
    return {
      output: entry.output.slice(cursor),
      cursor: entry.output.length,
      running: entry.running,
      exitCode: entry.exitCode,
    };
  }

  writeInput(id, data) {
    const entry = this.processes.get(id);
    if (!entry || !entry.running) {
      throw new Error("Process is not running.");
    }
    entry.child.stdin.write(data);
  }

  stop(id) {
    const entry = this.processes.get(id);
    if (!entry) {
      throw new Error("No such process.");
    }
    if (!entry.running) return;
    killTree(entry.child, "SIGTERM");
    if (process.platform !== "win32") {
      setTimeout(() => {
        if (entry.running) killTree(entry.child, "SIGKILL");
      }, KILL_GRACE_MS);
    }
  }

  list() {
    return [...this.processes.entries()].map(([id, entry]) => ({
      id,
      command: entry.command,
      running: entry.running,
      exitCode: entry.exitCode,
      startedAt: entry.startedAt,
    }));
  }

  // Called when the active project changes or Companion shuts down — a dev
  // server for a project the user just navigated away from shouldn't keep
  // running invisibly.
  stopAll() {
    for (const entry of this.processes.values()) {
      if (entry.running) killTree(entry.child, "SIGTERM");
    }
  }
}

module.exports = { ProcessManager };
