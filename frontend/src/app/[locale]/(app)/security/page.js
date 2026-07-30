"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { ShieldCheck, ShieldAlert, Monitor, ScrollText, Users } from "lucide-react";
import { Link } from "@/i18n/navigation";
import { apiFetch } from "@/lib/api";

function SummaryCard({ icon: Icon, label, value, tone }) {
  return (
    <div className="bg-dp-panel rounded-2xl border border-dp-border p-5 flex items-center gap-3">
      <div className={`w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 ${tone}`}>
        <Icon size={18} />
      </div>
      <div>
        <div className="text-xs text-dp-muted">{label}</div>
        <div className="text-sm font-semibold">{value}</div>
      </div>
    </div>
  );
}

export default function SecurityDashboardPage() {
  const t = useTranslations("Security");

  const [mfa, setMfa] = useState(null);
  const [sessions, setSessions] = useState(null);
  const [activity, setActivity] = useState(null);

  useEffect(() => {
    apiFetch("/security/two-factor").then(setMfa).catch(() => setMfa({ enabled: false }));
    apiFetch("/security/sessions").then(setSessions).catch(() => setSessions([]));
    apiFetch("/security/audit/me").then(setActivity).catch(() => setActivity([]));
  }, []);

  return (
    <div className="max-w-3xl mx-auto w-full px-4 sm:px-6 py-10 sm:py-14">
      <h1 className="text-2xl sm:text-3xl font-bold tracking-tight mb-1">{t("heading")}</h1>
      <p className="text-sm text-dp-muted mb-8">{t("subheading")}</p>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
        <SummaryCard
          icon={mfa?.enabled ? ShieldCheck : ShieldAlert}
          label={t("mfaStatusHeading")}
          value={mfa ? (mfa.enabled ? t("mfaOn") : t("mfaOff")) : t("loading")}
          tone={mfa?.enabled ? "bg-dp-green-bg text-dp-green" : "bg-dp-faint text-dp-muted-2"}
        />
        <SummaryCard
          icon={Monitor}
          label={t("sessionsHeading")}
          value={sessions ? t("sessionCount", { count: sessions.length }) : t("loading")}
          tone="bg-dp-faint text-dp-muted-2"
        />
      </div>

      <div className="flex gap-3 mb-8">
        <Link
          href="/security/audit"
          className="flex items-center gap-2 text-sm font-semibold px-4 py-2.5 rounded-full border border-dp-border hover:bg-dp-faint transition-colors"
        >
          <ScrollText size={15} /> {t("viewAuditHistory")}
        </Link>
        <Link
          href="/security/permissions"
          className="flex items-center gap-2 text-sm font-semibold px-4 py-2.5 rounded-full border border-dp-border hover:bg-dp-faint transition-colors"
        >
          <Users size={15} /> {t("viewPermissions")}
        </Link>
      </div>

      <section className="bg-dp-panel rounded-2xl border border-dp-border p-6">
        <h2 className="text-sm font-semibold uppercase tracking-wider text-dp-muted mb-4">{t("recentActivityHeading")}</h2>
        {!activity && <p className="text-xs text-dp-muted">{t("loading")}</p>}
        {activity?.length === 0 && <p className="text-xs text-dp-muted italic">{t("empty")}</p>}
        <div className="flex flex-col gap-2">
          {activity?.map((entry) => (
            <div key={entry.id} className="flex items-center justify-between text-sm border-b border-dp-border last:border-0 pb-2">
              <span className="font-mono text-xs text-dp-muted">{entry.action}</span>
              <span className="text-xs text-dp-muted">{new Date(entry.created_at).toLocaleString()}</span>
            </div>
          ))}
        </div>
      </section>
    </div>
  );
}
