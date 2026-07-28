"use client";

import { useEffect, useRef, useState } from "react";
import { useTranslations, useLocale } from "next-intl";
import { Plus, Sparkles, Trash2 } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { useProject } from "@/lib/project-context";
import { TinyBtn, CompleteButton } from "@/components/ui/Buttons";

const COLUMNS = ["must", "should", "could", "wont"];
const COLUMN_DOT = {
  must: "bg-red-500",
  should: "bg-dp-accent",
  could: "bg-dp-blue",
  wont: "bg-dp-muted",
};

export function MvpScopeModule({ module, isDone, onComplete }) {
  const t = useTranslations("MvpScopeModule");
  const tCommon = useTranslations("Common");
  const locale = useLocale();
  const { project } = useProject();
  const [loading, setLoading] = useState(true);
  const [items, setItems] = useState([]);
  const [draft, setDraft] = useState("");
  const [recommending, setRecommending] = useState(false);
  const [error, setError] = useState("");
  const dragItemId = useRef(null);
  const seeded = useRef(false);
  const projectRef = useRef(project);
  useEffect(() => {
    projectRef.current = project;
  }, [project]);

  useEffect(() => {
    apiFetch(`/modules/${module.id}/items`).then(async (existing) => {
      const mvpItems = existing.filter((i) => i.item_type === "mvp_item");

      if (mvpItems.length > 0 || seeded.current) {
        setItems(mvpItems.map((i) => ({ itemId: i.id, ...i.content })));
        setLoading(false);
        return;
      }

      seeded.current = true;
      const requirementsModule = projectRef.current?.modules?.find((m) => m.module_type === "requirements");
      if (!requirementsModule) {
        setLoading(false);
        return;
      }

      const requirementItems = await apiFetch(`/modules/${requirementsModule.id}/items`);
      const seedList = requirementItems.filter((i) => i.item_type === "requirement" && i.content.story);

      const created = [];
      for (const req of seedList) {
        const content = {
          feature: req.content.feature,
          story: req.content.story,
          column: COLUMNS.includes(req.content.priority) ? req.content.priority : "should",
          aiReason: null,
        };
        const item = await apiFetch(`/modules/${module.id}/items`, {
          method: "POST",
          body: JSON.stringify({ item_type: "mvp_item", content }),
        });
        created.push({ itemId: item.id, ...content });
      }
      setItems(created);
      setLoading(false);
    });
  }, [module.id]);

  async function addCard(name) {
    const trimmed = name.trim();
    if (!trimmed || items.some((i) => i.feature.toLowerCase() === trimmed.toLowerCase())) return;
    const content = { feature: trimmed, story: null, column: "should", aiReason: null };
    const created = await apiFetch(`/modules/${module.id}/items`, {
      method: "POST",
      body: JSON.stringify({ item_type: "mvp_item", content }),
    });
    setItems((its) => [...its, { itemId: created.id, ...content }]);
  }

  async function removeCard(itemId) {
    await apiFetch(`/items/${itemId}`, { method: "DELETE" });
    setItems((its) => its.filter((i) => i.itemId !== itemId));
  }

  async function moveCard(itemId, column) {
    const target = items.find((i) => i.itemId === itemId);
    if (!target || target.column === column) return;
    const content = { feature: target.feature, story: target.story, column, aiReason: null };
    setItems((its) => its.map((i) => (i.itemId === itemId ? { ...i, column, aiReason: null } : i)));
    await apiFetch(`/items/${itemId}`, {
      method: "PUT",
      body: JSON.stringify({ content, is_user_edited: true }),
    });
  }

  async function getRecommendation() {
    setError("");
    setRecommending(true);
    try {
      const result = await apiFetch("/ai/mvp-recommendation", {
        method: "POST",
        body: JSON.stringify({ module_id: module.id, features: items.map((i) => i.feature), locale }),
      });
      const byFeature = Object.fromEntries(
        (result.recommendations || []).map((r) => [r.feature.toLowerCase(), r]),
      );

      for (const item of items) {
        const match = byFeature[item.feature.toLowerCase()];
        if (!match || !COLUMNS.includes(match.column)) continue;
        const content = { feature: item.feature, story: item.story, column: match.column, aiReason: match.reason };
        await apiFetch(`/items/${item.itemId}`, {
          method: "PUT",
          body: JSON.stringify({ content, is_ai_generated: true }),
        });
        setItems((its) =>
          its.map((i) => (i.itemId === item.itemId ? { ...i, column: match.column, aiReason: match.reason } : i)),
        );
      }
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setRecommending(false);
    }
  }

  if (loading) {
    return <p className="text-sm text-dp-muted">{tCommon("loading")}</p>;
  }

  const mustCount = items.filter((i) => i.column === "must").length;

  return (
    <div>
      <p className="text-sm text-dp-muted mt-1.5 mb-4">{t("intro")}</p>

      <div className="flex flex-wrap items-center gap-2 mb-4">
        <input
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter" && draft.trim()) {
              addCard(draft);
              setDraft("");
            }
          }}
          placeholder={t("addPlaceholder")}
          className="flex-1 min-w-[160px] rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-2.5 text-sm outline-none transition-colors"
        />
        <TinyBtn
          onClick={() => {
            if (draft.trim()) {
              addCard(draft);
              setDraft("");
            }
          }}
        >
          <Plus size={13} /> {tCommon("add")}
        </TinyBtn>
        <TinyBtn onClick={getRecommendation} disabled={recommending || items.length === 0}>
          <Sparkles size={13} /> {recommending ? t("recommending") : t("recommend")}
        </TinyBtn>
      </div>

      {items.length === 0 ? (
        <p className="text-sm text-dp-muted">{t("empty")}</p>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          {COLUMNS.map((col) => {
            const colItems = items.filter((i) => i.column === col);
            return (
              <div
                key={col}
                onDragOver={(e) => e.preventDefault()}
                onDrop={(e) => {
                  e.preventDefault();
                  if (dragItemId.current) moveCard(dragItemId.current, col);
                  dragItemId.current = null;
                }}
                className="bg-dp-faint rounded-2xl border border-dp-border p-3 min-h-[140px]"
              >
                <div className="flex items-center gap-1.5 mb-3 px-1">
                  <span className={`w-2 h-2 rounded-full ${COLUMN_DOT[col]}`} />
                  <span className="text-[11px] font-semibold uppercase tracking-wider text-dp-muted">
                    {t(`columns.${col}`)}
                  </span>
                  <span className="text-[11px] font-bold text-dp-border ml-auto">{colItems.length}</span>
                </div>

                <div className="flex flex-col gap-2">
                  {colItems.map((item) => (
                    <div
                      key={item.itemId}
                      draggable
                      onDragStart={() => {
                        dragItemId.current = item.itemId;
                      }}
                      onDragEnd={() => {
                        dragItemId.current = null;
                      }}
                      className={`group bg-dp-panel rounded-xl border border-dp-border p-3 cursor-grab active:cursor-grabbing transition-opacity ${
                        col === "wont" ? "opacity-60" : ""
                      }`}
                    >
                      <div className="flex justify-between items-start gap-1.5 mb-1">
                        <span className="text-sm font-semibold">{item.feature}</span>
                        <button
                          type="button"
                          onClick={() => removeCard(item.itemId)}
                          className="text-dp-muted hover:text-red-500 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity"
                        >
                          <Trash2 size={13} />
                        </button>
                      </div>
                      {item.aiReason && (
                        <p className="text-[11px] text-dp-accent-strong leading-snug m-0">{item.aiReason}</p>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            );
          })}
        </div>
      )}

      {error && <p className="text-xs text-red-500 mt-2">{error}</p>}

      <CompleteButton enabled={mustCount >= 1} isDone={isDone} onClick={onComplete} />
    </div>
  );
}
