# Backend Kurulumu — Tamamlandı (referans)

Bu doküman artık geçmişe dönük bir referans: Laravel + Sanctum + PostgreSQL
kurulumu tamamlandı. Bilgisayarı her yeniden başlattığında **PostgreSQL'i
elle başlatman gerekiyor** çünkü Windows servisi olarak kayıtlı değil
(kurulum yarım kalmıştı, `initdb` elle çalıştırıldı — bkz. "Bilinen durum"
bölümü).

## Kurulum sırasında karşılaşılan ve çözülen sorunlar

1. **Composer kurulu değildi** → resmi installer script'i ile
   `C:\Users\user\AppData\Roaming\Composer\composer.phar` + `composer.bat`
   wrapper oluşturuldu, bu klasör User PATH'e eklendi.
2. **PostgreSQL kurulumu yarım kalmıştı** → veri dizini boştu, Windows servisi
   (`postgresql-x64-18`) hiç oluşmamıştı. `initdb` elle çalıştırılıp cluster
   başlatıldı.
3. **Port 5432 zaten Docker/WSL tarafından kullanılıyordu** (ilgisiz bir
   container/servis) → PostgreSQL **port 5433**'te çalışacak şekilde
   yapılandırıldı.
4. **Port 8000 de başka bir servis tarafından işgal edilmiş** (muhtemelen
   WSL mirrored networking üzerinden, `uvicorn` yanıtı alındı) → Laravel dev
   server **port 8010**'da çalışacak şekilde ayarlandı.
5. **PHP'de `pdo_pgsql`/`pgsql` eklentileri kapalıydı** → php.ini'de
   (`C:\Users\user\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_...\php.ini`)
   ilgili satırların başındaki `;` kaldırılarak etkinleştirildi.
6. **Türkçe sistem locale'i (`Turkish_Türkiye.1254`) initdb'yi bozuyordu**
   (non-ASCII karakter içeriyor) → veritabanı `--locale=C` ile oluşturuldu.
7. **Dış API çağrılarında (Groq/Anthropic) SSL hatası**: `cURL error 60:
   unable to get local issuer certificate` — WinGet'in PHP paketi bir CA
   sertifika paketiyle gelmiyor. `https://curl.se/ca/cacert.pem` indirilip
   PHP kurulum klasörüne kondu, `php.ini`'de `curl.cainfo` ve
   `openssl.cafile` bu dosyaya işaret edecek şekilde ayarlandı. **php.ini
   değişikliği ancak `php artisan serve` yeniden başlatılınca etkili olur.**

## Güncel bağlantı bilgileri

| Değer | Bilgi |
|---|---|
| PostgreSQL sürümü | 18.4, kurulum dizini `D:\Program Files\PostgreSQL\18` |
| Veri dizini | `D:\Program Files\PostgreSQL\18\data` |
| Port | **5433** (5432 değil — o başka bir şeye ait) |
| Superuser | `postgres` / `Ezgi2002` |
| Veritabanı | `devplan` |
| Laravel dev server portu | **8010** (8000 değil — o başka bir şeye ait) |
| Composer | `C:\Users\user\AppData\Roaming\Composer\composer.phar` (User PATH'te) |

## Her oturumda PostgreSQL'i başlatma

Servis olarak kayıtlı olmadığı için bilgisayar yeniden başlatıldığında ya da
PostgreSQL durduğunda şu komutla elle başlatılması gerekiyor:

```powershell
pg_ctl -D "D:\Program Files\PostgreSQL\18\data" -l "$env:TEMP\pg_startup.log" start
```

Durdurmak için:

```powershell
pg_ctl -D "D:\Program Files\PostgreSQL\18\data" stop
```

**Kalıcı çözüm (opsiyonel, ileride):** Yönetici olarak
`pg_ctl register -N postgresql-devplan -D "D:\Program Files\PostgreSQL\18\data"`
çalıştırılırsa Windows servisi olarak kaydedilip otomatik başlaması sağlanabilir.

## Backend'i çalıştırma

```bash
cd backend
composer install   # sadece ilk klonlamada
php artisan serve --port=8010
```

`http://localhost:8010/up` → "Application up" görünüyorsa backend ayakta demektir.

## Kurulan bileşenler

- Laravel 13.22 (`laravel/framework`)
- Laravel Sanctum 4.3 — SPA cookie-tabanlı auth (`statefulApi()` middleware,
  `config/cors.php` → `supports_credentials: true`, `allowed_origins` →
  `FRONTEND_URL` env değişkeninden)
- Migration'lar: `users`, `personal_access_tokens` (Sanctum), `projects`,
  `modules`, `module_items`, `tasks`, `subscriptions`
- Eloquent modelleri: `Project`, `Module`, `ModuleItem`, `Task`,
  `Subscription`, `User` (ilişkilerle birlikte)
- `AuthController` (`/register`, `/login`, `/logout`),
  `ProjectController`/`ModuleController`/`ModuleItemController` (CRUD)
- `AiController` + `AiTextGenerator` arayüzü — iki sağlayıcı destekleniyor:
  `AnthropicService` ve `GroqService`. Hangisinin kullanılacağı `.env`'deki
  `AI_PROVIDER` değişkeniyle seçiliyor (`anthropic` veya `groq`), bağlama
  `AppServiceProvider::register()` içinde yapılıyor.
- AI endpoint'leri: `/api/ai/pitch`, `/api/ai/competitor-suggestions`,
  `/api/ai/competitor-analysis` — sonuncu ikisi otomatik olarak projenin
  Fikir Doğrulama modülündeki canvas/pitch verisini bağlam olarak alıyor.

## AI sağlayıcı (Groq / Anthropic)

`.env`'de `AI_PROVIDER=groq` ayarlı, `GROQ_API_KEY` dolu — test için Groq
kullanılıyor (`llama-3.3-70b-versatile`). Anthropic'e geçmek için
`AI_PROVIDER=anthropic` yapıp `ANTHROPIC_API_KEY`'i doldurmak yeterli, kod
tarafında hiçbir değişiklik gerekmiyor.
