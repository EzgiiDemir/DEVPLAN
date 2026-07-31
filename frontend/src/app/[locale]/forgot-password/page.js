"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { apiFetch } from "@/lib/api";
import { MayaAvatar } from "@/components/MayaAvatar";
import { AuthHeader } from "@/components/AuthHeader";

export default function ForgotPasswordPage() {
  const t = useTranslations("Auth.forgotPassword");
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e) {
    e.preventDefault();
    setError("");
    setSubmitting(true);
    try {
      await apiFetch("/forgot-password", {
        method: "POST",
        body: JSON.stringify({ email }),
      });
      setSent(true);
    } catch (err) {
      setError(err.message || t("genericError"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="min-h-screen flex flex-col">
      <AuthHeader />
      <div className="flex-1 flex items-center justify-center px-4 sm:px-6 py-12">
        <div className="w-full max-w-[420px] bg-dp-panel rounded-[1.75rem] shadow-[0_16px_50px_rgba(0,0,0,0.08)] p-9">
          <div className="text-center mb-8">
            <MayaAvatar className="w-14 h-14 mx-auto mb-4" />
            <div className="text-2xl font-bold tracking-tight mb-1">{t("heading")}</div>
            <p className="text-sm text-dp-muted font-medium">{t("subheading")}</p>
          </div>

          {sent ? (
            <p className="text-sm text-center text-dp-muted">{t("sentMessage")}</p>
          ) : (
            <form onSubmit={handleSubmit} className="flex flex-col gap-3">
              <input
                type="email"
                required
                autoFocus
                placeholder={t("email")}
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-3 text-sm w-full outline-none transition-colors"
              />
              {error && <p className="text-xs text-red-500">{error}</p>}
              <button
                type="submit"
                disabled={submitting}
                className="text-sm font-semibold px-4 py-3.5 rounded-full mt-2 bg-dp-solid text-dp-on-solid hover:opacity-90 disabled:opacity-50 transition-colors"
              >
                {submitting ? t("submitting") : t("submit")}
              </button>
            </form>
          )}

          <p className="text-sm text-dp-muted mt-6 pt-6 border-t border-dp-faint text-center">
            <Link href="/login" className="text-dp-accent-strong font-semibold">
              {t("backToLogin")}
            </Link>
          </p>
        </div>
      </div>
    </div>
  );
}
