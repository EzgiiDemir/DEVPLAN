"use client";

import { useEffect, useRef, useState } from "react";
import { useTranslations, useLocale } from "next-intl";
import { Download } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { useProject } from "@/lib/project-context";
import { AiBtn, TinyBtn, CompleteButton } from "@/components/ui/Buttons";
import { FilePanel } from "@/components/ui/FilePanel";
import { exportTextFilesZip } from "@/lib/exportTextFilesZip";

const DEFAULT_STACK = { frontend: "Next.js", backend: "Laravel", database: "PostgreSQL" };

const FILES = [
  { key: "gitignore", name: ".gitignore" },
  { key: "readme", name: "README.md" },
  { key: "dockerCompose", name: "docker-compose.yml" },
  { key: "envExample", name: ".env.example" },
];

export function EnvironmentModule({ module, isDone, onComplete }) {
  const t = useTranslations("EnvironmentModule");
  const tCommon = useTranslations("Common");
  const locale = useLocale();
  const { project } = useProject();
  const [loading, setLoading] = useState(true);
  const [itemId, setItemId] = useState(null);
  const [files, setFiles] = useState(null);
  const [stackUsed, setStackUsed] = useState(null);
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState("");
  const projectRef = useRef(project);
  useEffect(() => {
    projectRef.current = project;
  }, [project]);

  useEffect(() => {
    apiFetch(`/modules/${module.id}/items`).then((items) => {
      const item = items.find((i) => i.item_type === "env_files");
      if (item) {
        setItemId(item.id);
        setFiles(item.content.files);
        setStackUsed(item.content.stack);
      }
      setLoading(false);
    });
  }, [module.id]);

  async function gatherStack() {
    const modules = projectRef.current?.modules || [];
    const stackModule = modules.find((m) => m.module_type === "tech_stack");
    if (!stackModule) return DEFAULT_STACK;

    const items = await apiFetch(`/modules/${stackModule.id}/items`);
    const stackItem = items.find((i) => i.item_type === "tech_stack");
    if (!stackItem) return DEFAULT_STACK;

    return {
      frontend: stackItem.content.frontend?.selected || DEFAULT_STACK.frontend,
      backend: stackItem.content.backend?.selected || DEFAULT_STACK.backend,
      database: stackItem.content.database?.selected || DEFAULT_STACK.database,
    };
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
        body: JSON.stringify({ item_type: "env_files", content, is_ai_generated: true }),
      });
      setItemId(created.id);
    }
  }

  async function generate() {
    setError("");
    setGenerating(true);
    try {
      const stack = await gatherStack();
      const result = await apiFetch("/ai/env-setup", {
        method: "POST",
        body: JSON.stringify({ module_id: module.id, ...stack, locale }),
      });
      setFiles(result);
      setStackUsed(stack);
      await persist({ files: result, stack });
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setGenerating(false);
    }
  }

  function download() {
    exportTextFilesZip({
      title: project?.title,
      suffix: "env-files",
      files: FILES.map((f) => ({ name: f.name, content: files[f.key] })),
    });
  }

  if (loading) {
    return <p className="text-sm text-dp-muted">{tCommon("loading")}</p>;
  }

  return (
    <div>
      <p className="text-sm text-dp-muted mt-1.5 mb-4">{t("intro")}</p>

      <div className="flex flex-wrap gap-2 items-center mb-4">
        <AiBtn onClick={generate} disabled={generating}>
          {generating ? t("generating") : files ? t("regenerate") : t("generate")}
        </AiBtn>
        {files && (
          <TinyBtn onClick={download}>
            <Download size={13} /> {t("exportZip")}
          </TinyBtn>
        )}
      </div>

      {error && <p className="text-xs text-red-500 mb-3">{error}</p>}

      {stackUsed && (
        <p className="text-xs text-dp-muted mb-3">
          {t("stackUsed", { frontend: stackUsed.frontend, backend: stackUsed.backend, database: stackUsed.database })}
        </p>
      )}

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
