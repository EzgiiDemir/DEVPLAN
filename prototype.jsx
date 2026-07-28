import { useState } from "react";
import {
  Lightbulb, Search, ListChecks, Target, LayoutGrid, Layers,
  Webhook, FolderTree, KanbanSquare, TerminalSquare, Sparkles, BookOpen,
  ArrowLeft, Check, Plus, Trash2, ArrowRightCircle, Wand2, RefreshCw, X
} from "lucide-react";

const C = { ink: "#16211D", muted: "#5B6B65", border: "#C7D0CC", accent: "#E06B2C", faint: "#F1F3F2", green: "#1E6B4E", greenBg: "#E7F3EC", blue: "#2B5FA3", blueBg: "#E9F0F9" };

const MODULES = [
  { id: "idea", n: 1, title: "Fikir & pitch", sub: "Çoklu Lean canvas, şablonlar", Icon: Lightbulb },
  { id: "research", n: 2, title: "Pazar araştırması", sub: "AI analiz, puan, trend, filtre", Icon: Search },
  { id: "requirements", n: 3, title: "Gereksinimler", sub: "12 sektör, stack önerisi", Icon: ListChecks },
  { id: "mvp", n: 4, title: "MVP kapsamı", sub: "Gereksinimden otomatik aktar", Icon: Target },
  { id: "design", n: 5, title: "Tasarım", sub: "Sayfa filtreleri, wireframe önizleme", Icon: LayoutGrid },
  { id: "stack", n: 6, title: "Teknoloji seçimi", sub: "7 katman, uyumluluk önerisi", Icon: Layers },
  { id: "api", n: 7, title: "API tasarla", sub: "Otomatik CRUD + özellik bazlı öneri", Icon: Webhook },
  { id: "folders", n: 8, title: "Klasör yapısı", sub: "Stack'e göre otomatik + düzenle", Icon: FolderTree },
  { id: "tasks", n: 9, title: "Görev planı", sub: "MVP'den sprint, öncelik etiketi", Icon: KanbanSquare },
  { id: "env", n: 10, title: "Ortam kurulumu", sub: "4 dosya + paket yöneticisi", Icon: TerminalSquare },
  { id: "prompt", n: 11, title: "Prompt mühendisliği", sub: "Kategorili kurallar, çoklu çıktı", Icon: Sparkles },
  { id: "resources", n: 12, title: "AI araç seçimi", sub: "Güncel araştırılmış araç veritabanı", Icon: BookOpen },
];

/* ---------- 1. IDEA ---------- */
const CANVAS_FIELDS = [
  ["problem", "Problem"], ["solution", "Solution"], ["customer", "Customer"],
  ["revenue", "Revenue"], ["cost", "Cost"], ["channels", "Channels"],
];
const IDEA_TEMPLATES = {
  "E-ticaret": { problem: ["Küçük satıcılar online mağaza açamıyor"], solution: ["Kod yazmadan mağaza kurma"], customer: ["Küçük işletme sahipleri"], revenue: ["Aylık abonelik"], cost: ["Sunucu, ödeme komisyonu"], channels: ["Instagram reklamları"] },
  "SaaS": { problem: ["Ekipler dağınık araçlarla çalışıyor"], solution: ["Tek panelde birleşik iş akışı"], customer: ["Küçük-orta ekipler"], revenue: ["Kullanıcı başı abonelik"], cost: ["Bulut altyapı"], channels: ["İçerik pazarlama, SEO"] },
  "Mobil uygulama": { problem: ["Kullanıcılar alışkanlığı takip edemiyor"], solution: ["Günlük hatırlatma + takip"], customer: ["Bireysel kullanıcılar"], revenue: ["Freemium + reklam"], cost: ["App store komisyonu"], channels: ["App Store / Play Store ASO"] },
};

/* ---------- 2. RESEARCH ---------- */
const RESEARCH_SUGGESTIONS = { "E-ticaret": ["Trendyol", "Amazon", "Shopify"], "SaaS": ["Notion", "Linear", "Monday.com"], "Mobil uygulama": ["Duolingo", "Headspace", "Todoist"] };
const TREND_OPTS = ["Yükselişte", "Stabil", "Düşüşte"];

/* ---------- 3. REQUIREMENTS ---------- */
const FEATURE_SETS = {
  "E-ticaret": ["Login", "Ödeme", "Ürün listeleme", "Sepet", "Arama", "Kargo takibi", "İade yönetimi"],
  "SaaS": ["Login", "Abonelik/faturalama", "Dashboard", "Takım daveti", "Rol yönetimi", "Bildirimler"],
  "Mobil uygulama": ["Login", "Push bildirim", "Offline mod", "Profil", "Uygulama içi satın alma"],
  "Blog / içerik": ["Login", "İçerik editörü", "Yorum", "Arama", "SEO ayarları"],
  "Pazaryeri (marketplace)": ["Çift taraflı kayıt", "Ödeme aracılığı", "Değerlendirme/puan", "Mesajlaşma", "Komisyon yönetimi"],
  "Eğitim platformu": ["Login", "Kurs/video oynatıcı", "Quiz/sınav", "Sertifika", "İlerleme takibi"],
  "Fintech": ["KYC/kimlik doğrulama", "Cüzdan/bakiye", "Transfer", "İşlem geçmişi", "Bildirimler"],
  "Sağlık": ["Randevu sistemi", "Hasta kaydı", "Reçete", "Video görüşme", "KVKK uyumu"],
  "Sosyal medya": ["Login", "Gönderi paylaşma", "Takip sistemi", "Mesajlaşma", "Bildirimler"],
  "İç kurumsal araç": ["SSO girişi", "Rol/izin yönetimi", "Raporlama", "Denetim kaydı (audit log)"],
  "Oyun": ["Kullanıcı profili", "Skor tablosu", "Uygulama içi satın alma", "Çok oyunculu"],
  "IoT / donanım": ["Cihaz eşleştirme", "Gerçek zamanlı veri", "Uzaktan kontrol", "Bildirimler"],
};
const STACK_RECS = {
  "E-ticaret": "Next.js + Node.js (NestJS) + PostgreSQL + Stripe",
  "SaaS": "Next.js + Node.js (NestJS) + PostgreSQL + Clerk",
  "Mobil uygulama": "React Native (Expo) + FastAPI + PostgreSQL",
  "Blog / içerik": "Next.js + Headless CMS + PostgreSQL",
  "Pazaryeri (marketplace)": "Next.js + NestJS + PostgreSQL + Stripe Connect",
  "Eğitim platformu": "Next.js + FastAPI + PostgreSQL + Supabase Storage",
  "Fintech": "Next.js + NestJS + PostgreSQL + güçlü şifreleme katmanı",
  "Sağlık": "Next.js + FastAPI + PostgreSQL + KVKK uyumlu barındırma",
  "Sosyal medya": "Next.js + NestJS + PostgreSQL + Redis (feed cache)",
  "İç kurumsal araç": "Next.js + NestJS + PostgreSQL + SSO (Auth0)",
  "Oyun": "Unity/Godot istemci + Node.js backend + PostgreSQL",
  "IoT / donanım": "React Native/Next.js + FastAPI + MQTT + PostgreSQL",
};

/* ---------- 5. DESIGN ---------- */
const PAGE_OPTIONS = ["Ana sayfa", "Ürün / detay", "Sepet & ödeme", "Profil & ayarlar", "Admin paneli", "Onboarding", "Arama sonuçları", "Bildirimler"];

