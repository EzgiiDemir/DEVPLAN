"use client";

import { useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronDown, ChevronUp, Square, TerminalSquare } from "lucide-react";
import { Terminal } from "@xterm/xterm";
import { FitAddon } from "@xterm/addon-fit";
import "@xterm/xterm/css/xterm.css";
import { useCompanion } from "@/lib/companion-context";
import { apiFetch } from "@/lib/api";
import { classifyCommand } from "@/lib/riskClassification";

const QUICK_COMMANDS = ["npm install", "npm run dev", "npm run build", "git init", "git add .", "git commit -m \"Initial commit from DevPlan\""];
const POLL_INTERVAL_MS = 300;
const PORT_PATTERN = /(?:https?:\/\/)?(?:localhost|127\.0\.0\.1):(\d{2,5})/i;

function relayCommandExecution(projectId, command, riskLevel, exitCode) {
  if (!projectId) return;
  apiFetch(`/projects/${projectId}/audit/commands`, {
    method: "POST",
    body: JSON.stringify({ type: "command", command, risk_level: riskLevel, exit_code: exitCode }),
  }).catch(() => {
    // Best-effort — the command already ran; a failed audit relay
    // shouldn't surface as if the command itself failed.
  });
}

// Real spawned process with streamed output, not a full PTY (no node-pty,
// which is a fragile native build inside Electron) — plain line-buffered
// stdin covers npm/git/dev servers fine; a redrawing interactive REPL
// wouldn't render perfectly. Same honest trade-off as the command allowlist.
export function TerminalPanel({ cwd, projectId, onPortDetected }) {
  const t = useTranslations("StudioTerminal");
  const companion = useCompanion();
  const [open, setOpen] = useState(true);
  const [draft, setDraft] = useState("");
  const [running, setRunning] = useState(false);

  const containerRef = useRef(null);
  const termRef = useRef(null);
  const fitAddonRef = useRef(null);
  const processIdRef = useRef(null);
  const pollTimerRef = useRef(null);

  useEffect(() => {
    if (!containerRef.current) return;

    const term = new Terminal({
      fontSize: 12,
      convertEol: true,
      disableStdin: false,
      theme: { background: "#00000000" },
    });
    const fitAddon = new FitAddon();
    term.loadAddon(fitAddon);
    term.open(containerRef.current);
    fitAddon.fit();
    termRef.current = term;
    fitAddonRef.current = fitAddon;

    term.onData((data) => {
      if (processIdRef.current) {
        companion.sendProcessInput(processIdRef.current, data).catch(() => {});
      }
    });

    const onResize = () => fitAddon.fit();
    window.addEventListener("resize", onResize);

    return () => {
      window.removeEventListener("resize", onResize);
      if (pollTimerRef.current) clearInterval(pollTimerRef.current);
      term.dispose();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (open) setTimeout(() => fitAddonRef.current?.fit(), 0);
  }, [open]);

  async function run(command) {
    if (!command.trim() || running || !termRef.current) return;

    const riskLevel = classifyCommand(command);
    if (riskLevel === "dangerous" && !window.confirm(t("confirmDangerous", { command }))) {
      return;
    }

    setRunning(true);
    termRef.current.write(`\r\n$ ${command}\r\n`);

    try {
      const { id } = await companion.startProcess(command, cwd);
      processIdRef.current = id;
      let cursor = 0;

      pollTimerRef.current = setInterval(async () => {
        try {
          const result = await companion.getProcessOutput(id, cursor);
          if (result.output) {
            termRef.current.write(result.output);
            cursor = result.cursor;
            const portMatch = result.output.match(PORT_PATTERN);
            if (portMatch) onPortDetected?.(portMatch[1]);
          }
          if (!result.running) {
            clearInterval(pollTimerRef.current);
            processIdRef.current = null;
            setRunning(false);
            termRef.current.write(`\r\n[${t("exitCode", { code: result.exitCode })}]\r\n`);
            relayCommandExecution(projectId, command, riskLevel, result.exitCode);
          }
        } catch (err) {
          clearInterval(pollTimerRef.current);
          processIdRef.current = null;
          setRunning(false);
          termRef.current.write(`\r\n[${err.message}]\r\n`);
        }
      }, POLL_INTERVAL_MS);
    } catch (err) {
      termRef.current.write(`\r\n[${err.message}]\r\n`);
      setRunning(false);
    }
  }

  async function stop() {
    if (!processIdRef.current) return;
    await companion.stopProcess(processIdRef.current).catch(() => {});
  }

  if (!cwd) return null;

  return (
    <div className="border-t border-dp-editor-border bg-dp-editor-panel flex flex-col" style={{ height: open ? 240 : 40 }}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="flex items-center justify-between px-4 py-2 text-xs font-semibold text-dp-editor-muted hover:text-dp-editor-text transition-colors flex-shrink-0"
      >
        <span className="flex items-center gap-1.5">
          <TerminalSquare size={13} /> {t("heading")}
        </span>
        {open ? <ChevronDown size={13} /> : <ChevronUp size={13} />}
      </button>

      {open && (
        <>
          <div className="flex flex-wrap items-center gap-1.5 px-4 pb-2 flex-shrink-0">
            {QUICK_COMMANDS.map((cmd) => (
              <button
                key={cmd}
                onClick={() => run(cmd)}
                disabled={running}
                className="text-[11px] font-mono bg-dp-editor-overlay rounded-md px-2 py-1 text-dp-editor-text hover:opacity-80 disabled:opacity-40 transition-opacity"
              >
                {cmd}
              </button>
            ))}
            {running && (
              <button
                onClick={stop}
                className="flex items-center gap-1 text-[11px] font-semibold bg-red-500/20 text-red-400 rounded-md px-2 py-1 hover:opacity-80"
              >
                <Square size={10} /> {t("stop")}
              </button>
            )}
          </div>
          <div className="flex-1 min-h-0 px-2">
            <div ref={containerRef} className="h-full" />
          </div>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              run(draft);
              setDraft("");
            }}
            className="flex items-center gap-2 px-4 py-2 border-t border-dp-editor-border flex-shrink-0"
          >
            <span className="text-dp-editor-muted text-xs font-mono">$</span>
            <input
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              placeholder={t("inputPlaceholder")}
              disabled={running}
              className="flex-1 bg-transparent text-xs font-mono text-dp-editor-text placeholder:text-dp-editor-muted outline-none"
            />
          </form>
        </>
      )}
    </div>
  );
}
