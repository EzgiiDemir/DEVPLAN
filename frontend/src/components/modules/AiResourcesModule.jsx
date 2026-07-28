"use client";

import { useEffect, useRef, useState } from "react";
import { useTranslations, useLocale } from "next-intl";
import { Check } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { useProject } from "@/lib/project-context";
import { Chip } from "@/components/ui/Chip";
import { AiBtn, CompleteButton } from "@/components/ui/Buttons";

const PROJECT_TYPES = ["Web App", "Mobile App", "SaaS", "E-commerce", "API/Backend", "Browser Extension"];

export function AiResourcesModule({ module, isDone, onComplete }) {
  const t = useTranslations("AiResourcesModule");
  const tCommon = useTranslations("Common");
  const locale = useLocale();
  const { project } = useProject();
  const [loading, setLoading] = useState(true);
  const [itemId, setItemId] = useState(null);
  const [projectType, setProjectType] = useState("Web App");
  const [customType, setCustomType] = useState("");
  const [tools, setTools] = useState([]);
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState("");
  const projectRef = useRef(project);
  useEffect(() => {
    projectRef.current = project;
  }, [project]);

  useEffect(() => {
    apiFetch(`/modules/${module.id}/items`).then((items) => {
      const item = items.find((i) => i.item_type === "ai_tools");
      if (item) {
        setItemId(item.id);
        setProjectType(item.content.projectType || "Web App");
        setTools(item.content.tools || []);
      }
      setLoading(false);
    });
  }, [module.id]);

  async function persist(content) {
    if (itemId) {
      await apiFetch(`/items/${itemId}`, {
        method: "PUT",
        body: JSON.stringify({ content, is_user_edited: true }),
      });
    } else {
      const created = await apiFetch(`/modules/${module.id}/items`, {
        method: "POST",
        body: JSON.stringify({ item_type: "ai_tools", content }),
      });
      setItemId(created.id);
    }
  }

  async function gatherStack() {
    const modules = projectRef.current?.modules || [];
    const stackModule = modules.find((m) => m.module_type === "tech_stack");
    if (!stackModule) return {};

    const items = await apiFetch(`/modules/${stackModule.id}/items`);
    const stackItem = items.find((i) => i.item_type === "tech_stack");
    if (!stackItem) return {};

    return {
      frontend: stackItem.content.frontend?.selected,
      backend: stackItem.content.backend?.selected,
      database: stackItem.content.database?.selected,
    };
  }

  async function generate() {
    const type = customType.trim() || projectType;
    setError("");
    setGenerating(true);
    try {
      const stack = await gatherStack();
      const result = await apiFetch("/ai/tool-recommendations", {
        method: "POST",
        body: JSON.stringify({ module_id: module.id, project_type: type, ...stack, locale }),
      });
      const nextTools = (result.tools || []).map((tool) => ({ ...tool, selected: false }));
      setProjectType(type);
      setTools(nextTools);
      await persist({ projectType: type, tools: nextTools });
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setGenerating(false);
    }
  }

  function toggleSelected(name) {
    const next = tools.map((tool) => (tool.name === name ? { ...tool, selected: !tool.selected } : tool));
    persist({ projectType, tools: next });
  }

  if (loading) {
    return <p className="text-sm text-dp-muted">{tCommon("loading")}</p>;
  }

  const selectedCount = tools.filter((tool) => tool.selected).length;

  return (
    <div>
      <p className="text-sm text-dp-muted mt-1.5 mb-4">{t("intro")}</p>

      <div className="mb-3">
        <div className="text-xs font-semibold text-dp-muted mb-1.5">{t("projectTypeLabel")}</div>
        <div className="flex flex-wrap gap-1.5 mb-2">
          {PROJECT_TYPES.map((type) => (
            <Chip
              key={type}
              active={!customType && projectType === type}
              onClick={() => {
                setProjectType(type);
                setCustomType("");
              }}
            >
              {type}
            </Chip>
          ))}
        </div>
        <input
          value={customType}
          onChange={(e) => setCustomType(e.target.value)}
          placeholder={t("customTypePlaceholder")}
          className="w-full max-w-xs rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-2 text-sm outline-none transition-colors"
        />
      </div>

      <AiBtn onClick={generate} disabled={generating}>
        {generating ? t("generating") : tools.length > 0 ? t("regenerate") : t("generate")}
      </AiBtn>

      {error && <p className="text-xs text-red-500 mt-2">{error}</p>}

      {tools.length === 0 ? (
        <p className="text-sm text-dp-muted mt-4">{t("empty")}</p>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4">
          {tools.map((tool) => (
            <button
              key={tool.name}
              type="button"
              onClick={() => toggleSelected(tool.name)}
              className={`text-left rounded-2xl border p-4 transition-colors ${
                tool.selected ? "border-dp-solid bg-dp-faint" : "border-dp-border bg-dp-panel hover:border-dp-accent/40"
              }`}
            >
              <div className="flex items-start justify-between gap-2 mb-1.5">
                <span className="text-sm font-semibold">{tool.name}</span>
                {tool.selected && <Check size={15} className="text-dp-accent-strong flex-shrink-0" />}
              </div>
              <span className="inline-block text-[10px] font-bold uppercase tracking-wider text-dp-accent-strong bg-dp-accent-tint rounded-md px-2 py-0.5 mb-2">
                {tool.category}
              </span>
              <p className="text-xs text-dp-muted leading-relaxed m-0">{tool.reason}</p>
            </button>
          ))}
        </div>
      )}

      {tools.length > 0 && (
        <p className="text-xs text-dp-muted mt-3">{t("selectedCount", { count: selectedCount })}</p>
      )}

      <CompleteButton enabled={selectedCount >= 1} isDone={isDone} onClick={onComplete} />
    </div>
  );
}