/* ---------- 6. STACK ---------- */
const STACK_OPTIONS = {
  Frontend: ["React (Next.js)", "Vue (Nuxt)", "SvelteKit", "Angular", "Remix"],
  Backend: ["Node.js (NestJS)", "Python (FastAPI)", "Go (Fiber)", "Ruby on Rails", "Java (Spring Boot)", "PHP (Laravel)"],
  Veritabanı: ["PostgreSQL", "MongoDB", "MySQL", "SQLite", "Supabase", "Firebase"],
  Hosting: ["Vercel + Railway", "AWS", "Render", "Netlify", "Fly.io", "DigitalOcean"],
  Mobil: ["React Native (Expo)", "Flutter", "Native (Swift/Kotlin)", "—"],
  Auth: ["Clerk", "Auth0", "Supabase Auth", "NextAuth", "Firebase Auth"],
  Ödeme: ["Stripe", "Iyzico", "PayTR", "Paddle", "—"],
};
const STACK_HINTS = (stack) => {
  const hints = [];
  if (stack.Frontend?.includes("Next.js")) hints.push("Next.js seçtin → Hosting için Vercel + Railway doğal bir eşleşme.");
  if (stack.Backend?.includes("NestJS")) hints.push("NestJS seçtin → PostgreSQL ile ORM (Prisma/TypeORM) uyumu güçlü.");
  if (stack.Veritabanı === "Supabase") hints.push("Supabase seçtin → Auth için ayrıca Supabase Auth kullanman entegrasyonu basitleştirir.");
  if (stack.Mobil && stack.Mobil !== "—") hints.push("Mobil platform seçtin → Auth ve API katmanını mobil-uyumlu (JWT tabanlı) tasarla.");
  return hints;
};

/* ---------- 11. PROMPT ---------- */
const RULE_GROUPS = {
  "Kod stili": ["TypeScript strict mode kullan", "Fonksiyonel bileşenler + hooks", "Açıklayıcı isimlendirme, gereksiz yorum yok"],
  "Mimari": ["Clean architecture uygula", "Katmanları (controller/service/repo) ayır", "Feature-based klasörleme kullan"],
  "Test": ["Her fonksiyon için unit test yaz", "Kritik akışlar için e2e test ekle"],
  "Güvenlik": ["Girdi doğrulaması (validation) uygula", "Sırları .env üzerinden yönet, hardcode etme"],
  "İşbirliği": ["Conventional commits formatı kullan", "Harici kütüphane eklemeden önce sor", "Her PR için açıklama yaz"],
};
const TARGET_TOOLS = { "Claude Code": "CLAUDE.md", "Cursor": ".cursorrules", "GitHub Copilot": ".github/copilot-instructions.md" };

/* ---------- 12. AI TOOLS (Temmuz 2026 itibarıyla güncel araştırmaya dayanır) ---------- */
const TOOL_DB = [
  { name: "Claude (Anthropic)", cat: "Genel asistan", desc: "Uzun doküman analizi ve derin akıl yürütmede öne çıkıyor." },
  { name: "ChatGPT (OpenAI)", cat: "Genel asistan", desc: "En çok yönlü asistan; strateji ve beyin fırtınası için güçlü." },
  { name: "Perplexity", cat: "Genel asistan", desc: "Kaynak göstererek güncel web araması yapan araştırma asistanı." },
  { name: "Claude Code", cat: "Kodlama", desc: "Terminal odaklı, büyük refactor ve çok dosyalı işlerde güçlü." },
  { name: "Cursor", cat: "Kodlama", desc: "AI-first VS Code fork'u — 2026'nın en yaygın AI IDE'si." },
  { name: "GitHub Copilot", cat: "Kodlama", desc: "En yaygın dağıtım, IDE içi öneri asistanı." },
  { name: "Windsurf", cat: "Kodlama", desc: "Cascade motoruyla güçlendirilmiş, Cursor'a güçlü alternatif." },
  { name: "v0 (Vercel)", cat: "Kodlama", desc: "Prompt'tan doğrudan React/UI bileşeni üretir, hızlı prototipleme." },
  { name: "Lovable / Bolt.new", cat: "Kodlama", desc: "Fikirden çalışan web uygulamasına en hızlı gidiş (vibe coding)." },
  { name: "Figma AI", cat: "Tasarım", desc: "Figma içinde AI destekli düzen ve bileşen üretimi." },
  { name: "Midjourney", cat: "Tasarım", desc: "Sanatsal/yaratıcı görsel üretiminde lider." },
  { name: "Canva AI", cat: "Tasarım", desc: "Hızlı, şablon tabanlı görsel ve sunum üretimi." },
  { name: "Adobe Firefly", cat: "Tasarım", desc: "Ticari kullanıma uygun lisanslı görsel üretimi." },
  { name: "Framer AI", cat: "Tasarım", desc: "Prompt'tan yayına hazır web sitesi/landing page üretir." },
  { name: "Runway", cat: "Video/Ses", desc: "AI video üretimi ve düzenleme." },
  { name: "ElevenLabs", cat: "Video/Ses", desc: "Gerçekçi AI seslendirme ve ses klonlama." },
  { name: "n8n", cat: "Otomasyon", desc: "Açık kaynaklı, görsel iş akışı otomasyon aracı." },
  { name: "Notion AI", cat: "Yazı/İçerik", desc: "Doküman içinde özetleme, yazma ve düzenleme asistanı." },
  { name: "Jasper", cat: "Yazı/İçerik", desc: "Pazarlama odaklı, yüksek dönüşümlü metin üretimi." },
];
const TOOL_CATS = [...new Set(TOOL_DB.map((t) => t.cat))];
const TOOL_RECS = {
  "Web uygulaması": ["Cursor", "Claude Code", "v0 (Vercel)", "Figma AI"],
  "Mobil uygulama": ["Cursor", "Figma AI", "Claude Code"],
  "Tasarım ağırlıklı": ["Figma AI", "Midjourney", "Framer AI", "Canva AI"],
  "Backend ağırlıklı": ["Claude Code", "Windsurf", "GitHub Copilot"],
  "İçerik / pazarlama sitesi": ["Framer AI", "Notion AI", "Jasper", "Canva AI"],
};

const LEARN_LINKS = [
  { label: "roadmap.sh", url: "https://roadmap.sh" },
  { label: "freeCodeCamp", url: "https://www.freecodecamp.org" },
  { label: "MDN Web Docs", url: "https://developer.mozilla.org" },
];

