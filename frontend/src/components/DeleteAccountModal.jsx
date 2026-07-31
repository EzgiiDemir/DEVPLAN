"use client";

import { useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { X, AlertTriangle } from "lucide-react";
import { useFocusTrap } from "@/lib/useFocusTrap";

export function DeleteAccountModal({ requiresPassword, onConfirm, onClose }) {
  const t = useTranslations("DeleteAccountModal");
  const [password, setPassword] = useState("");
  const [confirming, setConfirming] = useState(false);
  const [error, setError] = useState(null);
  const dialogRef = useRef(null);
  useFocusTrap(dialogRef, () => {
    if (!confirming) onClose();
  });

  async function handleConfirm(event) {
    event.preventDefault();
    setConfirming(true);
    setError(null);
    try {
      await onConfirm(password);
    } catch (err) {
      setError(err.message || t("genericError"));
      setConfirming(false);
    }
  }

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <form
        ref={dialogRef}
        onSubmit={handleConfirm}
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="delete-account-title"
        tabIndex={-1}
        className="w-full max-w-md bg-dp-panel rounded-2xl shadow-[0_16px_50px_rgba(0,0,0,0.15)] p-6 outline-none"
      >
        <div className="flex items-center justify-between mb-3">
          <span id="delete-account-title" className="flex items-center gap-2 text-base font-bold text-red-500">
            <AlertTriangle size={18} /> {t("title")}
          </span>
          <button
            type="button"
            onClick={onClose}
            disabled={confirming}
            aria-label={t("close")}
            className="text-dp-muted hover:text-dp-ink transition-colors disabled:opacity-40"
          >
            <X size={18} />
          </button>
        </div>

        <p className="text-sm text-dp-muted mb-4">{t("warning")}</p>

        {requiresPassword && (
          <>
            <label htmlFor="delete-account-password" className="block text-xs font-semibold text-dp-muted mb-1.5">
              {t("passwordLabel")}
            </label>
            <input
              id="delete-account-password"
              type="password"
              required
              autoFocus
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              className="rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-2.5 text-sm w-full outline-none transition-colors mb-4"
            />
          </>
        )}

        {error && <p className="text-xs text-red-500 mb-4">{error}</p>}

        <div className="flex justify-end gap-2">
          <button
            type="button"
            onClick={onClose}
            disabled={confirming}
            className="text-sm font-medium px-4 py-2.5 rounded-full border border-dp-border hover:bg-dp-faint disabled:opacity-50 transition-colors"
          >
            {t("cancel")}
          </button>
          <button
            type="submit"
            disabled={confirming}
            className="text-sm font-semibold px-4 py-2.5 rounded-full bg-red-600 text-white hover:opacity-90 disabled:opacity-50 transition-colors"
          >
            {confirming ? t("confirming") : t("confirm")}
          </button>
        </div>
      </form>
    </div>
  );
}
