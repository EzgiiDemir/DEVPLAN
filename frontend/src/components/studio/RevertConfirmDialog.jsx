"use client";

export function RevertConfirmDialog({
  title,
  diffStat,
  dirty,
  stashNotice,
  noDiffLabel,
  onCancel,
  onConfirm,
  confirming,
  confirmLabel,
  cancelLabel,
}) {
  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div className="bg-dp-editor-bg text-dp-editor-text rounded-xl border border-dp-editor-border w-full max-w-md max-h-[85vh] overflow-y-auto flex flex-col p-4 gap-3">
        <h3 className="text-sm font-semibold">{title}</h3>
        {dirty && <p className="text-[12px] text-amber-500">{stashNotice}</p>}
        <pre className="text-[11px] font-mono whitespace-pre-wrap text-dp-editor-muted bg-dp-editor-overlay rounded-lg p-2.5 max-h-56 overflow-y-auto">
          {diffStat || noDiffLabel}
        </pre>
        <div className="flex justify-end gap-2 pt-1">
          <button
            type="button"
            onClick={onCancel}
            disabled={confirming}
            className="text-xs px-3 py-1.5 rounded-lg border border-dp-editor-border disabled:opacity-40"
          >
            {cancelLabel}
          </button>
          <button
            type="button"
            onClick={onConfirm}
            disabled={confirming}
            className="text-xs font-semibold px-3 py-1.5 rounded-lg bg-red-600 text-white disabled:opacity-40"
          >
            {confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
