# DevPlan — Fikirden Koda

Fikirden koda tam kapsamlı proje hazırlık platformu. `prototype.jsx` ürünün
tüm 12 modülünü tek dosyada mockup olarak gösteriyor; bu repo o mockup'ı gerçek,
çalışan bir uygulamaya dönüştürüyor.

## Stack

| Katman | Seçim |
|---|---|
| Frontend | Next.js 16 (App Router) + Tailwind CSS v4 + next-intl (i18n) |
| Backend | Laravel 13 (PHP) + Sanctum (SPA cookie-tabanlı auth) |
| Veritabanı | PostgreSQL 18 |
| AI entegrasyonu | Laravel backend → Claude API (`ANTHROPIC_API_KEY` sadece backend'de tutulur, henüz boş) |
| Dil desteği | Türkçe (varsayılan) + İngilizce — hem frontend UI hem backend hata mesajları |
| Yapı | Monorepo (`frontend/` + `backend/` ayrı klasörler, tek git reposu) |

## Klasör yapısı

```
DevPlan/
├── prototype.jsx              # orijinal tek-dosyalık mockup (referans)
├── README.md                  # bu dosya
├── .gitignore
├── docs/
│   └── backend-setup.md       # kurulum geçmişi + PostgreSQL'i her seferinde başlatma
├── frontend/                  # Next.js + Tailwind + next-intl — ÇALIŞIYOR (localhost:3000)
│   ├── messages/
│   │   ├── tr.json             # tüm Türkçe metinler (varsayılan dil)
│   │   └── en.json             # tüm İngilizce metinler
│   └── src/
│       ├── proxy.js             # next-intl locale routing (Next.js 16'da "middleware" → "proxy")
│       ├── i18n/                # routing.js, navigation.js, request.js (next-intl config)
│       ├── app/[locale]/
│       │   ├── layout.js          # kök layout, fontlar, Auth/Project Provider, LanguageSwitcher
│       │   ├── page.js            # Dashboard — proje yoksa oluşturma formu, varsa 12 modül kartı
│       │   ├── login/page.js       # giriş formu
│       │   ├── register/page.js    # kayıt formu
│       │   └── modules/[id]/page.js  # modül detay route'u — registry.js'den component çeker
│       ├── components/
│       │   ├── LanguageSwitcher.jsx   # TR/EN geçişi, mevcut sayfada kalarak
│       │   ├── ui/                    # Panel, Chip, TinyBtn, AiBtn, CompleteButton, MultiList
│       │   └── modules/
│       │       ├── registry.js         # slug → component eşlemesi (bkz. "Modül ekleme" altı)
│       │       └── IdeaModule.jsx       # Lean Canvas + AI pitch generator (gerçek backend'e bağlı)
│       └── lib/
│           ├── constants.js           # 12 modülün id/ikon tanımı (metin YOK — messages/*.json'da)
│           ├── api.js                 # fetch wrapper + Sanctum CSRF + X-Locale header
│           ├── auth-context.jsx        # user/login/register/logout
│           └── project-context.jsx     # aktif proje + modül durumu (backend'den, localStorage değil)
└── backend/                   # Laravel — ÇALIŞIYOR (localhost:8010)
    ├── app/Models/             # Project, Module, ModuleItem, Task, Subscription, User
    ├── app/Http/Controllers/   # Auth, Project, Module, ModuleItem, Ai
    ├── app/Http/Middleware/SetLocale.php  # X-Locale header'dan app()->setLocale()
    ├── app/Services/AnthropicService.php  # Claude API çağrısı (Http facade ile)
    ├── lang/{en,tr}/           # validation.php, auth.php, messages.php (özel hata mesajları)
    ├── database/migrations/    # users, projects, modules, module_items, tasks,
    │                           # subscriptions, personal_access_tokens (Sanctum)
    ├── routes/api.php          # /register /login /logout, projects, modules, modules.items, /ai/pitch
    └── config/cors.php         # FRONTEND_URL'e izin veren CORS ayarı (credentials: true)
```

## Çalıştırma

**Önce PostgreSQL'i başlat** (Windows servisi olarak kayıtlı değil, bkz.
`docs/backend-setup.md`):

