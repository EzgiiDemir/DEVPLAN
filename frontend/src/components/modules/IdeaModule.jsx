"use client";

import { useEffect, useState } from "react";
import { useTranslations, useLocale } from "next-intl";
import { CircleAlert, Lightbulb, Users, Share2, Wallet, TrendingUp, FileDown } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { useProject } from "@/lib/project-context";
import { IDEA_TEMPLATE_KEYS, PITCH_TONES } from "@/lib/constants";
import { Panel } from "@/components/ui/Panel";
import { Chip } from "@/components/ui/Chip";
import { MultiList } from "@/components/ui/MultiList";
import { AiBtn, CompleteButton, TinyBtn } from "@/components/ui/Buttons";
import { exportLeanCanvasPdf } from "@/lib/exportLeanCanvasPdf";

const EMPTY_CANVAS = { problem: [], solution: [], customer: [], revenue: [], cost: [], channels: [] };

const FIELD_ICONS = {
  problem: CircleAlert,
  solution: Lightbulb,
  customer: Users,
  channels: Share2,
  cost: Wallet,
  revenue: TrendingUp,
};

// 6-field compressed reading of the classic Lean Canvas: problem/solution/customer
// up top (who & what), channels as the connective distribution band, cost/revenue
// as the closing financial band — mirrors the canonical canvas's flow at a glance.
const CANVAS_LAYOUT = [
  { keys: ["problem", "solution", "customer"], cols: "sm:grid-cols-2 lg:grid-cols-3" },
  { keys: ["channels"], cols: "" },
  { keys: ["cost", "revenue"], cols: "sm:grid-cols-2" },
];

export function IdeaModule({ module, isDone, onComplete }) {
  const t = useTranslations("IdeaModule");
  const tCommon = useTranslations("Common");
  const locale = useLocale();
  const { project } = useProject();
  const [loading, setLoading] = useState(true);
  const [canvasItemId, setCanvasItemId] = useState(null);
  const [canvas, setCanvas] = useState(EMPTY_CANVAS);
  const [pitchItemId, setPitchItemId] = useState(null);
  const [pitch, setPitch] = useState("");
  const [tone, setTone] = useState("medium");
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    apiFetch(`/modules/${module.id}/items`).then((items) => {
      const canvasItem = items.find((i) => i.item_type === "lean_canvas");
      const pitchItem = items.find((i) => i.item_type === "pitch");
      if (canvasItem) {
        setCanvasItemId(canvasItem.id);
        setCanvas(canvasItem.content);
      }
      if (pitchItem) {
        setPitchItemId(pitchItem.id);
        setPitch(pitchItem.content.text);
        setTone(pitchItem.content.tone);
      }
      setLoading(false);
    });
  }, [module.id]);

  async function persistCanvas(nextCanvas) {
    setCanvas(nextCanvas);
    if (canvasItemId) {
      await apiFetch(`/items/${canvasItemId}`, {
        method: "PUT",
        body: JSON.stringify({ content: nextCanvas, is_user_edited: true }),
      });
    } else {
      const created = await apiFetch(`/modules/${module.id}/items`, {
        method: "POST",
        body: JSON.stringify({ item_type: "lean_canvas", content: nextCanvas }),
      });
      setCanvasItemId(created.id);
    }
  }

  async function persistPitch(text, usedTone, aiGenerated) {
    const content = { text, tone: usedTone };
    if (pitchItemId) {
      await apiFetch(`/items/${pitchItemId}`, {
        method: "PUT",
        body: JSON.stringify({ content, is_user_edited: !aiGenerated }),
      });
    } else {
      const created = await apiFetch(`/modules/${module.id}/items`, {
        method: "POST",
        body: JSON.stringify({ item_type: "pitch", content, is_ai_generated: aiGenerated }),
      });
      setPitchItemId(created.id);
    }
  }

  async function generatePitch() {
    setError("");
    setGenerating(true);
    try {
      const result = await apiFetch("/ai/pitch", {
        method: "POST",
        body: JSON.stringify({ canvas, tone, locale }),
      });
      setPitch(result.pitch);
      await persistPitch(result.pitch, tone, true);
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setGenerating(false);
    }
  }

  if (loading) {
    return <p className="text-sm text-dp-muted">{tCommon("loading")}</p>;
  }

  const hasCanvasContent = Object.values(canvas).some((v) => v.length > 0);

  function exportPdf() {
    exportLeanCanvasPdf({
      projectTitle: project?.title,
      canvas,
      pitch,
      title: t("pdf.title"),
      canvasHeading: t("pdf.canvasHeading"),
      pitchHeading: t("pdf.pitchHeading"),
      emptyField: t("pdf.emptyField"),
      fieldLabels: Object.fromEntries(
        Object.keys(FIELD_ICONS).map((key) => [key, t(`fields.${key}`)]),
      ),
    });
  }

  return (
    <div>
      <p className="text-sm text-dp-muted mt-1.5 mb-4">{t("intro")}</p>

      <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
        <div className="flex flex-wrap gap-1.5">
          {IDEA_TEMPLATE_KEYS.map((key) => (
            <Chip key={key} onClick={() => persistCanvas(t.raw(`templateContent.${key}`))}>
              {t("applyTemplate", { name: t(`templates.${key}`) })}
            </Chip>
          ))}
        </div>
        <TinyBtn onClick={exportPdf} disabled={!hasCanvasContent && !pitch}>
          <FileDown size={13} /> {t("exportPdf")}
        </TinyBtn>
      </div>

      <div className="flex flex-col gap-3 mb-4">
        {CANVAS_LAYOUT.map((band, i) => (
          <div key={i} className={`grid grid-cols-1 ${band.cols} gap-3 items-stretch`}>
            {band.keys.map((key) => (
              <MultiList
                key={key}
                label={t(`fields.${key}`)}
                Icon={FIELD_ICONS[key]}
                items={canvas[key]}
                setItems={(v) => persistCanvas({ ...canvas, [key]: v })}
                placeholder={tCommon("add")}
              />
            ))}
          </div>
        ))}
      </div>

      <div className="flex flex-wrap gap-1.5 mb-3 items-center">
        <span className="text-xs font-semibold text-dp-muted mr-1">{t("toneLabel")}</span>
        {PITCH_TONES.map((toneKey) => (
          <Chip key={toneKey} active={tone === toneKey} onClick={() => setTone(toneKey)}>
            {t(`tones.${toneKey}`)}
          </Chip>
        ))}
      </div>

      <AiBtn onClick={generatePitch} disabled={!hasCanvasContent || generating}>
        {generating ? t("generating") : t("generate")}
      </AiBtn>

      {error && <p className="text-xs text-red-500 mt-2">{error}</p>}

      {pitch && (
        <Panel label={t("resultLabel")} className="mt-4">
          <p className="text-sm leading-relaxed m-0">{pitch}</p>
        </Panel>
      )}

      <CompleteButton enabled={!!pitch} isDone={isDone} onClick={onComplete} />
    </div>
  );
}
