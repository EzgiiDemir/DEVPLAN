"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Send, Trash2 } from "lucide-react";
import { apiFetch } from "@/lib/api";

export function CommentsPanel({ projectId, canAct }) {
  const t = useTranslations("StudioDiscussion");

  const [comments, setComments] = useState(null);
  const [body, setBody] = useState("");
  const [sending, setSending] = useState(false);
  const [error, setError] = useState(null);

  async function load() {
    try {
      setComments(
        await apiFetch(`/projects/${projectId}/comments?commentable_type=project&commentable_id=${projectId}`),
      );
    } catch (err) {
      setError(err.message);
    }
  }

  useEffect(() => {
    void Promise.resolve().then(load);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId]);

  async function send(e) {
    e.preventDefault();
    if (!body.trim() || sending) return;
    setSending(true);
    setError(null);
    try {
      await apiFetch(`/projects/${projectId}/comments`, {
        method: "POST",
        body: JSON.stringify({ commentable_type: "project", commentable_id: projectId, body }),
      });
      setBody("");
      await load();
    } catch (err) {
      setError(err.message);
    } finally {
      setSending(false);
    }
  }

  async function remove(comment) {
    try {
      await apiFetch(`/projects/${projectId}/comments/${comment.id}`, { method: "DELETE" });
      await load();
    } catch (err) {
      setError(err.message);
    }
  }

  return (
    <div className="h-full flex flex-col text-dp-editor-text">
      <div className="flex-1 overflow-y-auto p-3 flex flex-col gap-2">
        {comments?.length === 0 && <p className="text-[12px] text-dp-editor-muted italic">{t("empty")}</p>}
        {comments?.map((c) => (
          <div key={c.id} className="text-[12px] border-b border-dp-editor-border pb-2 last:border-0">
            <div className="flex items-center justify-between gap-2">
              <span className="font-semibold">{c.user?.name}</span>
              <button type="button" onClick={() => remove(c)} className="text-dp-editor-muted hover:text-red-400">
                <Trash2 size={11} />
              </button>
            </div>
            <p className="text-dp-editor-text whitespace-pre-wrap">{c.body}</p>
          </div>
        ))}
        {error && <p className="text-[11px] text-red-400">{error}</p>}
      </div>

      <form onSubmit={send} className="p-2 border-t border-dp-editor-border flex items-center gap-2">
        <input
          value={body}
          onChange={(e) => setBody(e.target.value)}
          disabled={!canAct || sending}
          placeholder={canAct ? t("placeholder") : t("viewOnly")}
          className="flex-1 bg-dp-editor-overlay rounded-full px-3 py-1.5 text-[12px] outline-none disabled:cursor-not-allowed"
        />
        <button type="submit" disabled={!canAct || sending || !body.trim()} className="disabled:opacity-40">
          <Send size={14} className="text-dp-editor-muted" />
        </button>
      </form>
    </div>
  );
}
