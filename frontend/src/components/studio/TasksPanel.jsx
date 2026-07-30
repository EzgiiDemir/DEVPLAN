"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Plus, Trash2 } from "lucide-react";
import { apiFetch } from "@/lib/api";

const STATUSES = ["todo", "doing", "done"];

export function TasksPanel({ projectId, teamId, canAct }) {
  const t = useTranslations("StudioTasks");

  const [tasks, setTasks] = useState(null);
  const [members, setMembers] = useState([]);
  const [title, setTitle] = useState("");
  const [adding, setAdding] = useState(false);
  const [error, setError] = useState(null);

  async function load() {
    try {
      setTasks(await apiFetch(`/projects/${projectId}/tasks`));
    } catch (err) {
      setError(err.message);
    }
  }

  useEffect(() => {
    void Promise.resolve().then(load);
    apiFetch(`/teams/${teamId}/members`)
      .then(setMembers)
      .catch(() => setMembers([]));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId, teamId]);

  async function addTask(e) {
    e.preventDefault();
    if (!title.trim()) return;
    setAdding(true);
    setError(null);
    try {
      await apiFetch(`/projects/${projectId}/tasks`, { method: "POST", body: JSON.stringify({ title }) });
      setTitle("");
      await load();
    } catch (err) {
      setError(err.message);
    } finally {
      setAdding(false);
    }
  }

  async function updateTask(task, patch) {
    try {
      await apiFetch(`/projects/${projectId}/tasks/${task.id}`, { method: "PATCH", body: JSON.stringify(patch) });
      await load();
    } catch (err) {
      setError(err.message);
    }
  }

  async function deleteTask(task) {
    try {
      await apiFetch(`/projects/${projectId}/tasks/${task.id}`, { method: "DELETE" });
      await load();
    } catch (err) {
      setError(err.message);
    }
  }

  const byStatus = STATUSES.reduce((acc, s) => ({ ...acc, [s]: (tasks || []).filter((task) => task.status === s) }), {});

  return (
    <div className="h-full overflow-y-auto p-3 text-dp-editor-text">
      {canAct && (
        <form onSubmit={addTask} className="flex gap-2 mb-3">
          <input
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            placeholder={t("titlePlaceholder")}
            className="flex-1 rounded-lg border border-dp-editor-border bg-dp-editor-overlay px-2.5 py-1.5 text-[12px] outline-none"
          />
          <button
            type="submit"
            disabled={adding || !title.trim()}
            className="flex items-center gap-1 text-xs font-semibold px-3 py-1.5 rounded-lg bg-dp-accent text-white disabled:opacity-40"
          >
            <Plus size={12} /> {t("addTask")}
          </button>
        </form>
      )}

      {tasks && tasks.length === 0 && <p className="text-[12px] text-dp-editor-muted italic">{t("empty")}</p>}

      <div className="grid grid-cols-3 gap-3">
        {STATUSES.map((status) => (
          <div key={status} className="flex flex-col gap-1.5">
            <h3 className="text-[10px] font-semibold uppercase tracking-wider text-dp-editor-muted">
              {t(`status.${status}`)} ({byStatus[status]?.length ?? 0})
            </h3>
            {byStatus[status]?.map((task) => (
              <div key={task.id} className="border border-dp-editor-border rounded-lg p-2 text-[11px] flex flex-col gap-1.5">
                <div className="flex items-start justify-between gap-1">
                  <span className="flex-1">{task.title}</span>
                  {canAct && (
                    <button type="button" onClick={() => deleteTask(task)} aria-label={t("delete")} className="text-dp-editor-muted hover:text-red-400 flex-shrink-0">
                      <Trash2 size={11} />
                    </button>
                  )}
                </div>
                {canAct ? (
                  <div className="flex flex-col gap-1">
                    <select
                      value={task.status}
                      onChange={(e) => updateTask(task, { status: e.target.value })}
                      className="rounded border border-dp-editor-border bg-dp-editor-overlay px-1.5 py-1 text-[10px] outline-none"
                    >
                      {STATUSES.map((s) => (
                        <option key={s} value={s}>
                          {t(`status.${s}`)}
                        </option>
                      ))}
                    </select>
                    <select
                      value={task.assigned_to_user_id ?? task.assignee?.id ?? ""}
                      onChange={(e) => updateTask(task, { assigned_to_user_id: e.target.value ? Number(e.target.value) : null })}
                      className="rounded border border-dp-editor-border bg-dp-editor-overlay px-1.5 py-1 text-[10px] outline-none"
                    >
                      <option value="">{t("unassigned")}</option>
                      {members.map((m) => (
                        <option key={m.user_id} value={m.user_id}>
                          {m.user?.name}
                        </option>
                      ))}
                    </select>
                  </div>
                ) : (
                  <span className="text-dp-editor-muted">{task.assignee?.name || t("unassigned")}</span>
                )}
              </div>
            ))}
          </div>
        ))}
      </div>

      {error && <p className="text-[11px] text-red-400 mt-2">{error}</p>}
    </div>
  );
}
