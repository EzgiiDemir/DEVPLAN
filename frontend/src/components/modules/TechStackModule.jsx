"use client";

import { useEffect, useState } from "react";
import { useTranslations, useLocale } from "next-intl";
import { LayoutTemplate, Server, Database, Cloud, Check, Plus } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { Chip } from "@/components/ui/Chip";
import { AiBtn, TinyBtn, CompleteButton } from "@/components/ui/Buttons";

const CATEGORIES = {
  frontend: ["Next.js", "React (Vite)", "Vue.js", "SvelteKit", "Angular"],
  backend: ["Laravel", "Node.js (Express)", "Django", "Ruby on Rails", "Spring Boot"],
  database: ["PostgreSQL", "MySQL", "MongoDB", "SQLite"],
  hosting: ["Vercel + Railway", "AWS", "DigitalOcean", "Render"],
};

const CATEGORY_ICONS = {
  frontend: LayoutTemplate,
  backend: Server,
  database: Database,
  hosting: Cloud,
};

const EMPTY_ENTRY = { selected: null, aiRecommended: null, aiReasoning: null, alternatives: [] };
const EMPTY_STACK = Object.fromEntries(Object.keys(CATEGORIES).map((k) => [k, { ...EMPTY_ENTRY }]));

export function TechStackModule({ module, isDone, onComplete }) {
  const t = useTranslations("TechStackModule");
  const tCommon = useTranslations("Common");
  const locale = useLocale();
  const [loading, setLoading] = useState(true);
  const [itemId, setItemId] = useState(null);
  const [stack, setStack] = useState(EMPTY_STACK);
  const [customDraft, setCustomDraft] = useState({});
  const [recommending, setRecommending] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    apiFetch(`/modules/${module.id}/items`).then((items) => {
      const item = items.find((i) => i.item_type === "tech_stack");
      if (item) {
        setItemId(item.id);
        setStack({ ...EMPTY_STACK, ...item.content });
      }
      setLoading(false);
    });
  }, [module.id]);

  async function persist(nextStack) {
    setStack(nextStack);
    if (itemId) {
      await apiFetch(`/items/${itemId}`, {
        method: "PUT",
        body: JSON.stringify({ content: nextStack, is_user_edited: true }),
      });
    } else {
      const created = await apiFetch(`/modules/${module.id}/items`, {
        method: "POST",
        body: JSON.stringify({ item_type: "tech_stack", content: nextStack }),
      });
      setItemId(created.id);
    }
  }

  function selectOption(category, value) {
    persist({ ...stack, [category]: { ...stack[category], selected: value } });
  }

  function acceptRecommendation(category) {
    selectOption(category, stack[category].aiRecommended);
  }

  async function getRecommendation() {
    setError("");
    setRecommending(true);
    try {
      const result = await apiFetch("/ai/tech-stack-recommendation", {
        method: "POST",
        body: JSON.stringify({ module_id: module.id, categories: CATEGORIES, locale }),
      });
      const next = { ...stack };
      for (const [category, rec] of Object.entries(result.categories || {})) {
        if (!next[category]) continue;
        next[category] = {
          ...next[category],
          aiRecommended: rec.recommended,
          aiReasoning: rec.reasoning,
          alternatives: rec.alternatives || [],
        };
      }
      await persist(next);
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setRecommending(false);
    }
  }

  if (loading) {
    return <p className="text-sm text-dp-muted">{tCommon("loading")}</p>;
  }

  const allSelected = Object.values(stack).every((entry) => !!entry.selected);

  return (
    <div>
      <p className="text-sm text-dp-muted mt-1.5 mb-4">{t("intro")}</p>

      <AiBtn onClick={getRecommendation} disabled={recommending}>
        {recommending ? t("recommending") : t("recommend")}
      </AiBtn>

      {error && <p className="text-xs text-red-500 mt-2">{error}</p>}

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4">
        {Object.entries(CATEGORIES).map(([category, options]) => {
          const entry = stack[category];
          const Icon = CATEGORY_ICONS[category];
          return (
            <div key={category} className="bg-dp-panel rounded-2xl border border-dp-border p-4">
              <div className="flex items-center gap-1.5 mb-3">
                <Icon size={14} className="text-dp-accent-strong" />
                <span className="text-[11px] font-semibold text-dp-muted uppercase tracking-wider">
                  {t(`categories.${category}`)}
                </span>
              </div>

              <div className="flex flex-wrap gap-1.5 mb-3">
                {options.map((option) => (
                  <Chip key={option} active={entry.selected === option} onClick={() => selectOption(category, option)}>
                    {option}
                  </Chip>
                ))}
                {entry.selected && !options.includes(entry.selected) && (
                  <Chip active>{entry.selected}</Chip>
                )}
              </div>

              <div className="flex gap-1.5 mb-3">
                <input
                  value={customDraft[category] || ""}
                  onChange={(e) => setCustomDraft((d) => ({ ...d, [category]: e.target.value }))}
                  onKeyDown={(e) => {
                    if (e.key === "Enter" && customDraft[category]?.trim()) {
                      selectOption(category, customDraft[category].trim());
                      setCustomDraft((d) => ({ ...d, [category]: "" }));
                    }
                  }}
                  placeholder={t("customPlaceholder")}
                  className="flex-1 rounded-lg border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-3 py-1.5 text-xs outline-none transition-colors"
                />
                <TinyBtn
                  onClick={() => {
                    if (customDraft[category]?.trim()) {
                      selectOption(category, customDraft[category].trim());
                      setCustomDraft((d) => ({ ...d, [category]: "" }));
                    }
                  }}
                >
                  <Plus size={12} />
                </TinyBtn>
              </div>

              {entry.aiReasoning && (
                <div className="bg-dp-accent-tint rounded-xl p-3 mb-2">
                  <p className="text-xs text-dp-accent-strong leading-relaxed m-0 mb-1.5">
                    <span className="font-semibold">{t("aiPick", { name: entry.aiRecommended })}</span>{" "}
                    {entry.aiReasoning}
                  </p>
                  {entry.selected !== entry.aiRecommended && (
                    <button
                      type="button"
                      onClick={() => acceptRecommendation(category)}
                      className="inline-flex items-center gap-1 text-xs font-semibold text-dp-accent-strong"
                    >
                      <Check size={12} /> {t("useThis")}
                    </button>
                  )}
                </div>
              )}

              {entry.alternatives?.length > 0 && (
                <div className="flex flex-col gap-1">
                  {entry.alternatives.map((alt) => (
                    <p key={alt.name} className="text-[11px] text-dp-muted leading-snug m-0">
                      <span className="font-semibold">{alt.name}:</span> {alt.note}
                    </p>
                  ))}
                </div>
              )}
            </div>
          );
        })}
      </div>

      <CompleteButton enabled={allSelected} isDone={isDone} onClick={onComplete} />
    </div>
  );
}
