"use client";

import { useEffect } from "react";
import { useTranslations } from "next-intl";
import { useRouter } from "@/i18n/navigation";
import { useAuth } from "@/lib/auth-context";
import { AppNav } from "@/components/AppNav";

export default function AppGroupLayout({ children }) {
  const tCommon = useTranslations("Common");
  const { user, loading } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (!loading && !user) router.push("/login");
  }, [loading, user, router]);

  if (loading || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center text-sm text-dp-muted">
        {tCommon("loading")}
      </div>
    );
  }

  return (
    <div className="min-h-screen flex flex-col">
      <AppNav />
      <main className="flex-1 flex flex-col">{children}</main>
    </div>
  );
}
