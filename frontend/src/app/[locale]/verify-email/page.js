"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { apiFetch } from "@/lib/api";
import { MayaAvatar } from "@/components/MayaAvatar";
import { AuthHeader } from "@/components/AuthHeader";

function verificationParamsFromUrl() {
  if (typeof window === "undefined") return { id: null, hash: null, query: "" };
  const params = new URLSearchParams(window.location.search);
  return { id: params.get("id"), hash: params.get("hash"), query: params.toString() };
}

export default function VerifyEmailPage() {
  const t = useTranslations("Auth.verifyEmail");
  const [{ id, hash, query }] = useState(verificationParamsFromUrl);
  // verifying | success | error — missing params is known from the very
  // first render, so it's real initial state, not something an effect
  // discovers and reacts to.
  const [status, setStatus] = useState(id && hash ? "verifying" : "error");
  const [error, setError] = useState(id && hash ? "" : t("missingParams"));

  useEffect(() => {
    if (!id || !hash) return;

    // The signature/expiry travel as query params too — forwarded as-is so
    // the backend can re-validate the exact same signed URL it issued.
    apiFetch(`/email/verify/${id}/${hash}?${query}`)
      .then(() => setStatus("success"))
      .catch((err) => {
        setStatus("error");
        setError(err.message || t("genericError"));
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div className="min-h-screen flex flex-col">
      <AuthHeader />
      <div className="flex-1 flex items-center justify-center px-4 sm:px-6 py-12">
        <div className="w-full max-w-[420px] bg-dp-panel rounded-[1.75rem] shadow-[0_16px_50px_rgba(0,0,0,0.08)] p-9">
          <div className="text-center mb-6">
            <MayaAvatar className="w-14 h-14 mx-auto mb-4" />
            <div className="text-2xl font-bold tracking-tight mb-1">{t("heading")}</div>
          </div>

          <p className="text-sm text-center text-dp-muted">
            {status === "verifying" && t("verifying")}
            {status === "success" && t("success")}
          </p>
          {status === "error" && <p className="text-sm text-center text-red-500">{error}</p>}

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