/* ---------- üretim fonksiyonları (gerçek sürümde Claude API burada çağrılır) ---------- */
const genPitch = (canvas, tone) => {
  const j = (k) => (canvas[k] || []).join(", ") || "belirtilmedi";
  const base = `${j("customer")} için ${j("problem")} problemini çözüyoruz: ${j("solution")}.`;
  if (tone === "Kısa") return base;
  const mid = `${base} Gelir modeli: ${j("revenue")}.`;
  if (tone === "Orta") return mid;
  return `${mid} Maliyet merkezi: ${j("cost")}. Kullanıcıya ulaşım kanalları: ${j("channels")}.`;
};
const genCompetitor = (name) => ({
  name, price: "Orta-üst segment fiyatlandırma",
  feature: `${name} temel akışları çözüyor, gelişmiş kişiselleştirme sunmuyor`,
  pro: "Güçlü marka bilinirliği ve kullanıcı tabanı",
  con: "Niş kullanıcı ihtiyaçlarına özelleşmemiş",
  rating: (Math.random() * 1.3 + 3.5).toFixed(1),
  trend: TREND_OPTS[Math.floor(Math.random() * TREND_OPTS.length)],
});
const genStory = (persona, feature) => ({
  text: `Bir ${persona || "kullanıcı"} olarak, ${feature.toLowerCase()} özelliğini kullanmak istiyorum çünkü akışımı kesintiye uğratmadan işimi tamamlamak istiyorum.`,
  criteria: `Kabul kriteri: ${feature} akışı 3 adımdan uzun sürmemeli, hata durumunda anlaşılır mesaj gösterilmeli.`,
});
const guessCategory = (text) => {
  const t = text.toLowerCase();
  if (/(ödeme|login|kayıt|güvenlik|kyc|payment)/.test(t)) return "Must";
  if (/(bildirim|arama|filtre|takip|search)/.test(t)) return "Should";
  if (/(sohbet|chat|öneri|tavsiye|sosyal)/.test(t)) return "Could";
  return "Should";
};
const guessResource = (desc) => {
  const words = (desc || "").trim().split(/\s+/).filter(Boolean);
  const last = words[words.length - 1] || "kaynak";
  return last.replace(/[^a-zA-ZğüşıöçĞÜŞİÖÇ]/g, "").toLowerCase() || "kaynak";
};
const genEndpoints = (resource) => ([
  { method: "GET", path: `/api/${resource}`, desc: "Listele" },
  { method: "POST", path: `/api/${resource}`, desc: "Oluştur" },
  { method: "GET", path: `/api/${resource}/{id}`, desc: "Tek kaydı getir" },
  { method: "PUT", path: `/api/${resource}/{id}`, desc: "Güncelle" },
  { method: "DELETE", path: `/api/${resource}/{id}`, desc: "Sil" },
]);
const FEATURE_ENDPOINT_MAP = {
  "Ödeme": [{ method: "POST", path: "/api/checkout", desc: "Ödeme başlat" }, { method: "POST", path: "/api/payments/webhook", desc: "Ödeme sağlayıcı webhook" }],
  "Login": [{ method: "POST", path: "/api/auth/login", desc: "Giriş yap" }, { method: "POST", path: "/api/auth/register", desc: "Kayıt ol" }],
  "Abonelik/faturalama": [{ method: "GET", path: "/api/billing/plans", desc: "Planları listele" }, { method: "POST", path: "/api/billing/subscribe", desc: "Abone ol" }],
  "Arama": [{ method: "GET", path: "/api/search", desc: "Arama yap" }],
  "Push bildirim": [{ method: "POST", path: "/api/notifications/send", desc: "Bildirim gönder" }],
};
const genSwagger = (eps) => `openapi: 3.0.0\npaths:\n${eps.map((e) => `  ${e.path}:\n    ${e.method.toLowerCase()}:\n      summary: ${e.desc}`).join("\n")}`;
const genFolders = (frontend, backend) => {
  const fe = (frontend || "").includes("Next.js") ? [{ name: "frontend/app", depth: 0 }, { name: "components", depth: 1 }, { name: "lib", depth: 1 }]
    : (frontend || "").includes("Vue") ? [{ name: "frontend/src", depth: 0 }, { name: "components", depth: 1 }, { name: "pages", depth: 1 }]
    : [{ name: "frontend/src", depth: 0 }, { name: "routes", depth: 1 }, { name: "lib", depth: 1 }];
  const be = (backend || "").includes("NestJS") ? [{ name: "backend/src", depth: 0 }, { name: "modules", depth: 1 }, { name: "common", depth: 1 }]
    : (backend || "").includes("FastAPI") ? [{ name: "backend/app", depth: 0 }, { name: "routers", depth: 1 }, { name: "models", depth: 1 }]
    : [{ name: "backend/src", depth: 0 }, { name: "controllers", depth: 1 }, { name: "services", depth: 1 }];
  return [...fe, ...be];
};
const genEnvFiles = (langs, pm, deploy) => ({
  gitignore: `node_modules/\n.env\ndist/\n${langs.includes("Python") ? "__pycache__/\n*.pyc\n" : ""}${deploy.includes("Docker") ? "*.log\n" : ""}.DS_Store`,
  readme: `# Proje adı\n\n## Kurulum\n${langs.map((l) => `- ${l} yüklü olmalı`).join("\n")}\n\n## Bağımlılık kurulumu\n\`\`\`\n${pm} install\n\`\`\`\n\n## Çalıştırma\n\`\`\`\n${pm} run dev\n\`\`\``,
  docker: deploy.includes("Docker") ? `version: "3.9"\nservices:\n  app:\n    build: .\n    ports:\n      - "3000:3000"` : "# Docker seçilmedi — atlanabilir",
  envExample: `DATABASE_URL=\nAPI_KEY=\nPORT=3000`,
});
const genPromptFiles = (rules, stackNote) => {
  const list = rules.length ? [...rules, ...(stackNote ? [stackNote] : [])] : ["(henüz kural seçilmedi)"];
  return {
    claudeMd: `# Proje kuralları\n\n${list.map((r) => `- ${r}`).join("\n")}`,
    cursorRules: `// cursor rules\n${list.map((r) => `- ${r}`).join("\n")}`,
    aiInstructions: list.join(". ") + ".",
  };
};

function Panel({ label, children, style }) {
  return <div style={{ background: "#fff", border: `1px solid ${C.border}`, padding: 14, ...style }}>
    {label && <div className="dp-mono" style={{ fontSize: 11, color: "#96A19C", marginBottom: 8 }}>{label.toUpperCase()}</div>}
    {children}
  </div>;
}
function CompleteButton({ enabled, isDone, onClick }) {
  return <button disabled={!enabled} onClick={onClick} className="dp-mono"
    style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 12.5, padding: "10px 16px", marginTop: 18, background: enabled ? C.ink : "#D8DEDC", color: enabled ? "#fff" : "#96A19C", border: "none", cursor: enabled ? "pointer" : "not-allowed" }}>
    {isDone ? <Check size={14} /> : null}{isDone ? "MODÜL TAMAMLANDI" : "MODÜLÜ TAMAMLA VE İLERLE"}
  </button>;
}
function TinyBtn({ children, onClick, danger }) {
  return <button onClick={onClick} className="dp-mono"
    style={{ fontSize: 11, padding: "5px 9px", border: `1px solid ${danger ? "#D64545" : C.border}`, background: "#fff", color: danger ? "#D64545" : C.ink, cursor: "pointer", display: "flex", alignItems: "center", gap: 4 }}>
    {children}
  </button>;
}
function AiBtn({ children, onClick, disabled }) {
  return <button onClick={onClick} disabled={disabled} className="dp-mono"
    style={{ fontSize: 11.5, padding: "7px 12px", border: `1px solid ${C.accent}`, background: "#fff", color: C.accent, cursor: disabled ? "not-allowed" : "pointer", opacity: disabled ? 0.4 : 1, display: "flex", alignItems: "center", gap: 5 }}>
    <Wand2 size={12} />{children}
  </button>;
}
function Chip({ active, children, onClick }) {
  return <div onClick={onClick} className="dp-pick"
    style={{ padding: "6px 11px", fontSize: 12, border: `1px solid ${active ? C.ink : C.border}`, background: active ? C.ink : "#fff", color: active ? "#fff" : C.ink, display: "inline-block", marginRight: 6, marginBottom: 6 }}>
    {children}
  </div>;
}
function MultiList({ label, items, setItems, placeholder }) {
  const [draft, setDraft] = useState("");
  return <Panel label={label}>
    {items.map((v, i) => (
      <div key={i} style={{ display: "flex", justifyContent: "space-between", alignItems: "center", fontSize: 12.5, background: C.faint, padding: "4px 7px", marginBottom: 4 }}>
        <span>{v}</span><span onClick={() => setItems(items.filter((_, idx) => idx !== i))} style={{ cursor: "pointer", color: C.muted }}><X size={12} /></span>
      </div>
    ))}
    <div style={{ display: "flex", gap: 5, marginTop: 6 }}>
      <input className="dp-input" placeholder={placeholder} value={draft} onChange={(e) => setDraft(e.target.value)} style={{ flex: 1, padding: 6, fontSize: 12 }} />
      <TinyBtn onClick={() => { if (draft.trim()) { setItems([...items, draft]); setDraft(""); } }}><Plus size={11} /></TinyBtn>
    </div>
  </Panel>;
}

