"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { CheckCircle2, Save, XCircle } from "lucide-react";
import { useCompanion } from "@/lib/companion-context";

function parseKeys(content) {
  const keys = new Set();
  for (const line of (content || "").split("\n")) {
    const match = line.match(/^\s*([A-Z_][A-Z0-9_]*)\s*=/);
    if (match) keys.add(match[1]);
  }
  return keys;
}

// The only component that ever calls readEnvFile/writeEnvFile — real .env
// content never leaves this panel, and never reaches the backend at all.
export function EnvironmentManagerPanel({ requiredEnvVars = [] }) {
  const t = useTranslations("StudioEnvironmentManager");
  const companion = useCompanion();

  const [content, setContent] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    void Promise.resolve().then(async () => {
      try {
        const result = await companion.readEnvFile(".env");
        setContent(result.content);
      } catch (err) {
        setError(err.message);
      } finally {
        setLoading(false);
      }
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function save() {
    setSaving(true);
    setError(null);
    setSaved(false);
    try {
      await companion.writeEnvFile(".env", content);
      setSaved(true);
    } catch (err) {
      setError(err.message);
    } finally {
      setSaving(false);
    }
  }

  const presentKeys = parseKeys(content);

  return (
    <div className="flex flex-col gap-2">
      <p className="text-[11px] text-dp-editor-muted">{t("intro")}</p>

      {requiredEnvVars.length > 0 && (
        <div className="flex flex-col gap-1 border border-dp-editor-border rounded-lg p-2">
          {requiredEnvVars.map((key) => (
            <div key={key} className="flex items-center gap-1.5 text-[11px] font-mono">
              {presentKeys.has(key) ? (
                <CheckCircle2 size={11} className="text-green-500 flex-shrink-0" />
              ) : (
                <XCircle size={11} className="text-amber-500 flex-shrink-0" />
              )}
              {key}
            </div>
          ))}
        </div>
      )}

      {loading ? (
        <p className="text-[11px] text-dp-editor-muted italic">{t("loading")}</p>
      ) : (
        <>
          <textarea
            value={content}
            onChange={(e) => setContent(e.target.value)}
            rows={8}
            spellCheck={false}
            className="w-full font-mono text-[11px] bg-dp-editor-bg border border-dp-editor-border rounded p-2 text-dp-editor-text outline-none"
          />
          <button
            type="button"
            onClick={save}
            disabled={saving}
            className="flex items-center gap-1.5 self-start text-xs font-semibold px-3 py-1.5 rounded-lg bg-dp-accent text-white disabled:opacity-40"
          >
            <Save size={12} />
            {saving ? t("saving") : t("save")}
          </button>
          {saved && <p className="text-[11px] text-green-500">{t("saved")}</p>}
        </>
      )}

      {error && <p className="text-[11px] text-red-400">{error}</p>}
    </div>
  );
}
