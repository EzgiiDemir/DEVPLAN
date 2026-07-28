"use client";

import { useTranslations } from "next-intl";
import { ChevronRight, Lightbulb, Search, Layers, LayoutGrid, Webhook, TerminalSquare, Mail, Quote } from "lucide-react";
import { Link } from "@/i18n/navigation";
import { useAuth } from "@/lib/auth-context";
import { LanguageSwitcher } from "@/components/LanguageSwitcher";
import { ThemeToggle } from "@/components/ThemeToggle";
import { MayaAvatar } from "@/components/MayaAvatar";

const SHOWCASE_MODULES = [
  { id: "idea", Icon: Lightbulb },
  { id: "research", Icon: Search },
  { id: "tech_stack", Icon: Layers },
  { id: "design", Icon: LayoutGrid },
  { id: "api_design", Icon: Webhook },
  { id: "environment", Icon: TerminalSquare },
];

const TESTIMONIAL_KEYS = ["one", "two", "three"];

export default function MarketingPage() {
  const t = useTranslations("Marketing");
  const tModules = useTranslations("Modules");
  const { user } = useAuth();

  return (
    <div className="min-h-screen flex flex-col">
      <header className="sticky top-0 z-40 bg-dp-panel/90 backdrop-blur border-b border-dp-border">
        <div className="max-w-6xl mx-auto w-full px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
          <div className="flex items-center gap-2">
            <span className="w-2 h-2 rounded-full bg-dp-accent" />
            <span className="text-base font-bold tracking-tight">DevPlan</span>
          </div>
          <div className="flex items-center gap-2 sm:gap-3">
            <div className="hidden sm:flex items-center gap-1">
              <LanguageSwitcher />
              <ThemeToggle />
            </div>
            {user ? (
              <Link
                href="/dashboard"
                className="text-sm font-semibold px-4 py-2 rounded-full bg-dp-solid text-dp-on-solid hover:opacity-90 transition-colors"
              >
                {t("nav.dashboard")}
              </Link>
            ) : (
              <>
                <Link
                  href="/login"
                  className="text-sm font-medium text-dp-muted hover:text-dp-ink px-3 py-2 transition-colors"
                >
                  {t("nav.login")}
                </Link>
                <Link
                  href="/register"
                  className="text-sm font-semibold px-4 py-2 rounded-full bg-dp-solid text-dp-on-solid hover:opacity-90 transition-colors"
                >
                  {t("nav.register")}
                </Link>
              </>
            )}
          </div>
        </div>
      </header>

      <main className="flex-1">
        {/* Hero */}
        <section className="max-w-6xl mx-auto w-full px-4 sm:px-6 pt-16 sm:pt-24 pb-16 sm:pb-24 text-center">
          <div className="text-[11px] font-semibold text-dp-accent-strong uppercase tracking-wider mb-5">
            {t("hero.kicker")}
          </div>
          <h1 className="text-4xl sm:text-6xl font-bold tracking-tight mb-6 max-w-3xl mx-auto text-balance">
            {t("hero.heading")}
          </h1>
          <p className="text-base sm:text-lg text-dp-muted leading-relaxed max-w-xl mx-auto mb-9">
            {t("hero.sub")}
          </p>
          <div className="flex flex-col sm:flex-row items-center justify-center gap-3">
            {user ? (
              <Link
                href="/dashboard"
                className="inline-flex items-center gap-1.5 px-6 py-3.5 rounded-full bg-dp-solid text-dp-on-solid text-sm font-semibold hover:opacity-90 transition-colors w-full sm:w-auto justify-center"
              >
                {t("nav.dashboard")} <ChevronRight size={16} />
              </Link>
            ) : (
              <>
                <Link
                  href="/register"
                  className="inline-flex items-center gap-1.5 px-6 py-3.5 rounded-full bg-dp-solid text-dp-on-solid text-sm font-semibold hover:opacity-90 transition-colors w-full sm:w-auto justify-center"
                >
                  {t("hero.ctaPrimary")} <ChevronRight size={16} />
                </Link>
                <Link
                  href="/login"
                  className="inline-flex items-center gap-1.5 px-6 py-3.5 rounded-full border border-dp-border text-sm font-semibold text-dp-ink hover:bg-dp-faint transition-colors w-full sm:w-auto justify-center"
                >
                  {t("hero.ctaSecondary")}
                </Link>
              </>
            )}
          </div>
        </section>

        {/* What we offer */}
        <section className="bg-dp-faint border-y border-dp-border">
          <div className="max-w-6xl mx-auto w-full px-4 sm:px-6 py-16 sm:py-24">
            <div className="text-center mb-12">
              <div className="text-[11px] font-semibold text-dp-accent-strong uppercase tracking-wider mb-3">
                {t("modules.kicker")}
              </div>
              <h2 className="text-2xl sm:text-3xl font-bold tracking-tight mb-3">{t("modules.heading")}</h2>
              <p className="text-sm text-dp-muted max-w-lg mx-auto">{t("modules.sub")}</p>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
              {SHOWCASE_MODULES.map(({ id, Icon }) => (
                <div key={id} className="bg-dp-panel rounded-2xl border border-dp-border p-5">
                  <div className="w-10 h-10 rounded-xl bg-dp-faint text-dp-accent-strong flex items-center justify-center mb-3">
                    <Icon size={18} strokeWidth={1.8} />
                  </div>
                  <div className="text-sm font-semibold mb-1">{tModules(`${id}.title`)}</div>
                  <p className="text-xs text-dp-muted leading-relaxed">{tModules(`${id}.sub`)}</p>
                </div>
              ))}
            </div>
            <p className="text-center text-xs text-dp-muted mt-8">{t("modules.footnote")}</p>
          </div>
        </section>

        {/* Maya */}
        <section className="max-w-6xl mx-auto w-full px-4 sm:px-6 py-16 sm:py-24">
          <div className="relative overflow-hidden bg-dp-panel rounded-[2rem] border border-dp-border p-8 sm:p-12">
            <div
              className="pointer-events-none absolute -top-24 -right-24 w-72 h-72 rounded-full opacity-40 blur-3xl"
              style={{ background: "radial-gradient(circle, var(--color-dp-accent) 0%, transparent 70%)" }}
            />
            <div className="relative flex flex-col sm:flex-row items-center gap-8 sm:gap-12 mb-10">
              <div className="relative flex-shrink-0">
                <div
                  className="absolute inset-0 rounded-full blur-2xl opacity-50"
                  style={{ background: "radial-gradient(circle, var(--color-dp-accent) 0%, transparent 70%)" }}
                />
                <div className="relative bg-dp-faint/80 backdrop-blur border border-dp-border rounded-full p-3">
                  <MayaAvatar className="w-24 h-24 sm:w-32 sm:h-32" />
                </div>
              </div>
              <div>
                <div className="text-[11px] font-semibold text-dp-accent-strong uppercase tracking-wider mb-3">
                  {t("maya.kicker")}
                </div>
                <h2 className="text-2xl sm:text-3xl font-bold tracking-tight mb-3">{t("maya.heading")}</h2>
                <p className="text-sm sm:text-base text-dp-muted leading-relaxed max-w-xl">{t("maya.body")}</p>
              </div>
            </div>
            <blockquote className="relative border-l-2 border-dp-accent pl-5 sm:pl-6 max-w-2xl">
              <p className="text-base sm:text-lg font-medium text-dp-ink leading-relaxed text-balance">
                {t("maya.voiceover")}
              </p>
            </blockquote>
          </div>
        </section>

        {/* Studio */}
        <section className="bg-dp-editor-bg text-dp-editor-text border-y border-dp-editor-border">
          <div className="max-w-6xl mx-auto w-full px-4 sm:px-6 py-16 sm:py-24 flex flex-col sm:flex-row items-center gap-10">
            <div className="flex-1">
              <div className="text-[11px] font-semibold text-dp-accent uppercase tracking-wider mb-3">
                {t("studio.kicker")}
              </div>
              <h2 className="text-2xl sm:text-3xl font-bold tracking-tight mb-4">{t("studio.heading")}</h2>
              <p className="text-sm sm:text-base text-dp-editor-muted leading-relaxed max-w-lg">{t("studio.body")}</p>
            </div>
            <div className="flex-1 w-full rounded-2xl bg-dp-editor-panel border border-dp-editor-border p-5 font-mono text-xs leading-6 text-dp-editor-muted">
              <div className="text-dp-accent-strong">{"// ProductController.php"}</div>
              <div>class ProductController extends Controller</div>
              <div>{"{"}</div>
              <div className="pl-4">public function index()</div>
              <div className="pl-4">{"{"}</div>
              <div className="pl-8 text-dp-editor-text">
                return Product::orderBy(<span className="text-dp-accent-strong">{"'price'"}</span>
                {")->get();"}
              </div>
              <div className="pl-4">{"}"}</div>
              <div>{"}"}</div>
            </div>
          </div>
        </section>

        {/* Testimonials */}
        <section className="max-w-6xl mx-auto w-full px-4 sm:px-6 py-16 sm:py-24">
          <div className="text-center mb-12">
            <h2 className="text-2xl sm:text-3xl font-bold tracking-tight mb-3">{t("testimonials.heading")}</h2>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            {TESTIMONIAL_KEYS.map((key) => (
              <div key={key} className="bg-dp-panel rounded-2xl border border-dp-border p-6 flex flex-col">
                <Quote size={20} className="text-dp-accent/50 mb-3" />
                <p className="text-sm text-dp-ink leading-relaxed mb-5 flex-1">
                  {t(`testimonials.items.${key}.quote`)}
                </p>
                <div className="flex items-center gap-3">
                  <div className="w-9 h-9 rounded-full bg-dp-faint text-dp-muted-2 flex items-center justify-center text-xs font-bold">
                    {t(`testimonials.items.${key}.initials`)}
                  </div>
                  <div>
                    <div className="text-xs font-semibold">{t(`testimonials.items.${key}.name`)}</div>
                    <div className="text-xs text-dp-muted">{t(`testimonials.items.${key}.role`)}</div>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </section>

        {/* Contact teaser */}
        <section className="bg-dp-faint border-t border-dp-border">
          <div className="max-w-3xl mx-auto w-full px-4 sm:px-6 py-16 sm:py-20 text-center">
            <h2 className="text-2xl sm:text-3xl font-bold tracking-tight mb-3">{t("contact.heading")}</h2>
            <p className="text-sm text-dp-muted mb-6 max-w-md mx-auto">{t("contact.body")}</p>
            <a
              href={`mailto:${t("contact.email")}`}
              className="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-full border border-dp-border text-sm font-semibold text-dp-ink hover:bg-dp-panel transition-colors"
            >
              <Mail size={15} /> {t("contact.email")}
            </a>
          </div>
        </section>
      </main>

      <footer className="border-t border-dp-border">
        <div className="max-w-6xl mx-auto w-full px-4 sm:px-6 py-8 flex flex-col sm:flex-row items-center justify-between gap-4">
          <div className="flex items-center gap-2">
            <span className="w-1.5 h-1.5 rounded-full bg-dp-accent" />
            <span className="text-sm font-bold tracking-tight">DevPlan</span>
          </div>
          <p className="text-xs text-dp-muted">{t("footer.rights")}</p>
          <div className="flex items-center gap-4">
            {user ? (
              <Link href="/dashboard" className="text-xs font-medium text-dp-muted hover:text-dp-ink transition-colors">
                {t("nav.dashboard")}
              </Link>
            ) : (
              <>
                <Link href="/login" className="text-xs font-medium text-dp-muted hover:text-dp-ink transition-colors">
                  {t("nav.login")}
                </Link>
                <Link href="/register" className="text-xs font-medium text-dp-muted hover:text-dp-ink transition-colors">
                  {t("nav.register")}
                </Link>
              </>
            )}
          </div>
        </div>
      </footer>
    </div>
  );
}
