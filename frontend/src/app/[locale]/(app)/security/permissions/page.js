"use client";

import { useTranslations } from "next-intl";
import { Check, X } from "lucide-react";
import { Link } from "@/i18n/navigation";

const ROLES = ["viewer", "developer", "admin", "owner"];
const ABILITIES = ["view", "act", "manage", "delete"];

// Mirrors App\Policies\ProjectPolicy exactly — not a re-guessed copy.
const MATRIX = {
  view: { viewer: true, developer: true, admin: true, owner: true },
  act: { viewer: false, developer: true, admin: true, owner: true },
  manage: { viewer: false, developer: false, admin: true, owner: true },
  delete: { viewer: false, developer: false, admin: false, owner: true },
};

export default function PermissionManagerPage() {
  const t = useTranslations("SecurityPermissions");

  return (
    <div className="max-w-3xl mx-auto w-full px-4 sm:px-6 py-10 sm:py-14">
      <h1 className="text-2xl sm:text-3xl font-bold tracking-tight mb-1">{t("heading")}</h1>
      <p className="text-sm text-dp-muted mb-6">{t("subheading")}</p>

      <div className="overflow-x-auto">
        <table className="w-full text-sm border-collapse">
          <thead>
            <tr>
              <th className="pb-3 pr-4" />
              {ROLES.map((role) => (
                <th key={role} className="text-center font-semibold text-xs uppercase tracking-wider pb-3 px-3">
                  {t(`roleLabel.${role}`)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {ABILITIES.map((ability) => (
              <tr key={ability} className="border-t border-dp-border">
                <td className="py-3 pr-4 text-xs text-dp-ink">{t(`ability.${ability}`)}</td>
                {ROLES.map((role) => (
                  <td key={role} className="py-3 px-3 text-center">
                    {MATRIX[ability][role] ? (
                      <Check size={15} className="inline text-dp-green" />
                    ) : (
                      <X size={15} className="inline text-dp-border" />
                    )}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <Link
        href="/teams"
        className="inline-flex items-center gap-1.5 text-sm font-semibold px-4 py-2.5 rounded-full bg-dp-solid text-dp-on-solid hover:opacity-90 transition-colors mt-8"
      >
        {t("goToTeams")}
      </Link>
    </div>
  );
}
