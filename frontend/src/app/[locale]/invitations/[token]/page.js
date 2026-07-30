"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { CheckCircle2 } from "lucide-react";
import { Link, useRouter } from "@/i18n/navigation";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { AuthHeader } from "@/components/AuthHeader";
import { MayaAvatar } from "@/components/MayaAvatar";

export default function InvitationPage() {
  const { token } = useParams();
  const t = useTranslations("Invitations");
  const tCommon = useTranslations("Common");
  const { user, loading: authLoading } = useAuth();
  const router = useRouter();

  const [invitation, setInvitation] = useState(null);
  const [notFound, setNotFound] = useState(false);
  const [accepting, setAccepting] = useState(false);
  const [accepted, setAccepted] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    apiFetch(`/invitations/${token}`)
      .then(setInvitation)
      .catch(() => setNotFound(true));
  }, [token]);

  async function accept() {
    setAccepting(true);
    setError("");
    try {
      await apiFetch(`/invitations/${token}/accept`, { method: "POST" });
      setAccepted(true);
    } catch (err) {
      setError(err.message || t("notFound"));
    } finally {
      setAccepting(false);
    }
  }

  const wrongEmail = user && invitation && user.email.toLowerCase() !== invitation.email.toLowerCase();

  return (
    <div className="min-h-screen flex flex-col">
      <AuthHeader />
      <div className="flex-1 flex items-center justify-center px-4 sm:px-6 py-12">
        <div className="w-full max-w-[420px] bg-dp-panel rounded-[1.75rem] shadow-[0_16px_50px_rgba(0,0,0,0.08)] p-9 text-center">
          <MayaAvatar className="w-14 h-14 mx-auto mb-4" />

          {!invitation && !notFound && <p className="text-sm text-dp-muted">{t("loading")}</p>}

          {notFound && <p className="text-sm text-red-500">{t("notFound")}</p>}

          {invitation && !accepted && (
            <>
              <div className="text-xl font-bold tracking-tight mb-2">{t("heading")}</div>
              <p className="text-sm text-dp-muted mb-6">
                {t("invitedBy", { inviter: invitation.invited_by, team: invitation.team_name, role: invitation.role })}
              </p>

              {invitation.accepted ? (
                <p className="text-sm text-dp-muted">{t("alreadyAccepted")}</p>
              ) : authLoading ? (
                <p className="text-sm text-dp-muted">{tCommon("loading")}</p>
              ) : !user ? (
                <div className="flex flex-col gap-3">
                  <p className="text-xs text-dp-muted">{t("needsLogin", { email: invitation.email })}</p>
                  <Link
                    href="/login"
                    className="text-sm font-semibold px-4 py-3 rounded-full bg-dp-solid text-dp-on-solid hover:opacity-90 transition-colors"
                  >
                    {t("logIn")}
                  </Link>
                </div>
              ) : wrongEmail ? (
                <p className="text-sm text-red-500">{t("wrongEmail")}</p>
              ) : (
                <>
                  <button
                    onClick={accept}
                    disabled={accepting}
                    className="text-sm font-semibold px-5 py-3 rounded-full bg-dp-solid text-dp-on-solid hover:opacity-90 disabled:opacity-50 transition-colors"
                  >
                    {accepting ? t("accepting") : t("accept")}
                  </button>
                  {error && <p className="text-xs text-red-500 mt-3">{error}</p>}
                </>
              )}
            </>
          )}

          {accepted && (
            <>
              <CheckCircle2 size={32} className="mx-auto mb-3 text-dp-green" />
              <p className="text-sm text-dp-ink mb-6">{t("accepted", { team: invitation.team_name })}</p>
              <button
                onClick={() => router.push("/dashboard")}
                className="text-sm font-semibold px-5 py-3 rounded-full bg-dp-solid text-dp-on-solid hover:opacity-90 transition-colors"
              >
                {t("goToDashboard")}
              </button>
            </>
          )}
        </div>
      </div>
    </div>
  );
}
