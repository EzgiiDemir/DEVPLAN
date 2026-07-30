"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Info, Mail, LayoutGrid, Sparkles, TerminalSquare, Users, Settings, ShieldCheck, Menu, X } from "lucide-react";
import { Link, usePathname } from "@/i18n/navigation";
import { LanguageSwitcher } from "@/components/LanguageSwitcher";
import { ThemeToggle } from "@/components/ThemeToggle";

const LINKS = [
  { href: "/dashboard", key: "modules", Icon: LayoutGrid },
  { href: "/maya", key: "maya", Icon: Sparkles },
  { href: "/studio", key: "studio", Icon: TerminalSquare },
  { href: "/teams", key: "teams", Icon: Users },
  { href: "/about", key: "about", Icon: Info },
  { href: "/contact", key: "contact", Icon: Mail },
];

export function AppNav() {
  const t = useTranslations("Nav");
  const pathname = usePathname();
  const [open, setOpen] = useState(false);

  return (
    <header className="sticky top-0 z-40 bg-dp-panel/90 backdrop-blur border-b border-dp-border">
      <div className="max-w-6xl mx-auto w-full px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
        <Link href="/dashboard" className="flex items-center gap-2 shrink-0">
          <span className="w-2 h-2 rounded-full bg-dp-accent" />
          <span className="text-base font-bold tracking-tight">DevPlan</span>
        </Link>

        <nav className="hidden lg:flex items-center gap-1">
          {LINKS.map(({ href, key, Icon }) => {
            const active = pathname === href;
            return (
              <Link
                key={href}
                href={href}
                className={`flex items-center gap-1.5 px-3 py-2 rounded-full text-sm font-medium transition-colors ${
                  active ? "bg-dp-faint text-dp-ink" : "text-dp-muted hover:text-dp-ink hover:bg-dp-faint"
                }`}
              >
                <Icon size={15} strokeWidth={1.8} />
                {t(key)}
              </Link>
            );
          })}
        </nav>

        <div className="hidden lg:flex items-center gap-1">
          <Link
            href="/security"
            className={`w-9 h-9 rounded-full flex items-center justify-center transition-colors ${
              pathname.startsWith("/security") ? "bg-dp-faint text-dp-ink" : "text-dp-muted hover:text-dp-ink hover:bg-dp-faint"
            }`}
            aria-label={t("security")}
          >
            <ShieldCheck size={16} strokeWidth={1.8} />
          </Link>
          <Link
            href="/settings"
            className={`w-9 h-9 rounded-full flex items-center justify-center transition-colors ${
              pathname === "/settings" ? "bg-dp-faint text-dp-ink" : "text-dp-muted hover:text-dp-ink hover:bg-dp-faint"
            }`}
            aria-label={t("settings")}
          >
            <Settings size={16} strokeWidth={1.8} />
          </Link>
          <LanguageSwitcher />
          <ThemeToggle />
        </div>

        <button
          onClick={() => setOpen((v) => !v)}
          className="lg:hidden w-9 h-9 rounded-full flex items-center justify-center text-dp-muted hover:text-dp-ink hover:bg-dp-faint transition-colors"
          aria-label={t("menuToggle")}
        >
          {open ? <X size={18} /> : <Menu size={18} />}
        </button>
      </div>

      {open && (
        <div className="lg:hidden border-t border-dp-border bg-dp-panel px-4 sm:px-6 py-3 flex flex-col gap-1">
          {LINKS.map(({ href, key, Icon }) => (
            <Link
              key={href}
              href={href}
              onClick={() => setOpen(false)}
              className={`flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors ${
                pathname === href ? "bg-dp-faint text-dp-ink" : "text-dp-muted hover:text-dp-ink hover:bg-dp-faint"
              }`}
            >
              <Icon size={16} strokeWidth={1.8} />
              {t(key)}
            </Link>
          ))}
          <Link
            href="/security"
            onClick={() => setOpen(false)}
            className={`flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors ${
              pathname.startsWith("/security") ? "bg-dp-faint text-dp-ink" : "text-dp-muted hover:text-dp-ink hover:bg-dp-faint"
            }`}
          >
            <ShieldCheck size={16} strokeWidth={1.8} />
            {t("security")}
          </Link>
          <Link
            href="/settings"
            onClick={() => setOpen(false)}
            className={`flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors ${
              pathname === "/settings" ? "bg-dp-faint text-dp-ink" : "text-dp-muted hover:text-dp-ink hover:bg-dp-faint"
            }`}
          >
            <Settings size={16} strokeWidth={1.8} />
            {t("settings")}
          </Link>
          <div className="flex items-center gap-2 mt-2 pt-3 border-t border-dp-border">
            <LanguageSwitcher />
            <ThemeToggle />
          </div>
        </div>
      )}
    </header>
  );
}
