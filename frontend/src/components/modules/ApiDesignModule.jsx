"use client";

import { useEffect, useRef, useState } from "react";
import { useTranslations, useLocale } from "next-intl";
import { Download, Plus, Trash2 } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { useProject } from "@/lib/project-context";
import { AiBtn, TinyBtn, CompleteButton } from "@/components/ui/Buttons";
import { exportSwaggerYaml } from "@/lib/exportSwaggerYaml";

const METHOD_STYLE = {
  GET: "bg-dp-blue-bg text-dp-blue",
  POST: "bg-dp-green-bg text-dp-green",
  PUT: "bg-dp-accent-tint text-dp-accent-strong",
  PATCH: "bg-dp-accent-tint text-dp-accent-strong",
  DELETE: "bg-red-50 text-red-500",
};

const FIELD_TYPES = ["string", "integer", "number", "boolean", "array", "object"];

function FieldEditor({ label, fields, onChange }) {
  const [name, setName] = useState("");
  const [type, setType] = useState("string");

  function addField() {
    const trimmed = name.trim();
    if (!trimmed) return;
    onChange([...fields, { name: trimmed, type }]);
    setName("");
  }

  return (
    <div className="mb-2">
      <div className="text-[10px] font-semibold text-dp-muted uppercase tracking-wider mb-1">{label}</div>
      <div className="flex flex-wrap gap-1.5 mb-1.5">
        {fields.map((f, i) => (
          <span
            key={f.name + i}
            className="inline-flex items-center gap-1 text-[11px] font-mono bg-dp-faint rounded-md px-2 py-1"
          >
            {f.name}: {f.type}
            <button
              type="button"
              onClick={() => onChange(fields.filter((_, fi) => fi !== i))}
              className="text-dp-muted hover:text-red-500"
            >
              <Trash2 size={10} />
            </button>
          </span>
        ))}
      </div>
      <div className="flex gap-1.5">
        <input
          value={name}
          onChange={(e) => setName(e.target.value)}
          onKeyDown={(e) => e.key === "Enter" && addField()}
          placeholder="field_name"
          className="w-28 rounded-md border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-2 py-1 text-[11px] outline-none transition-colors"
        />
        <select
          value={type}
          onChange={(e) => setType(e.target.value)}
          className="rounded-md border border-dp-border bg-dp-faint px-1.5 py-1 text-[11px] outline-none"
        >
          {FIELD_TYPES.map((ft) => (
            <option key={ft} value={ft}>
              {ft}
            </option>
          ))}
        </select>
        <TinyBtn onClick={addField}>
          <Plus size={11} />
        </TinyBtn>
      </div>
    </div>
  );
}