```powershell
pg_ctl -D "D:\Program Files\PostgreSQL\18\data" -l "$env:TEMP\pg_startup.log" start
```

**Backend:**

```bash
cd backend
php artisan serve --port=8010
```

**Frontend:**

```bash
cd frontend
npm run dev
```

`http://localhost:3000` (Türkçe, varsayılan) veya `http://localhost:3000/en`
(İngilizce) — sağ üstteki TR/EN düğmesiyle aynı sayfada dil değiştirilebilir.
Kayıt ol, proje oluştur, dashboard'da 12 modül kartı görünür. Şu an sadece
**Fikir & Pitch** modülünün gerçek arayüzü var (Lean Canvas doldurma + AI
pitch üretimi); diğerleri "henüz taşınmadı" placeholder'ı gösteriyor ve
doğrudan tamamlandı işaretlenebiliyor.

**Önemli:** `ANTHROPIC_API_KEY` boş — AI pitch üretimi butonu şu an
`502 ANTHROPIC_API_KEY tanımlı değil / is not defined` hatası verecek
(hata mesajı bile aktif dile göre değişir). Gerçek bir anahtar
`backend/.env` içine eklenince çalışır.

## Dil desteği (i18n) nasıl çalışıyor

- **Frontend:** next-intl, URL locale-prefix'li (`/`, `/en`) — varsayılan
  Türkçe prefiksiz, İngilizce `/en` altında. Tüm metinler
  `frontend/messages/{tr,en}.json` içinde; hiçbir component'te hardcoded
  metin yok. Modül başlıkları/açıklamaları `Modules.<module_type>` anahtarı
  altında — `constants.js`'deki `id` alanı doğrudan bu anahtarla eşleşir.
- **Backend:** frontend her istekte `X-Locale: tr|en` header'ı gönderiyor
  (`src/lib/api.js`), `SetLocale` middleware bunu okuyup `app()->setLocale()`
  çağırıyor. Laravel'in kendi validation/auth mesajları (`lang/tr/*.php`) ve
  bizim özel mesajlarımız (`lang/{tr,en}/messages.php`) buna göre otomatik
  seçiliyor. AI pitch üretiminde ayrıca `locale` alanı body'de de gönderiliyor
  çünkü üretilen pitch metninin dili (Claude'a verilen prompt) `tone`
  (short/medium/long — dil-bağımsız enum) gibi ayrı bir mantıkla seçiliyor.

## Yeni bir modülün gerçek arayüzünü eklerken (kod dokunmadan genişleyen kısımlar)

1. `frontend/src/components/modules/<Isim>Module.jsx` dosyasını yaz.
2. `frontend/src/components/modules/registry.js`'e tek satır ekle:
   `research: ResearchModule,` gibi.
3. `frontend/messages/tr.json` ve `en.json`'a o modülün metinlerini ekle.
4. Routing, dashboard kartı, tamamlanma durumu, dil değişimi — hiçbiri
   değişmeden otomatik çalışır (`modules/[id]/page.js` slug'a göre
   registry'den component'i buluyor, bulamazsa placeholder gösteriyor).

## Neden 5433 / 8010 gibi standart olmayan portlar?

Bu makinede 5432 (Docker/WSL) ve 8000 (başka bir servis, muhtemelen WSL
mirrored networking üzerinden) zaten dolu çıktı — DevPlan'ın kendi
PostgreSQL ve Laravel süreçleriyle çakışmaması için 5433 ve 8010 seçildi.
Detaylar `docs/backend-setup.md` içinde.

## Sırada ne var

- `ANTHROPIC_API_KEY` girilip AI pitch üretiminin gerçek Claude çağrısıyla test edilmesi
- Kalan 11 modülün prototip'teki etkileşimli arayüzlerinin Next.js'e taşınması
  (Pazar Araştırması muhtemel sıradaki — Fikir modülünden sonra geliyor),
  her biri "Modül ekleme" adımlarını izleyerek
- Şifre sıfırlama / e-posta doğrulama gibi auth detayları (şu an yok, MVP kapsamı dışı)
- Gerçek bir tarayıcıda görsel responsive doğrulama (bu oturumda yalnızca
  Tailwind breakpoint'leri + curl/build ile doğrulandı, gerçek cihaz/viewport
  testi yapılmadı)
