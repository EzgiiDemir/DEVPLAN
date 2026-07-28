"use client";

import { useEffect, useRef, useState } from "react";
import { useTranslations, useLocale } from "next-intl";
import { Plus, Trash2, Check } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { AiBtn, TinyBtn, CompleteButton } from "@/components/ui/Buttons";

export function TaskPlanModule({ module, isDone, onComplete }) {
  const t = useTranslations("TaskPlanModule");
  const tCommon = useTranslations("Common");
  const locale = useLocale();
  const [loading, setLoading] = useState(true);
  const [itemId, setItemId] = useState(null);
  const [sprints, setSprints] = useState([]);
  const [drafts, setDrafts] = useState({});
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState("");
  const dragRef = useRef(null);

  useEffect(() => {
    apiFetch(`/modules/${module.id}/items`).then((items) => {
      const item = items.find((i) => i.item_type === "sprint_plan");
      if (item) {
        setItemId(item.id);
        setSprints(item.content.sprints || []);
      }
      setLoading(false);
    });
  }, [module.id]);

  async function persist(nextSprints) {
    setSprints(nextSprints);
    const content = { sprints: nextSprints };
    if (itemId) {
      await apiFetch(`/items/${itemId}`, {
        method: "PUT",
        body: JSON.stringify({ content, is_user_edited: true }),
      });
    } else {
      const created = await apiFetch(`/modules/${module.id}/items`, {
        method: "POST",
        body: JSON.stringify({ item_type: "sprint_plan", content }),
      });
      setItemId(created.id);
    }
  }

  async function generate() {
    setError("");
    setGenerating(true);
    try {
      const result = await apiFetch("/ai/sprint-plan", {
        method: "POST",
        body: JSON.stringify({ module_id: module.id, locale }),
      });
      const nextSprints = (result.sprints || []).map((s) => ({
        name: s.name,
        theme: s.theme,
        tasks: (s.tasks || []).map((title) => ({ title, done: false })),
      }));
      await persist(nextSprints);
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setGenerating(false);
    }
  }

  function addSprint() {
    persist([...sprints, { name: t("newSprintName", { n: sprints.length + 1 }), theme: "", tasks: [] }]);
  }

  function removeSprint(sprintIndex) {
    persist(sprints.filter((_, i) => i !== sprintIndex));
  }

  function addTask(sprintIndex, title) {
    const trimmed = title.trim();
    if (!trimmed) return;
    const next = sprints.map((s, i) =>
      i === sprintIndex ? { ...s, tasks: [...s.tasks, { title: trimmed, done: false }] } : s,
    );
    persist(next);
  }

  function removeTask(sprintIndex, taskIndex) {
    const next = sprints.map((s, i) =>
      i === sprintIndex ? { ...s, tasks: s.tasks.filter((_, ti) => ti !== taskIndex) } : s,
    );
    persist(next);
  }

  function toggleTask(sprintIndex, taskIndex) {
    const next = sprints.map((s, i) =>
      i === sprintIndex
        ? { ...s, tasks: s.tasks.map((task, ti) => (ti === taskIndex ? { ...task, done: !task.done } : task)) }
        : s,
    );
    persist(next);
  }

  function moveTask(targetSprintIndex) {
    const source = dragRef.current;
    dragRef.current = null;
    if (!source || source.sprintIndex === targetSprintIndex) return;

    const task = sprints[source.sprintIndex].tasks[source.taskIndex];
    const next = sprints.map((s, i) => {
      if (i === source.sprintIndex) return { ...s, tasks: s.tasks.filter((_, ti) => ti !== source.taskIndex) };
      if (i === targetSprintIndex) return { ...s, tasks: [...s.tasks, task] };
      return s;
    });
    persist(next);
  }

  if (loading) {
    return <p className="text-sm text-dp-muted">{tCommon("loading")}</p>;
  }

  const totalTasks = sprints.reduce((sum, s) => sum + s.tasks.length, 0);
  const doneTasks = sprints.reduce((sum, s) => sum + s.tasks.filter((t2) => t2.done).length, 0);

  return (
    <div>
      <p className="text-sm text-dp-muted mt-1.5 mb-4">{t("intro")}</p>

      <div className="flex flex-wrap gap-2 mb-4">
        <AiBtn onClick={generate} disabled={generating}>
          {generating ? t("generating") : sprints.length > 0 ? t("regenerate") : t("generate")}
        </AiBtn>
        <TinyBtn onClick={addSprint}>
          <Plus size={13} /> {t("addSprint")}
        </TinyBtn>
      </div>

      {error && <p className="text-xs text-red-500 mb-3">{error}</p>}

      {sprints.length === 0 ? (
        <p className="text-sm text-dp-muted">{t("empty")}</p>
      ) : (
        <>
          <div className="flex gap-3 overflow-x-auto pb-2 mb-4">
            {sprints.map((sprint, si) => (
              <div
                key={si}
                onDragOver={(e) => e.preventDefault()}
                onDrop={() => moveTask(si)}
                className="bg-dp-faint rounded-2xl border border-dp-border p-3 w-64 flex-shrink-0"
              >
                <div className="flex items-start justify-between gap-1.5 mb-3 px-1">
                  <div>
                    <div className="text-sm font-bold">{sprint.name}</div>
                    {sprint.theme && <div className="text-xs text-dp-accent-strong font-medium">{sprint.theme}</div>}
                  </div>
                  <button
                    type="button"
                    onClick={() => removeSprint(si)}
                    className="text-dp-muted hover:text-red-500 flex-shrink-0"
                  >
                    <Trash2 size={13} />
                  </button>
                </div>

                <div className="flex flex-col gap-1.5 mb-2">
                  {sprint.tasks.map((task, ti) => (
                    <div
                      key={ti}
                      draggable
                      onDragStart={() => {
                        dragRef.current = { sprintIndex: si, taskIndex: ti };
                      }}
                      className="group bg-dp-panel rounded-xl border border-dp-border p-2.5 cursor-grab active:cursor-grabbing"
                    >
                      <div className="flex items-start gap-2">
                        <button
                          type="button"
                          onClick={() => toggleTask(si, ti)}
                          className={`w-4 h-4 rounded-md border flex items-center justify-center flex-shrink-0 mt-0.5 transition-colors ${
                            task.done ? "bg-dp-green border-dp-green text-white" : "border-dp-border"
                          }`}
                        >
                          {task.done && <Check size={11} />}
                        </button>
                        <span className={`text-xs flex-1 ${task.done ? "line-through text-dp-muted" : ""}`}>
                          {task.title}
                        </span>
                        <button
                          type="button"
                          onClick={() => removeTask(si, ti)}
                          className="text-dp-muted hover:text-red-500 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0"
                        >
                          <Trash2 size={12} />
                        </button>
                      </div>
                    </div>
                  ))}
                </div>

                <div className="flex gap-1.5">
                  <input
                    value={drafts[si] || ""}
                    onChange={(e) => setDrafts((d) => ({ ...d, [si]: e.target.value }))}
                    onKeyDown={(e) => {
                      if (e.key === "Enter" && drafts[si]?.trim()) {
                        addTask(si, drafts[si]);
                        setDrafts((d) => ({ ...d, [si]: "" }));
                      }
                    }}
                    placeholder={t("addTaskPlaceholder")}
                    className="flex-1 min-w-0 rounded-lg border border-dp-border bg-dp-panel focus:border-dp-accent px-2.5 py-1.5 text-xs outline-none transition-colors"
                  />
                  <TinyBtn
                    onClick={() => {
                      if (drafts[si]?.trim()) {
                        addTask(si, drafts[si]);
                        setDrafts((d) => ({ ...d, [si]: "" }));
                      }
                    }}
                  >
                    <Plus size={12} />
                  </TinyBtn>
                </div>
              </div>
            ))}
          </div>

          <p className="text-xs text-dp-muted">{t("progress", { done: doneTasks, total: totalTasks })}</p>
        </>
      )}

      <CompleteButton enabled={sprints.length > 0 && totalTasks > 0} isDone={isDone} onClick={onComplete} />
    </div>
  );
}
