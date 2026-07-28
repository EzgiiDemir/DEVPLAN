"use client";

import { useEffect, useState } from "react";
import { useTranslations, useLocale } from "next-intl";
import { Plus, Trash2 } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { Panel } from "@/components/ui/Panel";
import { Chip } from "@/components/ui/Chip";
import { TinyBtn, AiBtn, CompleteButton } from "@/components/ui/Buttons";

const PRIORITIES = ["must", "should", "could", "wont"];
const PRIORITY_DOT = {
  must: "bg-red-500",
  should: "bg-dp-accent",
  could: "bg-dp-blue",
  wont: "bg-dp-muted",
};

export function RequirementsModule({ module, isDone, onComplete }) {
  const t = useTranslations("RequirementsModule");
  const tCommon = useTranslations("Common");
  const locale = useLocale();
  const [loading, setLoading] = useState(true);
  const [requirements, setRequirements] = useState([]);
  const [draft, setDraft] = useState("");
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    apiFetch(`/modules/${module.id}/items`).then((items) => {
      setRequirements(
        items.filter((i) => i.item_type === "requirement").map((i) => ({ itemId: i.id, ...i.content })),
      );
      setLoading(false);
    });
  }, [module.id]);

  async function addFeature(name) {
    const trimmed = name.trim();
    if (!trimmed || requirements.some((r) => r.feature.toLowerCase() === trimmed.toLowerCase())) return;
    const content = { feature: trimmed, story: null, priority: "should" };
    const created = await apiFetch(`/modules/${module.id}/items`, {
      method: "POST",
      body: JSON.stringify({ item_type: "requirement", content }),
    });
    setRequirements((r) => [...r, { itemId: created.id, ...content }]);
  }

  async function removeFeature(itemId) {
    await apiFetch(`/items/${itemId}`, { method: "DELETE" });
    setRequirements((r) => r.filter((x) => x.itemId !== itemId));
  }

  async function setPriority(itemId, priority) {
    const target = requirements.find((r) => r.itemId === itemId);
    const content = { feature: target.feature, story: target.story, priority };
    setRequirements((r) => r.map((x) => (x.itemId === itemId ? { ...x, priority } : x)));
    await apiFetch(`/items/${itemId}`, {
      method: "PUT",
      body: JSON.stringify({ content, is_user_edited: true }),
    });
  }

  async function generateStories() {
    setError("");
    setGenerating(true);
    try {
      const pending = requirements.filter((r) => !r.story);
      const result = await apiFetch("/ai/user-stories", {
        method: "POST",
        body: JSON.stringify({ module_id: module.id, features: pending.map((r) => r.feature), locale }),
      });
      const byFeature = Object.fromEntries(
        (result.stories || []).map((s) => [s.feature.toLowerCase(), s]),
      );

      for (const req of pending) {
        const match = byFeature[req.feature.toLowerCase()];
        if (!match) continue;
        const content = { feature: req.feature, story: match.story, priority: match.priority || req.priority };
        await apiFetch(`/items/${req.itemId}`, {
          method: "PUT",
          body: JSON.stringify({ content, is_ai_generated: true }),
        });
        setRequirements((rs) => rs.map((r) => (r.itemId === req.itemId ? { ...r, ...content } : r)));
      }
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setGenerating(false);
    }
  }

  if (loading) {
    return <p className="text-sm text-dp-muted">{tCommon("loading")}</p>;
  }

  const generatedCount = requirements.filter((r) => r.story).length;

  return (
    <div>
      <p className="text-sm text-dp-muted mt-1.5 mb-4">{t("intro")}</p>

      <div className="flex gap-2 mb-4">
        <input
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter" && draft.trim()) {
              addFeature(draft);
              setDraft("");
            }
          }}
          placeholder={t("addPlaceholder")}
          className="flex-1 rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-2.5 text-sm outline-none transition-colors"
        />
        <TinyBtn
          onClick={() => {
            if (draft.trim()) {
              addFeature(draft);
              setDraft("");
            }
          }}
        >
          <Plus size={13} /> {tCommon("add")}
        </TinyBtn>
      </div>

      <div className="flex flex-col gap-2.5 mb-4">
        {requirements.map((r) => (
          <Panel key={r.itemId}>
            <div className="flex justify-between items-start gap-2 mb-2.5">
              <span className="font-semibold text-sm">{r.feature}</span>
              <button
                type="button"
                onClick={() => removeFeature(r.itemId)}
                className="text-dp-muted hover:text-red-500 flex-shrink-0"
              >
                <Trash2 size={14} />
              </button>
            </div>

            <div className="flex flex-wrap gap-1.5 mb-2.5">
              {PRIORITIES.map((p) => (
                <Chip key={p} active={r.priority === p} onClick={() => setPriority(r.itemId, p)}>
                  <span className={`w-1.5 h-1.5 rounded-full mr-1.5 ${PRIORITY_DOT[p]}`} />
                  {t(`priorities.${p}`)}
                </Chip>
              ))}
            </div>

            {r.story ? (
              <p className="text-sm leading-relaxed italic text-dp-ink m-0">&ldquo;{r.story}&rdquo;</p>
            ) : (
              <p className="text-xs text-dp-muted italic m-0">{t("notGenerated")}</p>
            )}
          </Panel>
        ))}
        {requirements.length === 0 && <p className="text-sm text-dp-muted">{t("empty")}</p>}
      </div>

      {requirements.some((r) => !r.story) && (
        <AiBtn onClick={generateStories} disabled={generating}>
          {generating ? t("generating") : t("generate")}
        </AiBtn>
      )}

      {error && <p className="text-xs text-red-500 mt-2">{error}</p>}

      <CompleteButton enabled={generatedCount >= 2} isDone={isDone} onClick={onComplete} />
    </div>
  );
}
