"use client";

import { useEffect, useRef, useState } from "react";
import { useTranslations, useLocale } from "next-intl";
import { Download } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { useProject } from "@/lib/project-context";
import { AiBtn, TinyBtn, CompleteButton } from "@/components/ui/Buttons";
import { TreeView } from "@/components/ui/TreeView";
import { exportScaffoldZip } from "@/lib/exportScaffoldZip";

const DEFAULT_STACK = { frontend: "Next.js", backend: "Laravel", database: "PostgreSQL" };

function extractEntities(erDiagram) {
  if (!erDiagram) return [];
  const matches = [...erDiagram.matchAll(/(\w+)\s*\{/g)];
  return [...new Set(matches.map((m) => m[1]))];
}

export function FolderStructureModule({ module, isDone, onComplete }) {
  const t = useTranslations("FolderStructureModule");
  const tCommon = useTranslations("Common");
  const locale = useLocale();
  const { project } = useProject();
  const [loading, setLoading] = useState(true);
  const [itemId, setItemId] = useState(null);
  const [tree, setTree] = useState(null);
  const [stackUsed, setStackUsed] = useState(null);
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState("");
  const [exporting, setExporting] = useState(false);
  const projectRef = useRef(project);
  useEffect(() => {
    projectRef.current = project;
  }, [project]);

  useEffect(() => {
    apiFetch(`/modules/${module.id}/items`).then((items) => {
      const item = items.find((i) => i.item_type === "scaffold_tree");
      if (item) {
        setItemId(item.id);
        setTree(item.content.tree);
        setStackUsed(item.content.stack);
      }
      setLoading(false);
    });
  }, [module.id]);

  async function gatherInputs() {
    const modules = projectRef.current?.modules || [];
    let stack = DEFAULT_STACK;
    let entities = [];
    let endpoints = [];

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

    const apiModule = modules.find((m) => m.module_type === "api_design");
    if (apiModule) {
      const items = await apiFetch(`/modules/${apiModule.id}/items`);
      endpoints = items
        .filter((i) => i.item_type === "endpoint")
        .map((i) => `${i.content.method} ${i.content.path}`);
    }

    return { stack, entities, endpoints };
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
        body: JSON.stringify({ item_type: "scaffold_tree", content, is_ai_generated: true }),
      });
      setItemId(created.id);
    }
  }

  async function generate() {
    setError("");
    setGenerating(true);
    try {
      const { stack, entities, endpoints } = await gatherInputs();
      const result = await apiFetch("/ai/scaffold", {
        method: "POST",
        body: JSON.stringify({ module_id: module.id, ...stack, entities, endpoints, locale }),
      });
      setTree(result.tree);
      setStackUsed(stack);
      await persist({ tree: result.tree, stack });
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setGenerating(false);
    }
  }

  async function download() {
    setExporting(true);
    try {
      await exportScaffoldZip({ title: project?.title, tree });
    } finally {
      setExporting(false);
    }
  }

  if (loading) {
    return <p className="text-sm text-dp-muted">{tCommon("loading")}</p>;
  }

  return (
    <div>
      <p className="text-sm text-dp-muted mt-1.5 mb-4">{t("intro")}</p>

      <AiBtn onClick={generate} disabled={generating}>
        {generating ? t("generating") : tree ? t("regenerate") : t("generate")}
      </AiBtn>

      {error && <p className="text-xs text-red-500 mt-2">{error}</p>}

      {stackUsed && (
        <p className="text-xs text-dp-muted mt-3">
          {t("stackUsed", { frontend: stackUsed.frontend, backend: stackUsed.backend, database: stackUsed.database })}
        </p>
      )}

      {tree && (
        <div className="bg-dp-panel rounded-2xl border border-dp-border p-4 mt-4 overflow-x-auto">
          <TreeView tree={tree} />
        </div>
      )}

      {tree && (
        <div className="mt-3">
          <TinyBtn onClick={download} disabled={exporting}>
            <Download size={13} /> {exporting ? t("exporting") : t("exportZip")}
          </TinyBtn>
        </div>
      )}

      <CompleteButton enabled={!!tree} isDone={isDone} onClick={onComplete} />
    </div>
  );
}
