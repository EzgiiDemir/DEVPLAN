"use client";

import { useEffect, useState } from "react";
import { useTranslations, useLocale } from "next-intl";
import { Frame, Plus, Trash2, ChevronDown, ChevronRight } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { Panel } from "@/components/ui/Panel";
import { AiBtn, CompleteButton, TinyBtn } from "@/components/ui/Buttons";
import { MermaidDiagram } from "@/components/ui/MermaidDiagram";

const METHOD_STYLE = {
  GET: "bg-dp-blue-bg text-dp-blue",
  POST: "bg-dp-green-bg text-dp-green",
  PUT: "bg-dp-accent-tint text-dp-accent-strong",
  PATCH: "bg-dp-accent-tint text-dp-accent-strong",
  DELETE: "bg-red-50 text-red-500",
};

export function DesignModule({ module, isDone, onComplete }) {
  const t = useTranslations("DesignModule");
  const tCommon = useTranslations("Common");
  const locale = useLocale();
  const [loading, setLoading] = useState(true);
  const [pagesItemId, setPagesItemId] = useState(null);
  const [pages, setPages] = useState([]);
  const [systemItemId, setSystemItemId] = useState(null);
  const [system, setSystem] = useState(null);

  const [figmaOpen, setFigmaOpen] = useState(false);
  const [figmaUrl, setFigmaUrl] = useState("");
  const [figmaToken, setFigmaToken] = useState("");
  const [importing, setImporting] = useState(false);
  const [importError, setImportError] = useState("");

  const [draft, setDraft] = useState("");
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    apiFetch(`/modules/${module.id}/items`).then((items) => {
      const pagesItem = items.find((i) => i.item_type === "design_pages");
      const systemItem = items.find((i) => i.item_type === "design_system");
      if (pagesItem) {
        setPagesItemId(pagesItem.id);
        setPages(pagesItem.content.pages || []);
      }
      if (systemItem) {
        setSystemItemId(systemItem.id);
        setSystem(systemItem.content);
      }
      setLoading(false);
    });
  }, [module.id]);

  async function persistPages(nextPages, fileName = null) {
    setPages(nextPages);
    const content = { fileName, pages: nextPages };
    if (pagesItemId) {
      await apiFetch(`/items/${pagesItemId}`, {
        method: "PUT",
        body: JSON.stringify({ content, is_user_edited: true }),
      });
    } else {
      const created = await apiFetch(`/modules/${module.id}/items`, {
        method: "POST",
        body: JSON.stringify({ item_type: "design_pages", content }),
      });
      setPagesItemId(created.id);
    }
  }

  async function persistSystem(content) {
    setSystem(content);
    if (systemItemId) {
      await apiFetch(`/items/${systemItemId}`, {
        method: "PUT",
        body: JSON.stringify({ content, is_ai_generated: true }),
      });
    } else {
      const created = await apiFetch(`/modules/${module.id}/items`, {
        method: "POST",
        body: JSON.stringify({ item_type: "design_system", content, is_ai_generated: true }),
      });
      setSystemItemId(created.id);
    }
  }

  async function importFromFigma() {
    setImportError("");
    setImporting(true);
    try {
      const result = await apiFetch("/figma/import", {
        method: "POST",
        body: JSON.stringify({ file_url: figmaUrl, figma_token: figmaToken }),
      });
      await persistPages(result.pages, result.file_name);
      setFigmaToken("");
      setFigmaOpen(false);
    } catch (err) {
      setImportError(err.message || t("importError"));
    } finally {
      setImporting(false);
    }
  }

  async function addManualPage(name) {
    const trimmed = name.trim();
    if (!trimmed) return;
    const manualGroupIndex = pages.findIndex((p) => p.name === t("manualGroup"));
    const next = [...pages];
    if (manualGroupIndex === -1) {
      next.push({ name: t("manualGroup"), frames: [trimmed] });
    } else {
      next[manualGroupIndex] = {
        ...next[manualGroupIndex],
        frames: [...next[manualGroupIndex].frames, trimmed],
      };
    }
    await persistPages(next);
  }

  async function removeFrame(pageIndex, frameIndex) {
    const next = pages
      .map((p, i) =>
        i === pageIndex ? { ...p, frames: p.frames.filter((_, fi) => fi !== frameIndex) } : p,
      )
      .filter((p) => p.frames.length > 0);
    await persistPages(next);
  }

  async function generateSystem() {
    setError("");
    setGenerating(true);
    try {
      const result = await apiFetch("/ai/design-system", {
        method: "POST",
        body: JSON.stringify({ module_id: module.id, pages, locale }),
      });
      await persistSystem(result);
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setGenerating(false);
    }
  }

  if (loading) {
    return <p className="text-sm text-dp-muted">{tCommon("loading")}</p>;
  }

  const totalFrames = pages.reduce((sum, p) => sum + p.frames.length, 0);

  return (
    <div>
      <p className="text-sm text-dp-muted mt-1.5 mb-4">{t("intro")}</p>

      <div className="mb-3">
        <button
          type="button"
          onClick={() => setFigmaOpen((v) => !v)}
          className="inline-flex items-center gap-1.5 text-sm font-semibold text-dp-accent-strong"
        >
          <Frame size={15} />
          {t("figmaToggle")}
          {figmaOpen ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
        </button>

        {figmaOpen && (
          <div className="mt-3 bg-dp-panel rounded-2xl border border-dp-border p-4 flex flex-col gap-2.5">
            <p className="text-xs text-dp-muted">{t("figmaHelp")}</p>
            <input
              value={figmaUrl}
              onChange={(e) => setFigmaUrl(e.target.value)}
              placeholder={t("figmaUrlPlaceholder")}
              className="rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-2.5 text-sm outline-none transition-colors"
            />
            <input
              type="password"
              value={figmaToken}
              onChange={(e) => setFigmaToken(e.target.value)}
              placeholder={t("figmaTokenPlaceholder")}
              className="rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-2.5 text-sm outline-none transition-colors"
            />
            {importError && <p className="text-xs text-red-500">{importError}</p>}
            <TinyBtn onClick={importFromFigma} disabled={importing || !figmaUrl || !figmaToken}>
              {importing ? t("importing") : t("import")}
            </TinyBtn>
          </div>
        )}
      </div>

      <div className="flex gap-2 mb-3">
        <input
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter" && draft.trim()) {
              addManualPage(draft);
              setDraft("");
            }
          }}
          placeholder={t("addPagePlaceholder")}
          className="flex-1 rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-2.5 text-sm outline-none transition-colors"
        />
        <TinyBtn
          onClick={() => {
            if (draft.trim()) {
              addManualPage(draft);
              setDraft("");
            }
          }}
        >
          <Plus size={13} /> {tCommon("add")}
        </TinyBtn>
      </div>

      {pages.length === 0 ? (
        <p className="text-sm text-dp-muted mb-4">{t("empty")}</p>
      ) : (
        <div className="flex flex-col gap-3 mb-4">
          {pages.map((page, pi) => (
            <Panel key={page.name} label={page.name}>
              <div className="flex flex-wrap gap-1.5">
                {page.frames.map((frame, fi) => (
                  <span
                    key={frame + fi}
                    className="group inline-flex items-center gap-1.5 text-xs font-medium bg-dp-faint rounded-full px-3 py-1.5"
                  >
                    {frame}
                    <button
                      type="button"
                      onClick={() => removeFrame(pi, fi)}
                      className="text-dp-muted hover:text-red-500"
                    >
                      <Trash2 size={11} />
                    </button>
                  </span>
                ))}
              </div>
            </Panel>
          ))}
        </div>
      )}

      <AiBtn onClick={generateSystem} disabled={totalFrames === 0 || generating}>
        {generating ? t("generating") : t("generate")}
      </AiBtn>

      {error && <p className="text-xs text-red-500 mt-2">{error}</p>}

      {system && (
        <div className="flex flex-col gap-4 mt-5">
          <Panel label={t("erHeading")}>
            <MermaidDiagram code={system.database_er} />
          </Panel>

          <Panel label={t("apiHeading")}>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[480px] text-sm border-collapse">
                <tbody>
                  {(system.api_flow || []).map((endpoint, i) => (
                    <tr key={i} className="border-t border-dp-border first:border-t-0">
                      <td className="py-2 pr-3 align-top">
                        <span
                          className={`inline-block text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded-md ${
                            METHOD_STYLE[endpoint.method] || "bg-dp-faint text-dp-muted"
                          }`}
                        >
                          {endpoint.method}
                        </span>
                      </td>
                      <td className="py-2 pr-3 align-top font-mono text-xs whitespace-nowrap">{endpoint.path}</td>
                      <td className="py-2 align-top text-dp-muted-2">{endpoint.description}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>

          <Panel label={t("architectureHeading")}>
            <MermaidDiagram code={system.architecture} />
          </Panel>
        </div>
      )}

      <CompleteButton enabled={!!system} isDone={isDone} onClick={onComplete} />
    </div>
  );
}
