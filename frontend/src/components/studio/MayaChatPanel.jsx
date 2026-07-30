"use client";

import { useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { File as FileIcon, Send } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { pollAiJob } from "@/lib/aiJobs";
import { MayaAvatar } from "@/components/MayaAvatar";
import { FeatureCard } from "./FeatureCard";

const FEATURE_INTENTS = new Set(["refactor", "feature_request", "test", "fix"]);

export function MayaChatPanel({ projectId, localPath, activeFile, onApplied, canAct = true }) {
  const t = useTranslations("MayaChat");

  const [messages, setMessages] = useState([]);
  const [loadingHistory, setLoadingHistory] = useState(true);
  const [input, setInput] = useState("");
  const [sending, setSending] = useState(false);
  const [error, setError] = useState(null);
  const scrollRef = useRef(null);

  useEffect(() => {
    let cancelled = false;
    apiFetch(`/projects/${projectId}/maya/messages`)
      .then((data) => {
        if (!cancelled) setMessages(data);
      })
      .catch(() => {})
      .finally(() => {
        if (!cancelled) setLoadingHistory(false);
      });
    return () => {
      cancelled = true;
    };
  }, [projectId]);

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight });
  }, [messages]);

  async function sendMessage(e) {
    e.preventDefault();
    if (!input.trim() || sending) return;

    setSending(true);
    setError(null);
    const text = input;
    setInput("");

    // handleMessage() now runs in a background job (Subsystem 3 — Queue
    // System) instead of blocking this request, so the user's own message
    // wouldn't otherwise appear until the whole round trip finishes —
    // render it optimistically now, then reconcile with the real history
    // (which replaces this temporary bubble) once the job resolves.
    const tempId = `pending-${Date.now()}`;
    setMessages((prev) => [...prev, { id: tempId, role: "user", content: text, intent: null }]);

    try {
      const dispatched = await apiFetch(`/projects/${projectId}/maya/messages`, {
        method: "POST",
        body: JSON.stringify({ message: text, active_file: activeFile || undefined }),
      });
      await pollAiJob(dispatched.job_id);
      const history = await apiFetch(`/projects/${projectId}/maya/messages`);
      setMessages(history);
    } catch (err) {
      setError(err.message);
      setInput(text);
      setMessages((prev) => prev.filter((m) => m.id !== tempId));
    } finally {
      setSending(false);
    }
  }

  return (
    <>
      <div className="flex items-center gap-2 px-4 py-3 border-b border-dp-editor-border">
        <MayaAvatar className="w-7 h-7" />
        <span className="text-sm font-semibold text-dp-editor-text">{t("mayaLabel")}</span>
      </div>

      {activeFile && (
        <div className="flex items-center gap-1.5 px-4 py-1.5 text-[10px] text-dp-editor-muted bg-dp-editor-overlay/40 border-b border-dp-editor-border truncate">
          <FileIcon size={10} className="flex-shrink-0" />
          <span className="truncate">{t("activeFileChip", { file: activeFile })}</span>
        </div>
      )}

      <div ref={scrollRef} className="flex-1 overflow-y-auto p-4 flex flex-col gap-3">
        {loadingHistory && <p className="text-[12px] text-dp-editor-muted">{t("loadingHistory")}</p>}

        {!loadingHistory && messages.length === 0 && (
          <p className="text-[13px] text-dp-editor-muted">{t("intro")}</p>
        )}

        {messages.map((msg) =>
          msg.role === "user" ? (
            <div
              key={msg.id}
              className="text-[13px] leading-relaxed px-3.5 py-2.5 rounded-2xl max-w-[95%] bg-dp-accent text-white self-end rounded-tr-sm"
            >
              {msg.content}
            </div>
          ) : (
            <div key={msg.id} className="flex flex-col gap-2 max-w-[95%] self-start">
              <div className="text-[13px] leading-relaxed px-3.5 py-2.5 rounded-2xl bg-dp-editor-overlay text-dp-editor-text rounded-tl-sm whitespace-pre-wrap">
                {msg.content}
              </div>
              {FEATURE_INTENTS.has(msg.intent) && msg.feature_request && (
                <FeatureCard
                  featureRequest={msg.feature_request}
                  projectId={projectId}
                  localPath={localPath}
                  onApplied={onApplied}
                  canAct={canAct}
                />
              )}
            </div>
          ),
        )}

        {sending && <p className="text-[12px] text-dp-editor-muted">{t("thinking")}</p>}
        {error && <p className="text-[12px] text-red-400">{error}</p>}
      </div>

      <form onSubmit={sendMessage} className="p-3 border-t border-dp-editor-border">
        <div className={`flex items-center gap-2 bg-dp-editor-overlay rounded-full px-3.5 py-2.5 ${sending || !canAct ? "opacity-60" : ""}`}>
          <input
            value={input}
            onChange={(e) => setInput(e.target.value)}
            disabled={sending || !canAct}
            placeholder={canAct ? t("inputPlaceholder") : t("viewOnlyPlaceholder")}
            className="flex-1 bg-transparent text-xs text-dp-editor-text placeholder:text-dp-editor-muted outline-none disabled:cursor-not-allowed"
          />
          <button type="submit" disabled={sending || !canAct || !input.trim()} className="disabled:opacity-40">
            <Send size={14} className="text-dp-editor-muted" />
          </button>
        </div>
      </form>
    </>
  );
}