export function ApiDesignModule({ module, isDone, onComplete }) {
  const t = useTranslations("ApiDesignModule");
  const tCommon = useTranslations("Common");
  const locale = useLocale();
  const { project } = useProject();
  const [loading, setLoading] = useState(true);
  const [endpoints, setEndpoints] = useState([]);
  const [prompt, setPrompt] = useState("");
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState("");
  const seeded = useRef(false);
  const projectRef = useRef(project);
  useEffect(() => {
    projectRef.current = project;
  }, [project]);

  useEffect(() => {
    apiFetch(`/modules/${module.id}/items`).then(async (existing) => {
      const own = existing.filter((i) => i.item_type === "endpoint");

      if (own.length > 0 || seeded.current) {
        setEndpoints(own.map((i) => ({ itemId: i.id, ...i.content })));
        setLoading(false);
        return;
      }

      seeded.current = true;
      const designModule = projectRef.current?.modules?.find((m) => m.module_type === "design");
      if (!designModule) {
        setLoading(false);
        return;
      }

      const designItems = await apiFetch(`/modules/${designModule.id}/items`);
      const systemItem = designItems.find((i) => i.item_type === "design_system");
      const seedList = systemItem?.content?.api_flow || [];

      const created = [];
      for (const ep of seedList) {
        const content = {
          method: (ep.method || "GET").toUpperCase(),
          path: ep.path,
          summary: ep.description || "",
          requestFields: [],
          responseFields: [],
        };
        const item = await apiFetch(`/modules/${module.id}/items`, {
          method: "POST",
          body: JSON.stringify({ item_type: "endpoint", content }),
        });
        created.push({ itemId: item.id, ...content });
      }
      setEndpoints(created);
      setLoading(false);
    });
  }, [module.id]);

  async function persistEndpoint(itemId, content) {
    await apiFetch(`/items/${itemId}`, {
      method: "PUT",
      body: JSON.stringify({ content, is_user_edited: true }),
    });
  }

  async function removeEndpoint(itemId) {
    await apiFetch(`/items/${itemId}`, { method: "DELETE" });
    setEndpoints((eps) => eps.filter((e) => e.itemId !== itemId));
  }

  async function updateEndpoint(itemId, patch) {
    const target = endpoints.find((e) => e.itemId === itemId);
    const content = { ...target, ...patch };
    delete content.itemId;
    setEndpoints((eps) => eps.map((e) => (e.itemId === itemId ? { ...e, ...patch } : e)));
    await persistEndpoint(itemId, content);
  }

  async function generateEndpoints() {
    if (!prompt.trim()) return;
    setError("");
    setGenerating(true);
    try {
      const result = await apiFetch("/ai/api-endpoints", {
        method: "POST",
        body: JSON.stringify({
          module_id: module.id,
          prompt: prompt.trim(),
          existing: endpoints.map((e) => `${e.method} ${e.path}`),
          locale,
        }),
      });

      const existingKeys = new Set(endpoints.map((e) => `${e.method} ${e.path}`.toLowerCase()));
      const created = [];
      for (const ep of result.endpoints || []) {
        const key = `${ep.method} ${ep.path}`.toLowerCase();
        if (existingKeys.has(key)) continue;
        existingKeys.add(key);
        const content = {
          method: (ep.method || "GET").toUpperCase(),
          path: ep.path,
          summary: ep.summary || "",
          requestFields: ep.requestFields || [],
          responseFields: ep.responseFields || [],
        };
        const item = await apiFetch(`/modules/${module.id}/items`, {
          method: "POST",
          body: JSON.stringify({ item_type: "endpoint", content, is_ai_generated: true }),
        });
        created.push({ itemId: item.id, ...content });
      }
      setEndpoints((eps) => [...eps, ...created]);
      setPrompt("");
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setGenerating(false);
    }
  }

  function exportYaml() {
    exportSwaggerYaml({ title: project?.title, endpoints });
  }

  if (loading) {
    return <p className="text-sm text-dp-muted">{tCommon("loading")}</p>;
  }

  return (
    <div>
      <p className="text-sm text-dp-muted mt-1.5 mb-4">{t("intro")}</p>

      <div className="flex gap-2 mb-4">
        <input
          value={prompt}
          onChange={(e) => setPrompt(e.target.value)}
          onKeyDown={(e) => e.key === "Enter" && generateEndpoints()}
          placeholder={t("promptPlaceholder")}
          className="flex-1 rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-2.5 text-sm outline-none transition-colors"
        />
        <AiBtn onClick={generateEndpoints} disabled={generating || !prompt.trim()}>
          {generating ? t("generating") : t("generate")}
        </AiBtn>
      </div>

      {error && <p className="text-xs text-red-500 mb-3">{error}</p>}

      {endpoints.length === 0 ? (
        <p className="text-sm text-dp-muted mb-4">{t("empty")}</p>
      ) : (
        <div className="flex flex-col gap-2.5 mb-4">
          {endpoints.map((ep) => (
            <div key={ep.itemId} className="bg-dp-panel rounded-2xl border border-dp-border p-4">
              <div className="flex items-start justify-between gap-2 mb-2.5">
                <div className="flex items-center gap-2 flex-wrap">
                  <span
                    className={`text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded-md ${
                      METHOD_STYLE[ep.method] || "bg-dp-faint text-dp-muted"
                    }`}
                  >
                    {ep.method}
                  </span>
                  <span className="font-mono text-sm font-semibold">{ep.path}</span>
                </div>
                <button
                  type="button"
                  onClick={() => removeEndpoint(ep.itemId)}
                  className="text-dp-muted hover:text-red-500 flex-shrink-0"
                >
                  <Trash2 size={14} />
                </button>
              </div>

              {ep.summary && <p className="text-xs text-dp-muted mb-3">{ep.summary}</p>}

              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {["POST", "PUT", "PATCH"].includes(ep.method) && (
                  <FieldEditor
                    label={t("requestFields")}
                    fields={ep.requestFields || []}
                    onChange={(fields) => updateEndpoint(ep.itemId, { requestFields: fields })}
                  />
                )}
                <FieldEditor
                  label={t("responseFields")}
                  fields={ep.responseFields || []}
                  onChange={(fields) => updateEndpoint(ep.itemId, { responseFields: fields })}
                />
              </div>
            </div>
          ))}
        </div>
      )}

      <TinyBtn onClick={exportYaml} disabled={endpoints.length === 0}>
        <Download size={13} /> {t("exportYaml")}
      </TinyBtn>

      <CompleteButton enabled={endpoints.length >= 2} isDone={isDone} onClick={onComplete} />
    </div>
  );
}