export default function DevPlan() {
  const [active, setActive] = useState(null);
  const [done, setDone] = useState({});

  const [canvas, setCanvas] = useState({ problem: [], solution: [], customer: [], revenue: [], cost: [], channels: [] });
  const [pitch, setPitch] = useState("");
  const [tone, setTone] = useState("Orta");
  const [pdfReady, setPdfReady] = useState(false);

  const [researchCat, setResearchCat] = useState("E-ticaret");
  const [competitors, setCompetitors] = useState([]);
  const [compDraft, setCompDraft] = useState("");
  const [sortBy, setSortBy] = useState("rating");

  const [projectType, setProjectType] = useState("E-ticaret");
  const [features, setFeatures] = useState({});
  const [reqTags, setReqTags] = useState({});
  const [persona, setPersona] = useState("");
  const [stories, setStories] = useState([]);

  const [mvpItems, setMvpItems] = useState([]);
  const [mvpDraft, setMvpDraft] = useState("");

  const [figmaLink, setFigmaLink] = useState("");
  const [pages, setPages] = useState({});
  const [designOut, setDesignOut] = useState(null);

  const [stack, setStack] = useState({});

  const [apiDesc, setApiDesc] = useState("");
  const [endpoints, setEndpoints] = useState([]);
  const [methodFilter, setMethodFilter] = useState(null);

  const [folders, setFolders] = useState([]);
  const [folderDraft, setFolderDraft] = useState({ name: "", depth: 0 });

  const [tasks, setTasks] = useState({ todo: [], doing: [], done: [] });
  const [taskDraft, setTaskDraft] = useState("");

  const [langs, setLangs] = useState([]);
  const [pm, setPm] = useState("npm");
  const [deploy, setDeploy] = useState([]);
  const [envOut, setEnvOut] = useState(null);

  const [promptRules, setPromptRules] = useState({});
  const [targetTool, setTargetTool] = useState("Claude Code");
  const [promptOut, setPromptOut] = useState(null);
  const [customRule, setCustomRule] = useState("");
  const [customRules, setCustomRules] = useState([]);

  const [toolCat, setToolCat] = useState(null);
  const [toolType, setToolType] = useState(null);

  const completedCount = Object.values(done).filter(Boolean).length;
  const pct = Math.round((completedCount / MODULES.length) * 100);
  const activeModule = MODULES.find((m) => m.id === active);
  const markDone = (id) => setDone((d) => ({ ...d, [id]: true }));
  const removeAt = (list, setList, i) => setList(list.filter((_, idx) => idx !== i));
  const selectedFeatures = Object.keys(features).filter((f) => features[f]);

  const sortedCompetitors = [...competitors].sort((a, b) => {
    if (!a.price) return 1; if (!b.price) return -1;
    if (sortBy === "rating") return b.rating - a.rating;
    if (sortBy === "name") return a.name.localeCompare(b.name);
    return 0;
  });

  const suggestedEndpoints = selectedFeatures.flatMap((f) => (FEATURE_ENDPOINT_MAP[f] || []).filter((e) => !endpoints.some((ex) => ex.path === e.path)));
  const visibleEndpoints = methodFilter ? endpoints.filter((e) => e.method === methodFilter) : endpoints;

  return (
    <div style={{ fontFamily: "'Inter', system-ui, sans-serif", background: "#F4F6F5", backgroundImage: "linear-gradient(#DDE4E2 0.5px, transparent 0.5px), linear-gradient(90deg, #DDE4E2 0.5px, transparent 0.5px)", backgroundSize: "24px 24px", color: C.ink, minHeight: "100%", padding: "28px 24px 0" }}>
      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap');
        .dp-mono { font-family: 'JetBrains Mono', monospace; }
        .dp-display { font-family: 'Space Grotesk', sans-serif; }
        .dp-card { transition: transform .12s ease, border-color .12s ease; }
        .dp-card:hover { transform: translateY(-2px); border-color: #16211D; }
        .dp-input { font-family: 'Inter', sans-serif; border: 1px solid #C7D0CC; background: #fff; }
        .dp-input:focus { outline: none; border-color: #E06B2C; }
        .dp-pick { cursor: pointer; }
      `}</style>

      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-end", borderBottom: `1.5px solid ${C.ink}`, paddingBottom: 14, marginBottom: 22 }}>
        <div>
          <div className="dp-mono" style={{ fontSize: 12, letterSpacing: 1, color: C.muted }}>DEVPLAN — PROJE ŞEMASI</div>
          <div className="dp-display" style={{ fontSize: 26, fontWeight: 700 }}>Kahve Dükkanı Sadakat Uygulaması</div>
        </div>
        <div className="dp-mono" style={{ textAlign: "right", fontSize: 12, color: C.muted }}>
          <div>SAYFA {activeModule ? String(activeModule.n).padStart(2, "0") : "00"} / 12</div>
          <div>{pct}% TAMAMLANDI</div>
        </div>
      </div>
      <div style={{ height: 6, background: "#E3E8E6", border: `0.5px solid ${C.border}`, marginBottom: 26 }}>
        <div style={{ height: "100%", width: `${pct}%`, background: C.accent, transition: "width .3s ease" }} />
      </div>

      {!activeModule && (
        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(220px, 1fr))", gap: 14, paddingBottom: 32 }}>
          {MODULES.map((m) => {
            const isDone = !!done[m.id];
            return (
              <button key={m.id} onClick={() => setActive(m.id)} className="dp-card"
                style={{ textAlign: "left", background: "#fff", border: `1px solid ${isDone ? C.green : C.border}`, padding: "16px 16px 14px", cursor: "pointer" }}>
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start" }}>
                  <m.Icon size={20} color={C.ink} strokeWidth={1.6} />
                  <span className="dp-mono" style={{ fontSize: 11, color: "#96A19C" }}>{String(m.n).padStart(2, "0")}</span>
                </div>
                <div className="dp-display" style={{ fontSize: 15, fontWeight: 700, marginTop: 10 }}>{m.title}</div>
                <div style={{ fontSize: 12.5, color: C.muted, marginTop: 3, lineHeight: 1.4 }}>{m.sub}</div>
                <div className="dp-mono" style={{ marginTop: 12, display: "inline-block", fontSize: 10.5, padding: "3px 8px", background: isDone ? C.greenBg : C.faint, color: isDone ? C.green : C.muted }}>
                  {isDone ? "TAMAMLANDI" : "BAŞLANMADI"}
                </div>
              </button>
            );
          })}
        </div>
      )}

      {activeModule && (
        <div style={{ paddingBottom: 40, maxWidth: 700 }}>
          <button onClick={() => setActive(null)} className="dp-mono" style={{ display: "flex", alignItems: "center", gap: 6, fontSize: 12, color: C.muted, background: "none", border: "none", cursor: "pointer", marginBottom: 18, padding: 0 }}>
            <ArrowLeft size={14} /> PANOYA DÖN
          </button>
          <div className="dp-display" style={{ fontSize: 20, fontWeight: 700, marginBottom: 4 }}>{activeModule.title}</div>

          {/* 1. IDEA */}
          {active === "idea" && (
            <div>
              <p style={{ fontSize: 13, color: C.muted, margin: "6px 0 10px" }}>Hazır şablonla başla veya sıfırdan doldur — her kutuya birden fazla madde ekleyebilirsin.</p>
              <div style={{ marginBottom: 14 }}>
                {Object.keys(IDEA_TEMPLATES).map((t) => <Chip key={t} onClick={() => setCanvas(IDEA_TEMPLATES[t])}>{t} şablonu uygula</Chip>)}
              </div>
              <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 10, marginBottom: 14 }}>
                {CANVAS_FIELDS.map(([k, label]) => (
                  <MultiList key={k} label={label} items={canvas[k]} setItems={(v) => setCanvas((c) => ({ ...c, [k]: v }))} placeholder="Ekle..." />
                ))}
              </div>
              <div style={{ display: "flex", gap: 6, marginBottom: 10, alignItems: "center" }}>
                <span className="dp-mono" style={{ fontSize: 11, color: C.muted }}>PITCH UZUNLUĞU:</span>
                {["Kısa", "Orta", "Uzun"].map((t) => <Chip key={t} active={tone === t} onClick={() => setTone(t)}>{t}</Chip>)}
              </div>
              <AiBtn onClick={() => setPitch(genPitch(canvas, tone))} disabled={!Object.values(canvas).some((v) => v.length)}>AI PITCH GENERATOR</AiBtn>
              {pitch && (
                <Panel label="Üretilen pitch" style={{ marginTop: 12 }}>
                  <p style={{ fontSize: 13.5, lineHeight: 1.6, margin: 0 }}>{pitch}</p>
                  <div style={{ marginTop: 10 }}><TinyBtn onClick={() => setPdfReady(true)}>PDF OLARAK DIŞA AKTAR</TinyBtn></div>
                  {pdfReady && <div className="dp-mono" style={{ fontSize: 11, color: C.green, marginTop: 8 }}>✓ pitch-deck.pdf hazır</div>}
                </Panel>
              )}
              <CompleteButton enabled={!!pitch && pdfReady} isDone={done.idea} onClick={() => markDone("idea")} />
            </div>
          )}

          {/* 2. RESEARCH */}
          {active === "research" && (
            <div>
              <Panel label="Sektör" style={{ marginBottom: 10 }}>
                {Object.keys(RESEARCH_SUGGESTIONS).map((c) => <Chip key={c} active={researchCat === c} onClick={() => setResearchCat(c)}>{c}</Chip>)}
              </Panel>
              <div style={{ marginBottom: 8 }}>
                {(RESEARCH_SUGGESTIONS[researchCat] || []).map((s) => <Chip key={s} onClick={() => setCompDraft(s)} active={compDraft === s}>{s}</Chip>)}
              </div>
              <div style={{ display: "flex", gap: 8, marginBottom: 14 }}>
                <input className="dp-input" placeholder="Rakip adı yaz veya yukarıdan seç" value={compDraft} onChange={(e) => setCompDraft(e.target.value)} style={{ flex: 1, padding: 8, fontSize: 13 }} />
                <TinyBtn onClick={() => { if (compDraft.trim()) { setCompetitors([...competitors, { name: compDraft, price: "", feature: "", pro: "", con: "", rating: null, trend: null }]); setCompDraft(""); } }}><Plus size={12} /> EKLE</TinyBtn>
              </div>
              {competitors.some((c) => c.price) && (
                <div style={{ display: "flex", gap: 6, alignItems: "center", marginBottom: 10 }}>
                  <span className="dp-mono" style={{ fontSize: 11, color: C.muted }}>SIRALA:</span>
                  {["rating", "name"].map((s) => <Chip key={s} active={sortBy === s} onClick={() => setSortBy(s)}>{s === "rating" ? "Puana göre" : "İsme göre"}</Chip>)}
                </div>
              )}
              {sortedCompetitors.map((c, i) => (
                <Panel key={i} style={{ marginBottom: 8 }}>
                  <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: c.price ? 8 : 0 }}>
                    <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                      <b style={{ fontSize: 13.5 }}>{c.name}</b>
                      {c.rating && <span className="dp-mono" style={{ fontSize: 11, color: C.accent }}>★ {c.rating}</span>}
                      {c.trend && <span className="dp-mono" style={{ fontSize: 10, padding: "2px 6px", background: c.trend === "Yükselişte" ? C.greenBg : C.faint, color: c.trend === "Yükselişte" ? C.green : C.muted }}>{c.trend.toUpperCase()}</span>}
                    </div>
                    <TinyBtn danger onClick={() => removeAt(competitors, setCompetitors, i)}><Trash2 size={12} /></TinyBtn>
                  </div>
                  {c.price && (
                    <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 6, fontSize: 12 }}>
                      <div><span className="dp-mono" style={{ color: "#96A19C" }}>FİYAT </span>{c.price}</div>
                      <div><span className="dp-mono" style={{ color: "#96A19C" }}>ÖZELLİK </span>{c.feature}</div>
                      <div><span className="dp-mono" style={{ color: C.green }}>AVANTAJ </span>{c.pro}</div>
                      <div><span className="dp-mono" style={{ color: "#D64545" }}>DEZAVANTAJ </span>{c.con}</div>
                    </div>
                  )}
                </Panel>
              ))}
              <AiBtn disabled={!competitors.some((c) => !c.price)} onClick={() => setCompetitors(competitors.map((c) => (c.price ? c : { ...c, ...genCompetitor(c.name) })))}>AI İLE ANALİZ ET (FİYAT, PUAN, TREND)</AiBtn>
              <CompleteButton enabled={competitors.filter((c) => c.price).length >= 2} isDone={done.research} onClick={() => markDone("research")} />
            </div>
          )}

          {/* 3. REQUIREMENTS */}
          {active === "requirements" && (
            <div>
              <Panel label="Proje tipi (12 sektör)" style={{ marginBottom: 12 }}>
                {Object.keys(FEATURE_SETS).map((t) => <Chip key={t} active={projectType === t} onClick={() => { setProjectType(t); setFeatures({}); setReqTags({}); }}>{t}</Chip>)}
              </Panel>
              <Panel label="Önerilen teknoloji stack'i" style={{ marginBottom: 12, background: C.blueBg, border: `1px solid ${C.blue}` }}>
                <p style={{ fontSize: 12.5, margin: "0 0 8px", color: C.blue }}>{STACK_RECS[projectType]}</p>
                <TinyBtn onClick={() => {
                  const [fe, be, db, pay] = STACK_RECS[projectType].split(" + ");
                  setStack((s) => ({ ...s, Frontend: fe.includes("Next.js") ? "React (Next.js)" : s.Frontend, Backend: be?.includes("NestJS") ? "Node.js (NestJS)" : s.Backend, Veritabanı: db?.includes("PostgreSQL") ? "PostgreSQL" : s.Veritabanı }));
                }}>BU STACK'İ TEKNOLOJİ MODÜLÜNE UYGULA</TinyBtn>
              </Panel>
              <Panel label="Özellik seç (zorunlu/opsiyonel işaretle)" style={{ marginBottom: 12 }}>
                {FEATURE_SETS[projectType].map((f) => (
                  <div key={f} style={{ display: "flex", alignItems: "center", gap: 8, padding: "4px 0" }}>
                    <label style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 13, flex: 1, cursor: "pointer" }}>
                      <input type="checkbox" checked={!!features[f]} onChange={() => setFeatures((s) => ({ ...s, [f]: !s[f] }))} />{f}
                    </label>
                    {features[f] && <span onClick={() => setReqTags((t) => ({ ...t, [f]: t[f] === "Zorunlu" ? "Opsiyonel" : "Zorunlu" }))} className="dp-mono"
                      style={{ fontSize: 10, cursor: "pointer", padding: "2px 6px", background: reqTags[f] === "Opsiyonel" ? C.faint : C.greenBg, color: reqTags[f] === "Opsiyonel" ? C.muted : C.green }}>
                      {reqTags[f] || "Zorunlu"}
                    </span>}
                  </div>
                ))}
                <input className="dp-input" placeholder="Kullanıcı tipi (örn: müşteri)" value={persona} onChange={(e) => setPersona(e.target.value)} style={{ marginTop: 8, padding: 7, fontSize: 12.5, width: "100%" }} />
              </Panel>
              <AiBtn disabled={selectedFeatures.length === 0} onClick={() => setStories(selectedFeatures.map((f) => genStory(persona, f)))}>AI İLE USER STORY OLUŞTUR</AiBtn>
              {stories.length > 0 && (
                <Panel style={{ marginTop: 12 }}>
                  {stories.map((s, i) => (
                    <div key={i} style={{ paddingBottom: 8, marginBottom: 8, borderBottom: i < stories.length - 1 ? `0.5px solid ${C.border}` : "none" }}>
                      <p style={{ fontSize: 13, margin: 0 }}>{s.text}</p>
                      <p style={{ fontSize: 11.5, margin: "3px 0 0", color: C.muted }}>{s.criteria}</p>
                    </div>
                  ))}
                </Panel>
              )}
              <CompleteButton enabled={stories.length >= 2} isDone={done.requirements} onClick={() => markDone("requirements")} />
            </div>
          )}

          {/* 4. MVP */}
          {active === "mvp" && (
            <div>
              <div style={{ display: "flex", gap: 8, marginBottom: 12, flexWrap: "wrap" }}>
                <input className="dp-input" placeholder="Örn: Login" value={mvpDraft} onChange={(e) => setMvpDraft(e.target.value)} style={{ flex: 1, padding: 8, fontSize: 13.5 }} />
                <TinyBtn onClick={() => { if (mvpDraft.trim()) { setMvpItems([...mvpItems, { text: mvpDraft, cat: null }]); setMvpDraft(""); } }}><Plus size={12} /> EKLE</TinyBtn>
                <TinyBtn onClick={() => setMvpItems([...mvpItems, ...selectedFeatures.filter((f) => !mvpItems.some((it) => it.text === f)).map((f) => ({ text: f, cat: reqTags[f] === "Zorunlu" ? "Must" : null }))])}><RefreshCw size={11} /> GEREKSİNİMLERDEN GETİR</TinyBtn>
                <AiBtn disabled={!mvpItems.some((it) => !it.cat)} onClick={() => setMvpItems(mvpItems.map((it) => it.cat ? it : { ...it, cat: guessCategory(it.text) }))}>AI RECOMMENDATION</AiBtn>
              </div>
              <div style={{ display: "grid", gridTemplateColumns: "repeat(4, 1fr)", gap: 8 }}>
                {["Must", "Should", "Could", "Won't"].map((cat) => (
                  <div key={cat} style={{ border: `1px solid ${C.border}`, minHeight: 90, padding: 8 }}>
                    <div className="dp-mono" style={{ fontSize: 10.5, color: C.muted, marginBottom: 6 }}>{cat.toUpperCase()}</div>
                    {mvpItems.filter((it) => it.cat === cat).map((it, i) => <div key={i} style={{ fontSize: 11.5, background: C.faint, padding: "4px 6px", marginBottom: 4 }}>{it.text}</div>)}
                  </div>
                ))}
              </div>
              <div style={{ marginTop: 12 }}>
                {mvpItems.filter((it) => !it.cat).map((it, i) => (
                  <div key={i} style={{ display: "flex", alignItems: "center", gap: 6, marginBottom: 6, fontSize: 12.5 }}>
                    <span style={{ flex: 1 }}>{it.text}</span>
                    {["Must", "Should", "Could", "Won't"].map((cat) => <TinyBtn key={cat} onClick={() => setMvpItems(mvpItems.map((x) => x === it ? { ...x, cat } : x))}>{cat}</TinyBtn>)}
                  </div>
                ))}
              </div>
              <CompleteButton enabled={mvpItems.filter((it) => it.cat).length >= 4} isDone={done.mvp} onClick={() => markDone("mvp")} />
            </div>
          )}

          {/* 5. DESIGN */}
          {active === "design" && (
            <div>
              <Panel label="Figma linki yükle" style={{ marginBottom: 12 }}>
                <input className="dp-input" placeholder="https://figma.com/..." value={figmaLink} onChange={(e) => setFigmaLink(e.target.value)} style={{ width: "100%", padding: 8, fontSize: 13.5 }} />
              </Panel>
              <Panel label="İhtiyacın olan sayfaları seç" style={{ marginBottom: 12 }}>
                {PAGE_OPTIONS.map((p) => <Chip key={p} active={!!pages[p]} onClick={() => setPages((s) => ({ ...s, [p]: !s[p] }))}>{p}</Chip>)}
              </Panel>
              <AiBtn disabled={!figmaLink.trim() || !Object.values(pages).some(Boolean)} onClick={() => setDesignOut({ pages: Object.keys(pages).filter((p) => pages[p]), db: "users, products, orders, order_items tabloları — 1-e-çok ilişkiler", api: "İstemci → API Gateway → İş mantığı servisleri → Veritabanı", arch: "Frontend (SPA/SSR) → Backend API → Veritabanı, ödeme ve kimlik servisleriyle birlikte" })}>AI İLE ANALİZ ET</AiBtn>
              {designOut && (
                <>
                  <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10, marginTop: 12 }}>
                    <Panel label="Database ER"><p style={{ fontSize: 12.5, margin: 0 }}>{designOut.db}</p></Panel>
                    <Panel label="API flow"><p style={{ fontSize: 12.5, margin: 0 }}>{designOut.api}</p></Panel>
                    <Panel label="Architecture" style={{ gridColumn: "1 / -1" }}><p style={{ fontSize: 12.5, margin: 0 }}>{designOut.arch}</p></Panel>
                  </div>
                  <Panel label="Wireframe önizleme (düşük çözünürlük)" style={{ marginTop: 10 }}>
                    <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(100px, 1fr))", gap: 8 }}>
                      {designOut.pages.map((p) => (
                        <div key={p} style={{ border: `1px dashed ${C.border}`, height: 70, padding: 6, display: "flex", flexDirection: "column", gap: 3 }}>
                          <div style={{ height: 6, background: C.border, width: "60%" }} />
                          <div style={{ height: 4, background: C.faint, width: "90%" }} />
                          <div style={{ height: 4, background: C.faint, width: "80%" }} />
                          <div style={{ fontSize: 9.5, color: C.muted, marginTop: "auto" }}>{p}</div>
                        </div>
                      ))}
                    </div>
                  </Panel>
                </>
              )}
              <CompleteButton enabled={!!designOut} isDone={done.design} onClick={() => markDone("design")} />
            </div>
          )}

          {/* 6. STACK */}
          {active === "stack" && (
            <div>
              {Object.entries(STACK_OPTIONS).map(([cat, opts]) => (
                <Panel key={cat} label={cat} style={{ marginBottom: 12 }}>
                  <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                    {opts.map((opt) => <Chip key={opt} active={stack[cat] === opt} onClick={() => setStack((s) => ({ ...s, [cat]: opt }))}>{opt}</Chip>)}
                  </div>
                </Panel>
              ))}
              {STACK_HINTS(stack).length > 0 && (
                <Panel style={{ background: C.blueBg, border: `1px solid ${C.blue}` }}>
                  {STACK_HINTS(stack).map((h, i) => <p key={i} style={{ fontSize: 12, color: C.blue, margin: "2px 0" }}>💡 {h}</p>)}
                </Panel>
              )}
              <CompleteButton enabled={["Frontend", "Backend", "Veritabanı", "Hosting"].every((c) => stack[c])} isDone={done.stack} onClick={() => markDone("stack")} />
            </div>
          )}

          {/* 7. API */}
          {active === "api" && (
            <div>
              <div style={{ display: "flex", gap: 8, marginBottom: 12 }}>
                <input className="dp-input" placeholder="Örn: E-ticaret ürün API'si" value={apiDesc} onChange={(e) => setApiDesc(e.target.value)} style={{ flex: 1, padding: 8, fontSize: 13.5 }} />
                <AiBtn disabled={!apiDesc.trim()} onClick={() => setEndpoints(genEndpoints(guessResource(apiDesc)))}>OTOMATİK OLUŞTUR (CRUD)</AiBtn>
              </div>
              {suggestedEndpoints.length > 0 && (
                <Panel label="Gereksinimlerine göre önerilen ek endpoint'ler" style={{ marginBottom: 12, background: C.blueBg, border: `1px solid ${C.blue}` }}>
                  {suggestedEndpoints.map((e, i) => (
                    <div key={i} style={{ display: "flex", justifyContent: "space-between", alignItems: "center", padding: "4px 0", fontSize: 12 }}>
                      <span className="dp-mono">{e.method} {e.path} — {e.desc}</span>
                      <TinyBtn onClick={() => setEndpoints([...endpoints, e])}><Plus size={11} /> EKLE</TinyBtn>
                    </div>
                  ))}
                </Panel>
              )}
              {endpoints.length > 0 && <div style={{ marginBottom: 8 }}>
                {["GET", "POST", "PUT", "DELETE"].map((m) => <Chip key={m} active={methodFilter === m} onClick={() => setMethodFilter(methodFilter === m ? null : m)}>{m}</Chip>)}
              </div>}
              {visibleEndpoints.map((ep, i) => (
                <div key={i} style={{ display: "flex", alignItems: "center", gap: 10, fontSize: 12.5, padding: "6px 0", borderTop: `0.5px solid ${C.border}` }}>
                  <span className="dp-mono" style={{ width: 55, color: C.accent }}>{ep.method}</span>
                  <span className="dp-mono" style={{ flex: 1 }}>{ep.path}</span>
                  <span style={{ color: C.muted }}>{ep.desc}</span>
                  <TinyBtn danger onClick={() => removeAt(endpoints, setEndpoints, endpoints.indexOf(ep))}><Trash2 size={12} /></TinyBtn>
                </div>
              ))}
              {endpoints.length > 0 && <Panel label="swagger.yaml" style={{ marginTop: 12 }}><pre className="dp-mono" style={{ fontSize: 11, margin: 0, whiteSpace: "pre-wrap" }}>{genSwagger(endpoints)}</pre></Panel>}
              <CompleteButton enabled={endpoints.length >= 3} isDone={done.api} onClick={() => markDone("api")} />
            </div>
          )}

          {/* 8. FOLDERS */}
          {active === "folders" && (
            <div>
              <AiBtn disabled={!stack.Frontend || !stack.Backend} onClick={() => setFolders(genFolders(stack.Frontend, stack.Backend))}>AI İLE KLASÖR YAPISI ÖNER</AiBtn>
              {(!stack.Frontend || !stack.Backend) && <div style={{ fontSize: 11.5, color: C.muted, marginTop: 6 }}>Önce Teknoloji seçimi modülünde Frontend + Backend seç.</div>}
              <div style={{ display: "grid", gridTemplateColumns: "1fr 90px auto", gap: 6, margin: "12px 0" }}>
                <input className="dp-input" placeholder="klasör-veya-dosya-adı" value={folderDraft.name} onChange={(e) => setFolderDraft((d) => ({ ...d, name: e.target.value }))} style={{ padding: 7, fontSize: 12.5 }} />
                <select className="dp-input" value={folderDraft.depth} onChange={(e) => setFolderDraft((d) => ({ ...d, depth: Number(e.target.value) }))} style={{ padding: 7, fontSize: 12.5 }}>
                  {[0, 1, 2, 3].map((d) => <option key={d} value={d}>Seviye {d}</option>)}
                </select>
                <TinyBtn onClick={() => { if (folderDraft.name.trim()) { setFolders([...folders, folderDraft]); setFolderDraft({ name: "", depth: 0 }); } }}><Plus size={12} /></TinyBtn>
              </div>
              <Panel>
                {folders.map((f, i) => (
                  <div key={i} className="dp-mono" style={{ fontSize: 12.5, padding: "3px 0 3px " + f.depth * 18, display: "flex", justifyContent: "space-between" }}>
                    <span>{"— ".repeat(f.depth)}{f.name}</span><TinyBtn danger onClick={() => removeAt(folders, setFolders, i)}><Trash2 size={11} /></TinyBtn>
                  </div>
                ))}
                {folders.length === 0 && <div style={{ fontSize: 12.5, color: C.muted }}>Henüz düğüm eklenmedi.</div>}
              </Panel>
              <CompleteButton enabled={folders.length >= 4} isDone={done.folders} onClick={() => markDone("folders")} />
            </div>
          )}

          {/* 9. TASKS */}
          {active === "tasks" && (
            <div>
              <div style={{ display: "flex", gap: 8, marginBottom: 12, flexWrap: "wrap" }}>
                <input className="dp-input" placeholder="Yeni görev" value={taskDraft} onChange={(e) => setTaskDraft(e.target.value)} style={{ flex: 1, padding: 8, fontSize: 13.5 }} />
                <TinyBtn onClick={() => { if (taskDraft.trim()) { setTasks((t) => ({ ...t, todo: [...t.todo, { text: taskDraft, pr: "Orta" }] })); setTaskDraft(""); } }}><Plus size={12} /> EKLE</TinyBtn>
                <AiBtn onClick={() => {
                  const must = mvpItems.filter((it) => it.cat === "Must").map((it) => ({ text: `Sprint 1: ${it.text}`, pr: "Yüksek" }));
                  const should = mvpItems.filter((it) => it.cat === "Should").map((it) => ({ text: `Sprint 2: ${it.text}`, pr: "Orta" }));
                  const fallback = must.length === 0 ? [{ text: "Sprint 1: Kimlik doğrulama kurulumu", pr: "Yüksek" }, { text: "Sprint 1: Veritabanı şeması", pr: "Yüksek" }] : [];
                  setTasks((t) => ({ ...t, todo: [...t.todo, ...fallback, ...must, ...should, { text: "Sprint 3: Test ve lansmana hazırlık", pr: "Düşük" }] }));
                }}>AI İLE SPRINT PLANI OLUŞTUR (MVP'DEN)</AiBtn>
              </div>
              <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 8 }}>
                {["todo", "doing", "done"].map((col, ci) => (
                  <div key={col} style={{ border: `1px solid ${C.border}`, minHeight: 100, padding: 8 }}>
                    <div className="dp-mono" style={{ fontSize: 10.5, color: C.muted, marginBottom: 6 }}>{col.toUpperCase()}</div>
                    {tasks[col].map((t, i) => (
                      <div key={i} style={{ fontSize: 11, background: C.faint, padding: "5px 6px", marginBottom: 4, display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                        <span>{t.text}</span>
                        <span style={{ display: "flex", alignItems: "center", gap: 5 }}>
                          <span onClick={() => { const order = ["Düşük", "Orta", "Yüksek"]; const next = order[(order.indexOf(t.pr) + 1) % 3]; setTasks((prev) => ({ ...prev, [col]: prev[col].map((x) => x === t ? { ...x, pr: next } : x) })); }}
                            className="dp-mono" style={{ cursor: "pointer", fontSize: 9.5, padding: "1px 5px", background: t.pr === "Yüksek" ? "#FBE4E4" : t.pr === "Orta" ? "#FDEFE0" : "#E9EEEC", color: t.pr === "Yüksek" ? "#D64545" : t.pr === "Orta" ? C.accent : C.muted }}>{t.pr}</span>
                          {ci < 2 && <span onClick={() => { const next = ci === 0 ? "doing" : "done"; setTasks((prev) => ({ ...prev, [col]: prev[col].filter((x) => x !== t), [next]: [...prev[next], t] })); }} style={{ cursor: "pointer", color: C.accent }}><ArrowRightCircle size={13} /></span>}
                        </span>
                      </div>
                    ))}
                  </div>
                ))}
              </div>
              <CompleteButton enabled={tasks.done.length >= 1 && (tasks.todo.length + tasks.doing.length + tasks.done.length) >= 3} isDone={done.tasks} onClick={() => markDone("tasks")} />
            </div>
          )}

          {/* 10. ENV */}
          {active === "env" && (
            <div>
              <Panel label="Yazılım dilleri" style={{ marginBottom: 10 }}>
                {["Node.js", "Python", "Go", "PHP", "Ruby", "Java"].map((l) => <Chip key={l} active={langs.includes(l)} onClick={() => setLangs((ls) => ls.includes(l) ? ls.filter((x) => x !== l) : [...ls, l])}>{l}</Chip>)}
              </Panel>
              <Panel label="Paket yöneticisi" style={{ marginBottom: 10 }}>
                {["npm", "pnpm", "yarn", "pip", "poetry"].map((p) => <Chip key={p} active={pm === p} onClick={() => setPm(p)}>{p}</Chip>)}
              </Panel>
              <Panel label="Dağıtım hedefi" style={{ marginBottom: 12 }}>
                {["Docker", "Vercel", "Railway", "Render", "AWS"].map((d) => <Chip key={d} active={deploy.includes(d)} onClick={() => setDeploy((ds) => ds.includes(d) ? ds.filter((x) => x !== d) : [...ds, d])}>{d}</Chip>)}
              </Panel>
              <AiBtn disabled={langs.length === 0} onClick={() => setEnvOut(genEnvFiles(langs, pm, deploy))}>ÜRET</AiBtn>
              {envOut && (
                <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10, marginTop: 12 }}>
                  <Panel label=".gitignore"><pre className="dp-mono" style={{ fontSize: 10.5, margin: 0, whiteSpace: "pre-wrap" }}>{envOut.gitignore}</pre></Panel>
                  <Panel label="README.md"><pre className="dp-mono" style={{ fontSize: 10.5, margin: 0, whiteSpace: "pre-wrap" }}>{envOut.readme}</pre></Panel>
                  <Panel label="docker-compose.yml"><pre className="dp-mono" style={{ fontSize: 10.5, margin: 0, whiteSpace: "pre-wrap" }}>{envOut.docker}</pre></Panel>
                  <Panel label=".env.example"><pre className="dp-mono" style={{ fontSize: 10.5, margin: 0, whiteSpace: "pre-wrap" }}>{envOut.envExample}</pre></Panel>
                </div>
              )}
              <CompleteButton enabled={!!envOut} isDone={done.env} onClick={() => markDone("env")} />
            </div>
          )}

          {/* 11. PROMPT */}
          {active === "prompt" && (
            <div>
              <Panel label="Hedef AI aracı" style={{ marginBottom: 12 }}>
                {Object.keys(TARGET_TOOLS).map((t) => <Chip key={t} active={targetTool === t} onClick={() => setTargetTool(t)}>{t}</Chip>)}
              </Panel>
              {Object.entries(RULE_GROUPS).map(([group, rules]) => (
                <Panel key={group} label={group} style={{ marginBottom: 10 }}>
                  {rules.map((r) => (
                    <label key={r} style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 12.5, padding: "3px 0", cursor: "pointer" }}>
                      <input type="checkbox" checked={!!promptRules[r]} onChange={() => setPromptRules((c) => ({ ...c, [r]: !c[r] }))} />{r}
                    </label>
                  ))}
                </Panel>
              ))}
              <Panel label="Özel kural ekle" style={{ marginBottom: 12 }}>
                {customRules.map((r, i) => <div key={i} style={{ fontSize: 12.5, display: "flex", justifyContent: "space-between", padding: "3px 0" }}><span>{r}</span><span onClick={() => removeAt(customRules, setCustomRules, i)} style={{ cursor: "pointer", color: C.muted }}><X size={12} /></span></div>)}
                <div style={{ display: "flex", gap: 6, marginTop: 6 }}>
                  <input className="dp-input" placeholder="Kendi kuralını yaz..." value={customRule} onChange={(e) => setCustomRule(e.target.value)} style={{ flex: 1, padding: 6, fontSize: 12 }} />
                  <TinyBtn onClick={() => { if (customRule.trim()) { setCustomRules([...customRules, customRule]); setCustomRule(""); } }}><Plus size={11} /></TinyBtn>
                </div>
              </Panel>
              <AiBtn disabled={Object.values(promptRules).filter(Boolean).length === 0 && customRules.length === 0} onClick={() => {
                const active = [...Object.keys(promptRules).filter((r) => promptRules[r]), ...customRules];
                const note = stack.Backend && stack.Veritabanı ? `${stack.Backend} ve ${stack.Veritabanı} kullan (Teknoloji modülünden)` : null;
                setPromptOut(genPromptFiles(active, note));
              }}>ÜRET</AiBtn>
              {promptOut && (
                <Panel label={TARGET_TOOLS[targetTool]} style={{ marginTop: 12 }}>
                  <pre className="dp-mono" style={{ fontSize: 11.5, margin: 0, whiteSpace: "pre-wrap" }}>{targetTool === "Cursor" ? promptOut.cursorRules : targetTool === "GitHub Copilot" ? promptOut.aiInstructions : promptOut.claudeMd}</pre>
                </Panel>
              )}
              <CompleteButton enabled={!!promptOut} isDone={done.prompt} onClick={() => markDone("prompt")} />
            </div>
          )}

          {/* 12. AI TOOLS */}
          {active === "resources" && (
            <div>
              <Panel label="Proje tipine göre öneri al" style={{ marginBottom: 12 }}>
                {Object.keys(TOOL_RECS).map((t) => <Chip key={t} active={toolType === t} onClick={() => { setToolType(t); setToolCat(null); }}>{t}</Chip>)}
              </Panel>
              {toolType && (
                <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(150px, 1fr))", gap: 10, marginBottom: 16 }}>
                  {TOOL_RECS[toolType].map((name) => {
                    const tool = TOOL_DB.find((t) => t.name === name);
                    return <Panel key={name}><div className="dp-display" style={{ fontWeight: 700, fontSize: 13 }}>{name}</div><div style={{ fontSize: 11.5, color: C.muted, marginTop: 3 }}>{tool?.desc}</div></Panel>;
                  })}
                </div>
              )}
              <Panel label="Ya da kategoriye göre tüm araçları gez" style={{ marginBottom: 12 }}>
                {TOOL_CATS.map((c) => <Chip key={c} active={toolCat === c} onClick={() => { setToolCat(c); setToolType(null); }}>{c}</Chip>)}
              </Panel>
              {toolCat && (
                <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(150px, 1fr))", gap: 10, marginBottom: 16 }}>
                  {TOOL_DB.filter((t) => t.cat === toolCat).map((t) => (
                    <Panel key={t.name}><div className="dp-display" style={{ fontWeight: 700, fontSize: 13 }}>{t.name}</div><div style={{ fontSize: 11.5, color: C.muted, marginTop: 3 }}>{t.desc}</div></Panel>
                  ))}
                </div>
              )}
              <Panel label="Öğrenme kaynakları">
                {LEARN_LINKS.map((l) => <div key={l.label} style={{ fontSize: 13, padding: "4px 0" }}><a href={l.url} style={{ color: C.accent }}>{l.label}</a></div>)}
              </Panel>
              <CompleteButton enabled={!!toolType} isDone={done.resources} onClick={() => markDone("resources")} />
            </div>
          )}
        </div>
      )}
    </div>
  );
}
