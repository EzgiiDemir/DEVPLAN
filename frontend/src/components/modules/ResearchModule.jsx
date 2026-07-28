"use client";

import { useEffect, useMemo, useState } from "react";
import { useTranslations, useLocale } from "next-intl";
import { ArrowUp, ArrowDown, ArrowUpDown, Plus, Search, Trash2 } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { Chip } from "@/components/ui/Chip";
import { TinyBtn, AiBtn, CompleteButton } from "@/components/ui/Buttons";

const COLUMNS = [
  { key: "name", align: "left" },
  { key: "price", align: "left" },
  { key: "feature", align: "left" },
  { key: "pro", align: "left" },
  { key: "con", align: "left" },
];

export function ResearchModule({ module, isDone, onComplete }) {
  const t = useTranslations("ResearchModule");
  const tCommon = useTranslations("Common");
  const locale = useLocale();
  const [loading, setLoading] = useState(true);
  const [competitors, setCompetitors] = useState([]);
  const [draft, setDraft] = useState("");
  const [suggestions, setSuggestions] = useState([]);
  const [suggesting, setSuggesting] = useState(false);
  const [analyzing, setAnalyzing] = useState(false);
  const [error, setError] = useState("");
  const [search, setSearch] = useState("");
  const [onlyAnalyzed, setOnlyAnalyzed] = useState(false);
  const [sort, setSort] = useState({ key: null, dir: "asc" });

  useEffect(() => {
    apiFetch(`/modules/${module.id}/items`).then((items) => {
      setCompetitors(
        items.filter((i) => i.item_type === "competitor").map((i) => ({ itemId: i.id, ...i.content })),
      );
      setLoading(false);
    });
  }, [module.id]);

  async function addCompetitor(name) {
    const trimmed = name.trim();
    if (!trimmed || competitors.some((c) => c.name.toLowerCase() === trimmed.toLowerCase())) return;
    const content = { name: trimmed };
    const created = await apiFetch(`/modules/${module.id}/items`, {
      method: "POST",
      body: JSON.stringify({ item_type: "competitor", content }),
    });
    setCompetitors((c) => [...c, { itemId: created.id, ...content }]);
  }

  async function removeCompetitor(itemId) {
    await apiFetch(`/items/${itemId}`, { method: "DELETE" });
    setCompetitors((c) => c.filter((x) => x.itemId !== itemId));
  }

  async function getSuggestions() {
    setError("");
    setSuggesting(true);
    try {
      const result = await apiFetch("/ai/competitor-suggestions", {
        method: "POST",
        body: JSON.stringify({ module_id: module.id, locale }),
      });
      setSuggestions(result.suggestions || []);
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setSuggesting(false);
    }
  }

  async function analyzeAll() {
    setError("");
    setAnalyzing(true);
    try {
      const unanalyzed = competitors.filter((c) => !c.price);
      for (const comp of unanalyzed) {
        const result = await apiFetch("/ai/competitor-analysis", {
          method: "POST",
          body: JSON.stringify({ module_id: module.id, competitor_name: comp.name, locale }),
        });
        const content = { name: comp.name, ...result };
        await apiFetch(`/items/${comp.itemId}`, {
          method: "PUT",
          body: JSON.stringify({ content, is_ai_generated: true }),
        });
        setCompetitors((cs) => cs.map((c) => (c.itemId === comp.itemId ? { ...c, ...result } : c)));
      }
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setAnalyzing(false);
    }
  }

  function toggleSort(key) {
    setSort((s) => (s.key === key ? { key, dir: s.dir === "asc" ? "desc" : "asc" } : { key, dir: "asc" }));
  }

  const visibleCompetitors = useMemo(() => {
    let rows = competitors.filter((c) => c.name.toLowerCase().includes(search.trim().toLowerCase()));
    if (onlyAnalyzed) rows = rows.filter((c) => !!c.price);
    if (sort.key) {
      rows = [...rows].sort((a, b) => {
        const av = (a[sort.key] || "").toString().toLowerCase();
        const bv = (b[sort.key] || "").toString().toLowerCase();
        const cmp = av.localeCompare(bv);
        return sort.dir === "asc" ? cmp : -cmp;
      });
    }
    return rows;
  }, [competitors, search, onlyAnalyzed, sort]);

  if (loading) {
    return <p className="text-sm text-dp-muted">{tCommon("loading")}</p>;
  }

  const analyzedCount = competitors.filter((c) => c.price).length;
  const visibleSuggestions = suggestions.filter(
    (s) => !competitors.some((c) => c.name.toLowerCase() === s.toLowerCase()),
  );

  return (
    <div>
      <p className="text-sm text-dp-muted mt-1.5 mb-4">{t("intro")}</p>

      <div className="flex gap-2 mb-3">
        <input
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter" && draft.trim()) {
              addCompetitor(draft);
              setDraft("");
            }
          }}
          placeholder={t("addPlaceholder")}
          className="flex-1 rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-2.5 text-sm outline-none transition-colors"
        />
        <TinyBtn
          onClick={() => {
            if (draft.trim()) {
              addCompetitor(draft);
              setDraft("");
            }
          }}
        >
          <Plus size={13} /> {tCommon("add")}
        </TinyBtn>
      </div>

      <AiBtn onClick={getSuggestions} disabled={suggesting}>
        {suggesting ? t("suggesting") : t("getSuggestions")}
      </AiBtn>

      {visibleSuggestions.length > 0 && (
        <div className="mt-3">
          {visibleSuggestions.map((s) => (
            <Chip key={s} onClick={() => addCompetitor(s)}>
              + {s}
            </Chip>
          ))}
        </div>
      )}

      {competitors.length === 0 ? (
        <p className="text-sm text-dp-muted mt-5">{t("empty")}</p>
      ) : (
        <>
          <div className="flex flex-wrap items-center gap-2 mt-6 mb-3">
            <div className="relative flex-1 min-w-[180px]">
              <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-dp-muted" />
              <input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder={t("filterPlaceholder")}
                className="w-full rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent pl-8 pr-3 py-2 text-sm outline-none transition-colors"
              />
            </div>
            <Chip active={onlyAnalyzed} onClick={() => setOnlyAnalyzed((v) => !v)}>
              {t("onlyAnalyzed")}
            </Chip>
          </div>

          <div className="overflow-x-auto rounded-2xl border border-dp-border">
            <table className="w-full min-w-[720px] text-sm border-collapse">
              <thead>
                <tr className="bg-dp-faint">
                  {COLUMNS.map((col) => (
                    <th
                      key={col.key}
                      onClick={() => toggleSort(col.key)}
                      className="text-left px-4 py-2.5 text-[11px] font-semibold text-dp-muted uppercase tracking-wider cursor-pointer select-none hover:text-dp-ink transition-colors whitespace-nowrap"
                    >
                      <span className="inline-flex items-center gap-1">
                        {t(`fields.${col.key}`)}
                        {sort.key === col.key ? (
                          sort.dir === "asc" ? (
                            <ArrowUp size={11} />
                          ) : (
                            <ArrowDown size={11} />
                          )
                        ) : (
                          <ArrowUpDown size={11} className="opacity-30" />
                        )}
                      </span>
                    </th>
                  ))}
                  <th className="w-10" />
                </tr>
              </thead>
              <tbody>
                {visibleCompetitors.map((c) => (
                  <tr key={c.itemId} className="border-t border-dp-border">
                    <td className="px-4 py-3 font-semibold whitespace-nowrap">{c.name}</td>
                    {c.price ? (
                      <>
                        <td className="px-4 py-3 text-dp-muted-2">{c.price}</td>
                        <td className="px-4 py-3 text-dp-muted-2">{c.feature}</td>
                        <td className="px-4 py-3 text-dp-green">{c.pro}</td>
                        <td className="px-4 py-3 text-red-500">{c.con}</td>
                      </>
                    ) : (
                      <td colSpan={4} className="px-4 py-3 text-dp-muted italic">
                        {t("notAnalyzed")}
                      </td>
                    )}
                    <td className="px-2 py-3 text-right">
                      <button
                        type="button"
                        onClick={() => removeCompetitor(c.itemId)}
                        className="text-dp-muted hover:text-red-500"
                      >
                        <Trash2 size={14} />
                      </button>
                    </td>
                  </tr>
                ))}
                {visibleCompetitors.length === 0 && (
                  <tr>
                    <td colSpan={6} className="px-4 py-6 text-center text-sm text-dp-muted">
                      {t("noMatches")}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </>
      )}

      {competitors.some((c) => !c.price) && (
        <div className="mt-4">
          <AiBtn onClick={analyzeAll} disabled={analyzing}>
            {analyzing ? t("analyzing") : t("analyzeAll")}
          </AiBtn>
        </div>
      )}

      {error && <p className="text-xs text-red-500 mt-2">{error}</p>}

      <CompleteButton enabled={analyzedCount >= 2} isDone={isDone} onClick={onComplete} />
    </div>
  );
}
