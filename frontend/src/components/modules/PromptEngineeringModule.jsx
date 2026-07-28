"use client";

import { useEffect, useRef, useState } from "react";
import { useTranslations, useLocale } from "next-intl";
import { Download, Plus, Trash2 } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { useProject } from "@/lib/project-context";
import { Chip } from "@/components/ui/Chip";
import { AiBtn, TinyBtn, CompleteButton } from "@/components/ui/Buttons";
import { FilePanel } from "@/components/ui/FilePanel";
import { exportTextFilesZip } from "@/lib/exportTextFilesZip";

const DEFAULT_STACK = { frontend: "Next.js", backend: "Laravel", database: "PostgreSQL" };
const SUGGESTED_RULES = [
  "Use TypeScript",
  "Use Clean Architecture",
  "Write tests",
  "Use PostgreSQL",
];

const FILES = [
  { key: "claudeMd", name: "CLAUDE.md" },
  { key: "cursorRules", name: ".cursorrules" },
  { key: "aiInstructions", name: "ai-instructions.txt" },
];

function extractEntities(erDiagram) {
  if (!erDiagram) return [];
  const matches = [...erDiagram.matchAll(/(\w+)\s*\{/g)];
  return [...new Set(matches.map((m) => m[1]))];
}

export function PromptEngineeringModule({ module, isDone, onComplete }) {
  const t = useTranslations("PromptEngineeringModule");
  const tCommon = useTranslations("Common");
  const locale = useLocale();
  const { project } = useProject();
  const [loading, setLoading] = useState(true);
  const [itemId, setItemId] = useState(null);
  const [rules, setRules] = useState([]);
  const [draft, setDraft] = useState("");
  const [files, setFiles] = useState(null);
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState("");
  const projectRef = useRef(project);
  useEffect(() => {
    projectRef.current = project;
  }, [project]);

  useEffect(() => {
    apiFetch(`/modules/${module.id}/items`).then((items) => {
      const item = items.find((i) => i.item_type === "prompt_files");
      if (item) {
        setItemId(item.id);
        setRules(item.content.rules || []);
        setFiles(item.content.files || null);
      }
      setLoading(false);
    });
  }, [module.id]);

  function addRule(name) {
    const trimmed = name.trim();
    if (!trimmed || rules.includes(trimmed)) return;
    setRules((r) => [...r, trimmed]);
  }

  function removeRule(rule) {
    setRules((r) => r.filter((x) => x !== rule));
  }

  async function gatherContext() {
    const modules = projectRef.current?.modules || [];
    let stack = DEFAULT_STACK;
    let entities = [];

    const stackModule = modules.find((m) => m.module_type === "tech_stack");
    if (stackModule) {
      const items = await apiFetch(`/modules/${stackModule.id}/items`);
      const stackItem = items.find((i) => i.item_type === "tech_stack");
      if (stackItem) {
        stack = {
          frontend: stackItem.content.frontend?.selected || DEFAULT_STACK.frontend,
          backend: stackItem.content.backend?.selected || DEFAULT_STACK.backend,
          database: stackItem.content.database?.selected || DEFAULT_STACK.database,
        };
      }
    }

    const designModule = modules.find((m) => m.module_type === "design");
    if (designModule) {
      const items = await apiFetch(`/modules/${designModule.id}/items`);
      const systemItem = items.find((i) => i.item_type === "design_system");
      if (systemItem) entities = extractEntities(systemItem.content.database_er);
    }

    return { stack, entities };
  }

  async function persist(content) {
    if (itemId) {
      await apiFetch(`/items/${itemId}`, {
        method: "PUT",
        body: JSON.stringify({ content, is_ai_generated: true }),
      });
    } else {
      const created = await apiFetch(`/modules/${module.id}/items`, {
        method: "POST",
        body: JSON.stringify({ item_type: "prompt_files", content, is_ai_generated: true }),
      });
      setItemId(created.id);
    }
  }

  async function generate() {
    if (rules.length === 0) return;
    setError("");
    setGenerating(true);
    try {
      const { stack, entities } = await gatherContext();
      const result = await apiFetch("/ai/prompt-instructions", {
        method: "POST",
        body: JSON.stringify({ module_id: module.id, rules, ...stack, entities, locale }),
      });
      setFiles(result);
      await persist({ rules, files: result });
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setGenerating(false);
    }
  }

  function download() {
    exportTextFilesZip({
      title: project?.title,
      suffix: "ai-instructions",
      files: FILES.map((f) => ({ name: f.name, content: files[f.key] })),
    });
  }

  if (loading) {
    return <p className="text-sm text-dp-muted">{tCommon("loading")}</p>;
  }

  const availableSuggestions = SUGGESTED_RULES.filter((r) => !rules.includes(r));

  return (
    <div>
      <p className="text-sm text-dp-muted mt-1.5 mb-4">{t("intro")}</p>

      <div className="flex gap-2 mb-3">
        <input
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter" && draft.trim()) {
              addRule(draft);
              setDraft("");
            }
          }}
          placeholder={t("addRulePlaceholder")}
          className="flex-1 rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-2.5 text-sm outline-none transition-colors"
        />
        <TinyBtn
          onClick={() => {
            if (draft.trim()) {
              addRule(draft);
              setDraft("");
            }
          }}
        >
          <Plus size={13} /> {tCommon("add")}
        </TinyBtn>
      </div>

      {availableSuggestions.length > 0 && (
        <div className="mb-4">
          {availableSuggestions.map((r) => (
            <Chip key={r} onClick={() => addRule(r)}>
              + {r}
            </Chip>
          ))}
        </div>
      )}

      {rules.length > 0 && (
        <div className="flex flex-wrap gap-1.5 mb-4">
          {rules.map((r) => (
            <span
              key={r}
              className="inline-flex items-center gap-1.5 text-sm font-medium bg-dp-solid text-dp-on-solid rounded-full pl-3.5 pr-2 py-1.5"
            >
              {r}
              <button type="button" onClick={() => removeRule(r)} className="hover:opacity-70">
                <Trash2 size={12} />
              </button>
            </span>
          ))}
        </div>
      )}

      <div className="flex flex-wrap gap-2 items-center mb-4">
        <AiBtn onClick={generate} disabled={generating || rules.length === 0}>
          {generating ? t("generating") : files ? t("regenerate") : t("generate")}
        </AiBtn>
        {files && (
          <TinyBtn onClick={download}>
            <Download size={13} /> {t("exportZip")}
          </TinyBtn>
        )}
      </div>

      {error && <p className="text-xs text-red-500 mb-3">{error}</p>}

      {files && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
          {FILES.map((f) => (
            <FilePanel key={f.key} name={f.name} content={files[f.key]} />
          ))}
        </div>
      )}

      <CompleteButton enabled={!!files} isDone={isDone} onClick={onComplete} />
    </div>
  );
}
