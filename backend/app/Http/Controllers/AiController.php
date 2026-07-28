<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\ModuleItem;
use App\Models\Project;
use App\Services\AiTextGenerator;
use Illuminate\Http\Request;
use RuntimeException;

class AiController extends Controller
{
    private const PROMPTS = [
        'tr' => [
            'system' => 'Sen bir startup danışmanısın, kısa ve net elevator pitch metinleri yazıyorsun.',
            'unspecified' => 'belirtilmedi',
            'length' => [
                'short' => 'Tek cümlelik, çok kısa bir elevator pitch yaz.',
                'medium' => '2-3 cümlelik orta uzunlukta bir elevator pitch yaz, gelir modelinden bahset.',
                'long' => '4-5 cümlelik detaylı bir elevator pitch yaz, gelir modeli, maliyet ve müşteriye ulaşım kanallarından bahset.',
            ],
            'template' => <<<'PROMPT'
                Aşağıdaki Lean Canvas bilgilerine göre Türkçe bir elevator pitch yaz.

                Müşteri: %s
                Problem: %s
                Çözüm: %s
                Gelir modeli: %s
                Maliyet: %s
                Kanallar: %s

                %s
                Sadece pitch metnini döndür, başka açıklama ekleme.
                PROMPT,
        ],
        'en' => [
            'system' => 'You are a startup advisor who writes short, sharp elevator pitches.',
            'unspecified' => 'not specified',
            'length' => [
                'short' => 'Write a single-sentence, very short elevator pitch.',
                'medium' => 'Write a 2-3 sentence, medium-length elevator pitch, mentioning the revenue model.',
                'long' => 'Write a detailed 4-5 sentence elevator pitch, covering the revenue model, cost, and customer acquisition channels.',
            ],
            'template' => <<<'PROMPT'
                Write an elevator pitch in English based on the following Lean Canvas information.

                Customer: %s
                Problem: %s
                Solution: %s
                Revenue model: %s
                Cost: %s
                Channels: %s

                %s
                Return only the pitch text, no other explanation.
                PROMPT,
        ],
    ];

    private const RESEARCH_PROMPTS = [
        'tr' => [
            'system' => 'Sen bir pazar araştırması danışmanısın. Bilgin eğitim kesim tarihinle sınırlı — anlık/canlı veri erişimin yok, bunu asla gizleme. Yalnızca geçerli JSON döndürüyorsun, başka hiçbir metin eklemiyorsun.',
            'noContext' => 'Proje bağlamı henüz girilmedi.',
            'contextHeader' => "Proje bağlamı (Fikir Doğrulama modülünden):\nMüşteri: %s\nProblem: %s\nÇözüm: %s",
            'suggestPrompt' => <<<'PROMPT'
                %s

                Yukarıdaki bağlama göre bu ürüne benzer veya alternatif olabilecek 4-5 gerçek/bilinen ürün ya da şirket adı öner.
                Sadece şu formatta JSON döndür: {"suggestions": ["isim1", "isim2", ...]}
                PROMPT,
            'analyzePrompt' => <<<'PROMPT'
                %s

                Rakip/alternatif: %s

                Bu rakip hakkında genel bilgine dayanarak kısa bir değerlendirme yap. Emin olmadığın konularda
                tahmini olduğunu belirt, uydurma kesin rakam verme.
                Sadece şu formatta JSON döndür:
                {"price": "fiyatlandırma konumlandırması (kısa)", "feature": "öne çıkan özellik (kısa)", "pro": "güçlü yönü (kısa)", "con": "zayıf yönü (kısa)"}
                PROMPT,
        ],
        'en' => [
            'system' => 'You are a market research advisor. Your knowledge has a training cutoff — you have no live/real-time data access, and you never hide that. You only return valid JSON, no other text.',
            'noContext' => 'No project context has been entered yet.',
            'contextHeader' => "Project context (from the Idea Validation module):\nCustomer: %s\nProblem: %s\nSolution: %s",
            'suggestPrompt' => <<<'PROMPT'
                %s

                Based on the context above, suggest 4-5 real, known products or companies that are similar or alternative to this idea.
                Return only JSON in this format: {"suggestions": ["name1", "name2", ...]}
                PROMPT,
            'analyzePrompt' => <<<'PROMPT'
                %s

                Competitor/alternative: %s

                Give a brief assessment of this competitor based on your general knowledge. Where you're unsure,
                say so explicitly rather than inventing precise figures.
                Return only JSON in this format:
                {"price": "pricing position (short)", "feature": "standout feature (short)", "pro": "strength (short)", "con": "weakness (short)"}
                PROMPT,
        ],
    ];

    private const REQUIREMENTS_PROMPTS = [
        'tr' => [
            'system' => 'Sen bir ürün müdürüsün. Özelliklerden kısa ve net user story\'ler yazıyorsun. Yalnızca geçerli JSON döndürüyorsun, başka hiçbir metin eklemiyorsun.',
            'noContext' => 'Proje bağlamı henüz girilmedi.',
            'contextHeader' => "Proje bağlamı (Fikir Doğrulama modülünden):\nMüşteri: %s\nProblem: %s\nÇözüm: %s",
            'prompt' => <<<'PROMPT'
                %s

                Aşağıdaki özellik listesi için her biri adına bir user story yaz ve bir öncelik seviyesi öner.
                Öncelik değeri şu 4 değerden biri olmalı: "must", "should", "could", "wont" (MoSCoW yöntemi).
                User story'ler "Bir [kullanıcı rolü] olarak, [istediğim şey] istiyorum, çünkü [fayda]." formatında, tek cümle, Türkçe olsun.

                Özellikler: %s

                Sadece şu formatta JSON döndür:
                {"stories": [{"feature": "özellik adı", "story": "user story metni", "priority": "must|should|could|wont"}, ...]}
                PROMPT,
        ],
        'en' => [
            'system' => 'You are a product manager who writes short, sharp user stories from feature names. You only return valid JSON, no other text.',
            'noContext' => 'No project context has been entered yet.',
            'contextHeader' => "Project context (from the Idea Validation module):\nCustomer: %s\nProblem: %s\nSolution: %s",
            'prompt' => <<<'PROMPT'
                %s

                For each feature in the list below, write one user story and suggest a priority level.
                The priority must be one of these 4 values: "must", "should", "could", "wont" (the MoSCoW method).
                User stories should follow the format "As a [role], I want to [goal], so that [benefit]." — one sentence, in English.

                Features: %s

                Return only JSON in this format:
                {"stories": [{"feature": "feature name", "story": "user story text", "priority": "must|should|could|wont"}, ...]}
                PROMPT,
        ],
    ];

    private const MVP_PROMPTS = [
        'tr' => [
            'system' => 'Sen bir ürün müdürüsün. MVP (ilk sürüm) kapsamına neyin girip neyin ertelenmesi gerektiğine karar veriyorsun. Yalnızca geçerli JSON döndürüyorsun, başka hiçbir metin eklemiyorsun.',
            'noContext' => 'Proje bağlamı henüz girilmedi.',
            'contextHeader' => "Proje bağlamı (Fikir Doğrulama modülünden):\nMüşteri: %s\nProblem: %s\nÇözüm: %s",
            'storyLine' => "- %s: %s",
            'prompt' => <<<'PROMPT'
                %s

                Aşağıdaki özellik listesi için, gerçek bir ilk sürümün (MVP) hangi özelliklerle çıkması gerektiğine karar ver.
                Her özelliği şu 4 gruptan birine ata: "must" (MVP için zorunlu), "should" (önemli ama ertelenebilir),
                "could" (olsa iyi olur), "wont" (bu MVP'de kesinlikle olmamalı). Her atama için tek cümlelik kısa bir gerekçe yaz.

                Özellikler:
                %s

                Sadece şu formatta JSON döndür:
                {"recommendations": [{"feature": "özellik adı", "column": "must|should|could|wont", "reason": "kısa gerekçe"}, ...]}
                PROMPT,
        ],
        'en' => [
            'system' => 'You are a product manager deciding what belongs in an MVP versus what should be deferred. You only return valid JSON, no other text.',
            'noContext' => 'No project context has been entered yet.',
            'contextHeader' => "Project context (from the Idea Validation module):\nCustomer: %s\nProblem: %s\nSolution: %s",
            'storyLine' => "- %s: %s",
            'prompt' => <<<'PROMPT'
                %s

                For the feature list below, decide which features a real first release (MVP) should ship with.
                Assign each feature to one of 4 buckets: "must" (required for the MVP), "should" (important but can be deferred),
                "could" (nice to have), "wont" (must not be in this MVP). Give a one-sentence reason for each assignment.

                Features:
                %s

                Return only JSON in this format:
                {"recommendations": [{"feature": "feature name", "column": "must|should|could|wont", "reason": "short reason"}, ...]}
                PROMPT,
        ],
    ];

    private const DESIGN_PROMPTS = [
        'tr' => [
            'system' => 'Sen bir yazılım mimarısın. Sana verilen ekran/sayfa listesinden veritabanı şeması, API listesi ve mimari çıkarıyorsun. Yalnızca geçerli JSON döndürüyorsun, başka hiçbir metin eklemiyorsun. Mermaid diyagram sözdizimi kurallarına harfiyen uyuyorsun.',
            'noContext' => 'Proje bağlamı henüz girilmedi.',
            'contextHeader' => "Proje bağlamı (Fikir Doğrulama modülünden):\nMüşteri: %s\nProblem: %s\nÇözüm: %s",
            'prompt' => <<<'PROMPT'
                %s

                Aşağıdaki ekran/sayfa listesine göre (bunlar gerçek Figma tasarımından veya kullanıcının girdiği sayfa listesinden geliyor):
                %s

                Şunları üret:
                1) database_er: Bu ekranları destekleyecek bir veritabanı şeması. Geçerli bir Mermaid erDiagram string'i olarak
                   (örnek biçim: "erDiagram\n    USER ||--o{ ORDER : places\n    USER {\n        int id\n        string name\n    }").
                2) api_flow: Bu ekranların ihtiyaç duyacağı 5-10 REST endpoint. Her biri {"method": "GET|POST|PUT|DELETE", "path": "/api/...", "description": "kısa açıklama"} formatında.
                3) architecture: Katmanlı bir sistem mimarisi. Geçerli bir Mermaid flowchart string'i olarak
                   (örnek biçim: "graph TD\n    Client[Next.js] --> API[Laravel API]\n    API --> DB[(PostgreSQL)]").

                Sadece şu formatta JSON döndür:
                {"database_er": "...", "api_flow": [...], "architecture": "..."}
                PROMPT,
        ],
        'en' => [
            'system' => 'You are a software architect. From a list of screens/pages you derive a database schema, an API list, and a system architecture. You only return valid JSON, no other text. You strictly follow Mermaid diagram syntax rules.',
            'noContext' => 'No project context has been entered yet.',
            'contextHeader' => "Project context (from the Idea Validation module):\nCustomer: %s\nProblem: %s\nSolution: %s",
            'prompt' => <<<'PROMPT'
                %s

                Based on the following screen/page list (these come from a real Figma design or from a page list the user entered):
                %s

                Produce:
                1) database_er: A database schema to support these screens, as a valid Mermaid erDiagram string
                   (example shape: "erDiagram\n    USER ||--o{ ORDER : places\n    USER {\n        int id\n        string name\n    }").
                2) api_flow: 5-10 REST endpoints these screens would need. Each as {"method": "GET|POST|PUT|DELETE", "path": "/api/...", "description": "short description"}.
                3) architecture: A layered system architecture, as a valid Mermaid flowchart string
                   (example shape: "graph TD\n    Client[Next.js] --> API[Laravel API]\n    API --> DB[(PostgreSQL)]").

                Return only JSON in this format:
                {"database_er": "...", "api_flow": [...], "architecture": "..."}
                PROMPT,
        ],
    ];

    private const TECH_STACK_PROMPTS = [
        'tr' => [
            'system' => 'Sen bir çözüm mimarısın. Projelere uygun teknoloji seçimi konusunda tavsiye veriyorsun. Bilgin eğitim kesim tarihinle sınırlı — anlık trend/istatistik verisine erişimin yok, bunu asla gizleme; genel bilgine dayanarak nitel bir değerlendirme yap. Yalnızca geçerli JSON döndürüyorsun, başka hiçbir metin eklemiyorsun.',
            'noContext' => 'Proje bağlamı henüz girilmedi.',
            'contextHeader' => "Proje bağlamı (Fikir Doğrulama modülünden):\nMüşteri: %s\nProblem: %s\nÇözüm: %s",
            'featuresHeader' => "\nMVP kapsamındaki zorunlu özellikler: %s",
            'noFeatures' => '',
            'prompt' => <<<'PROMPT'
                %s

                Aşağıdaki 4 kategori için: her kategoride verilen seçeneklerden birini öner, tek cümlelik gerekçe yaz,
                ve önerdiğinin dışında kalan 2 seçenek için kısa birer nitel not düş (bu projeye neden daha az uygun olabilecekleri).

                Kategoriler ve seçenekler:
                %s

                Sadece şu formatta JSON döndür:
                {"categories": {
                  "frontend": {"recommended": "seçenek adı", "reasoning": "kısa gerekçe", "alternatives": [{"name": "...", "note": "..."}]},
                  "backend": {...},
                  "database": {...},
                  "hosting": {...}
                }}
                PROMPT,
        ],
        'en' => [
            'system' => 'You are a solutions architect advising on technology choices for projects. Your knowledge has a training cutoff — you have no access to live trend/statistics data, and you never hide that; give a qualitative assessment based on your general knowledge instead. You only return valid JSON, no other text.',
            'noContext' => 'No project context has been entered yet.',
            'contextHeader' => "Project context (from the Idea Validation module):\nCustomer: %s\nProblem: %s\nSolution: %s",
            'featuresHeader' => "\nMust-have features in MVP scope: %s",
            'noFeatures' => '',
            'prompt' => <<<'PROMPT'
                %s

                For each of the 4 categories below: recommend one of the given options, write a one-sentence reason,
                and add a short qualitative note for each of the other 2 options (why they might fit this project less well).

                Categories and options:
                %s

                Return only JSON in this format:
                {"categories": {
                  "frontend": {"recommended": "option name", "reasoning": "short reason", "alternatives": [{"name": "...", "note": "..."}]},
                  "backend": {...},
                  "database": {...},
                  "hosting": {...}
                }}
                PROMPT,
        ],
    ];

    private const API_DESIGN_PROMPTS = [
        'tr' => [
            'system' => 'Sen bir API tasarımcısısın. Verilen isteklerden REST endpoint\'leri ve basit istek/yanıt şemaları üretiyorsun. Yalnızca geçerli JSON döndürüyorsun, başka hiçbir metin eklemiyorsun.',
            'noContext' => 'Proje bağlamı henüz girilmedi.',
            'contextHeader' => "Proje bağlamı (Fikir Doğrulama modülünden):\nMüşteri: %s\nProblem: %s\nÇözüm: %s",
            'existingHeader' => "\nZaten tanımlı endpoint'ler (bunları tekrar üretme):\n%s",
            'noExisting' => '',
            'prompt' => <<<'PROMPT'
                %s
                %s

                Kullanıcının isteği: "%s"

                Bu isteğe uygun REST endpoint'leri üret. Her endpoint için HTTP metodu, path, kısa açıklama,
                ve varsa istek/yanıt gövdesinde bulunması gereken alanları (alan adı ve basit bir tip: string, integer, number, boolean, array, object) belirt.
                GET ve DELETE endpoint'lerinde genelde requestFields olmaz, boş dizi bırak.

                Sadece şu formatta JSON döndür:
                {"endpoints": [
                  {"method": "GET|POST|PUT|PATCH|DELETE", "path": "/api/...", "summary": "kısa açıklama",
                   "requestFields": [{"name": "alan_adi", "type": "string"}], "responseFields": [{"name": "alan_adi", "type": "string"}]}
                ]}
                PROMPT,
        ],
        'en' => [
            'system' => 'You are an API designer. From a given request you produce REST endpoints with simple request/response schemas. You only return valid JSON, no other text.',
            'noContext' => 'No project context has been entered yet.',
            'contextHeader' => "Project context (from the Idea Validation module):\nCustomer: %s\nProblem: %s\nSolution: %s",
            'existingHeader' => "\nEndpoints already defined (do not regenerate these):\n%s",
            'noExisting' => '',
            'prompt' => <<<'PROMPT'
                %s
                %s

                The user's request: "%s"

                Generate REST endpoints that fit this request. For each endpoint, give the HTTP method, path, a short summary,
                and the fields that should appear in the request/response body (field name and a simple type: string, integer, number, boolean, array, object).
                GET and DELETE endpoints usually have no requestFields — leave that array empty.

                Return only JSON in this format:
                {"endpoints": [
                  {"method": "GET|POST|PUT|PATCH|DELETE", "path": "/api/...", "summary": "short summary",
                   "requestFields": [{"name": "field_name", "type": "string"}], "responseFields": [{"name": "field_name", "type": "string"}]}
                ]}
                PROMPT,
        ],
    ];

    private const SCAFFOLD_PROMPTS = [
        'tr' => [
            'system' => 'Sen bir yazılım mimarısın. Seçilen teknoloji yığınına uygun, gerçekçi ve o framework\'ün gerçek klasör konvansiyonlarını izleyen bir proje iskeleti (klasör/dosya ağacı) üretiyorsun. Genel/placeholder isimler değil, verilen varlık ve endpoint\'lere göre gerçek dosya adları kullanıyorsun (ör. ProductController.php, product.model.js). Yalnızca geçerli JSON döndürüyorsun, başka hiçbir metin eklemiyorsun.',
            'prompt' => <<<'PROMPT'
                Teknoloji yığını:
                - Frontend: %s
                - Backend: %s
                - Veritabanı: %s

                Varlıklar (veritabanı şemasından): %s
                API endpoint'leri: %s

                Bu bilgilere göre, "frontend" ve "backend" olmak üzere iki ana klasörden oluşan gerçekçi bir monorepo proje iskeleti üret.
                Backend'de seçilen framework'ün gerçek klasör konvansiyonunu kullan (ör. Laravel ise app/Http/Controllers, routes/, database/migrations).
                Frontend'de seçilen framework'ün gerçek klasör konvansiyonunu kullan (ör. Next.js ise src/app, src/components).
                Varlık adlarına göre gerçek controller/model/route/component dosya adları üret (ör. ProductController.php, ProductList.jsx).
                En fazla 4 seviye derinlik kullan, her klasörde makul sayıda dosya olsun (aşırı uzatma).

                Sadece şu formatta JSON döndür (name: dosya/klasör adı, type: "folder" veya "file", children: sadece folder için):
                {"tree": {"name": "proje-adi", "type": "folder", "children": [
                  {"name": "frontend", "type": "folder", "children": [...]},
                  {"name": "backend", "type": "folder", "children": [...]}
                ]}}
                PROMPT,
        ],
        'en' => [
            'system' => 'You are a software architect who produces realistic project scaffolds (folder/file trees) that follow the real folder conventions of the chosen tech stack. You use real file names derived from the given entities and endpoints, not generic placeholders (e.g. ProductController.php, product.model.js). You only return valid JSON, no other text.',
            'prompt' => <<<'PROMPT'
                Tech stack:
                - Frontend: %s
                - Backend: %s
                - Database: %s

                Entities (from the database schema): %s
                API endpoints: %s

                Based on this, produce a realistic monorepo project scaffold made of two top-level folders: "frontend" and "backend".
                For the backend, use the real folder convention of the chosen framework (e.g. for Laravel: app/Http/Controllers, routes/, database/migrations).
                For the frontend, use the real folder convention of the chosen framework (e.g. for Next.js: src/app, src/components).
                Derive real controller/model/route/component file names from the entity names (e.g. ProductController.php, ProductList.jsx).
                Use at most 4 levels of depth, and a reasonable number of files per folder (don't over-extend).

                Return only JSON in this format (name: file/folder name, type: "folder" or "file", children: only for folders):
                {"tree": {"name": "project-name", "type": "folder", "children": [
                  {"name": "frontend", "type": "folder", "children": [...]},
                  {"name": "backend", "type": "folder", "children": [...]}
                ]}}
                PROMPT,
        ],
    ];

    private const SPRINT_PROMPTS = [
        'tr' => [
            'system' => 'Sen bir proje yöneticisisin. Bir proje için gerçekçi bir sprint planı çıkarıyorsun — her sprint tematik bir gruba odaklanır ve somut, uygulanabilir görevler içerir. Yalnızca geçerli JSON döndürüyorsun, başka hiçbir metin eklemiyorsun.',
            'noContext' => 'Proje bağlamı henüz girilmedi.',
            'contextHeader' => "Proje: %s\nMüşteri: %s\nProblem: %s\nÇözüm: %s",
            'featuresHeader' => "\nMVP kapsamındaki özellikler: %s",
            'noFeatures' => '',
            'prompt' => <<<'PROMPT'
                %s
                %s

                Bu projeyi 3 ila 6 sprint'e böl. Her sprint tematik bir gruba odaklansın (ör. "Auth", "Müşteri Modülü", "Raporlama").
                Her sprintte 3-6 somut, uygulanabilir görev listele (ör. "Login endpoint'i", "Login ekranı UI", "JWT middleware").
                Sprint'leri mantıklı bir bağımlılık sırasına göre diz (ör. kimlik doğrulama önce gelir).

                Sadece şu formatta JSON döndür:
                {"sprints": [{"name": "Sprint 1", "theme": "kısa tema adı", "tasks": ["görev 1", "görev 2", ...]}, ...]}
                PROMPT,
        ],
        'en' => [
            'system' => 'You are a project manager producing a realistic sprint plan for a project — each sprint focuses on one thematic group and contains concrete, actionable tasks. You only return valid JSON, no other text.',
            'noContext' => 'No project context has been entered yet.',
            'contextHeader' => "Project: %s\nCustomer: %s\nProblem: %s\nSolution: %s",
            'featuresHeader' => "\nFeatures in MVP scope: %s",
            'noFeatures' => '',
            'prompt' => <<<'PROMPT'
                %s
                %s

                Split this project into 3 to 6 sprints. Each sprint should focus on one thematic group (e.g. "Auth", "Customer Module", "Reporting").
                List 3-6 concrete, actionable tasks per sprint (e.g. "Login endpoint", "Login screen UI", "JWT middleware").
                Order the sprints in a sensible dependency order (e.g. authentication comes first).

                Return only JSON in this format:
                {"sprints": [{"name": "Sprint 1", "theme": "short theme name", "tasks": ["task 1", "task 2", ...]}, ...]}
                PROMPT,
        ],
    ];

    private const ENV_SETUP_PROMPTS = [
        'tr' => [
            'system' => 'Sen bir DevOps mühendisisin. Verilen teknoloji yığınına uygun, gerçekten çalışacak yapılandırma dosyaları üretiyorsun — genel şablon değil, o dillere/framework\'lere özel gerçek içerik. Yalnızca geçerli JSON döndürüyorsun, başka hiçbir metin eklemiyorsun.',
            'prompt' => <<<'PROMPT'
                Proje: %s
                Teknoloji yığını:
                - Frontend: %s
                - Backend: %s
                - Veritabanı: %s

                Bu yığın için 4 dosya üret:
                1) gitignore: Bu frontend ve backend framework'lerine özel gerçek bir .gitignore içeriği (node_modules, .env, vendor, build çıktıları vb.).
                2) readme: Projenin adını başlık yapan, teknoloji yığınını listeleyen ve kurulum/çalıştırma adımlarını (gerçek komutlarla, ör. npm install, composer install, php artisan serve) içeren bir README.md (Markdown).
                3) dockerCompose: Frontend, backend ve veritabanı servislerini içeren, gerçek imaj adları ve portlarla çalışan bir docker-compose.yml (YAML metni).
                4) envExample: Backend ve veritabanı için gerçekçi ortam değişkeni isimleriyle bir .env.example.

                Sadece şu formatta JSON döndür:
                {"gitignore": "...", "readme": "...", "dockerCompose": "...", "envExample": "..."}
                PROMPT,
        ],
        'en' => [
            'system' => 'You are a DevOps engineer producing configuration files that will actually work for the given tech stack — real content specific to those languages/frameworks, not a generic template. You only return valid JSON, no other text.',
            'prompt' => <<<'PROMPT'
                Project: %s
                Tech stack:
                - Frontend: %s
                - Backend: %s
                - Database: %s

                Produce 4 files for this stack:
                1) gitignore: A real .gitignore tailored to these frontend and backend frameworks (node_modules, .env, vendor, build output, etc.).
                2) readme: A README.md (Markdown) that titles the project, lists the tech stack, and gives real setup/run steps (with real commands, e.g. npm install, composer install, php artisan serve).
                3) dockerCompose: A docker-compose.yml (YAML text) with frontend, backend, and database services using real image names and ports.
                4) envExample: A .env.example with realistic environment variable names for the backend and database.

                Return only JSON in this format:
                {"gitignore": "...", "readme": "...", "dockerCompose": "...", "envExample": "..."}
                PROMPT,
        ],
    ];

    private const PROMPT_ENGINEERING_PROMPTS = [
        'tr' => [
            'system' => 'Sen, AI kodlama asistanları için proje talimat dosyaları yazan bir mühendissin. Kullanıcının belirttiği kurallarla projenin gerçek bağlamını (teknoloji yığını, varlıklar) birleştirip somut, jenerik olmayan talimatlar üretiyorsun. Yalnızca geçerli JSON döndürüyorsun, başka hiçbir metin eklemiyorsun.',
            'prompt' => <<<'PROMPT'
                Proje: %s
                Teknoloji yığını: Frontend: %s, Backend: %s, Veritabanı: %s
                Varlıklar: %s

                Kullanıcının belirttiği proje kuralları:
                %s

                Bu bilgilere göre 3 dosya üret:
                1) claudeMd: Claude Code (AI kodlama asistanı) için bir CLAUDE.md — proje özeti, teknoloji yığını, yukarıdaki kurallar ve
                   kod yazarken uyulması gereken somut konvansiyonları içersin (Markdown, başlıklarla).
                2) cursorRules: Cursor editörü için bir .cursorrules dosyası — kısa, madde madde, doğrudan talimat formatında (uzun paragraf değil).
                3) aiInstructions: GitHub Copilot / ChatGPT gibi genel bir AI asistanına verilebilecek, tek paragraflık yoğun bir sistem talimatı.

                Her üç dosya da aynı kuralları ve bağlamı yansıtsın, sadece formatları farklı olsun. Genel/placeholder ifadeler değil,
                verilen teknoloji yığınına ve varlıklara özel somut ifadeler kullan.

                Sadece şu formatta JSON döndür:
                {"claudeMd": "...", "cursorRules": "...", "aiInstructions": "..."}
                PROMPT,
        ],
        'en' => [
            'system' => 'You are an engineer who writes project instruction files for AI coding assistants. You combine the user\'s stated rules with the project\'s real context (tech stack, entities) into concrete, non-generic instructions. You only return valid JSON, no other text.',
            'prompt' => <<<'PROMPT'
                Project: %s
                Tech stack: Frontend: %s, Backend: %s, Database: %s
                Entities: %s

                Project rules stated by the user:
                %s

                Based on this, produce 3 files:
                1) claudeMd: A CLAUDE.md for Claude Code (an AI coding assistant) — a project summary, the tech stack, the rules above,
                   and concrete conventions to follow when writing code (Markdown, with headings).
                2) cursorRules: A .cursorrules file for the Cursor editor — short, bulleted, direct instructions (not long paragraphs).
                3) aiInstructions: A single dense paragraph of system instructions suitable for a general AI assistant like GitHub Copilot or a custom ChatGPT.

                All three files should reflect the same rules and context, just in different formats. Use concrete language specific to
                the given tech stack and entities, not generic placeholders.

                Return only JSON in this format:
                {"claudeMd": "...", "cursorRules": "...", "aiInstructions": "..."}
                PROMPT,
        ],
    ];

    private const TOOL_PROMPTS = [
        'tr' => [
            'system' => 'Sen bir AI araç danışmanısın. Gerçekten var olan, bilinen AI araçlarını (uydurma isim değil) proje tipine ve bağlama göre öneriyorsun. Bilgin eğitim kesim tarihinle sınırlı — yeni çıkan veya güncel trend olan araçlara anlık erişimin yok, bunu asla gizleme; önerilerini genel bilinirliği yüksek, köklü araçlarla sınırla. Yalnızca geçerli JSON döndürüyorsun, başka hiçbir metin eklemiyorsun.',
            'noContext' => 'Proje bağlamı henüz girilmedi.',
            'contextHeader' => "Proje bağlamı (Fikir Doğrulama modülünden):\nMüşteri: %s\nProblem: %s\nÇözüm: %s",
            'stackHeader' => "\nTeknoloji yığını: Frontend: %s, Backend: %s, Veritabanı: %s",
            'noStack' => '',
            'prompt' => <<<'PROMPT'
                %s
                %s

                Proje tipi: %s

                Bu proje için, farklı kategorilerden (kodlama asistanı, tasarım, görsel/medya üretimi, backend/veritabanı,
                genel AI asistanı, pazarlama/içerik vb.) 5-8 gerçek ve bilinen AI aracı öner. Her biri için hangi kategoriye
                girdiğini ve bu projeye neden uygun olduğunu tek cümlelik somut bir gerekçeyle açıkla.

                Sadece şu formatta JSON döndür:
                {"tools": [{"name": "araç adı", "category": "kategori", "reason": "kısa gerekçe"}, ...]}
                PROMPT,
        ],
        'en' => [
            'system' => 'You are an AI tool advisor. You recommend real, known AI tools (never invented names) based on the project type and context. Your knowledge has a training cutoff — you have no access to brand-new or currently-trending tools, and you never hide that; limit recommendations to well-established, broadly known tools. You only return valid JSON, no other text.',
            'noContext' => 'No project context has been entered yet.',
            'contextHeader' => "Project context (from the Idea Validation module):\nCustomer: %s\nProblem: %s\nSolution: %s",
            'stackHeader' => "\nTech stack: Frontend: %s, Backend: %s, Database: %s",
            'noStack' => '',
            'prompt' => <<<'PROMPT'
                %s
                %s

                Project type: %s

                Recommend 5-8 real, well-known AI tools for this project, spanning different categories (coding assistant, design,
                image/media generation, backend/database, general AI assistant, marketing/content, etc.). For each, state its category
                and a one-sentence concrete reason it fits this specific project.

                Return only JSON in this format:
                {"tools": [{"name": "tool name", "category": "category", "reason": "short reason"}, ...]}
                PROMPT,
        ],
    ];

    public function __construct(private AiTextGenerator $ai) {}

    public function pitch(Request $request)
    {
        $data = $request->validate([
            'canvas' => ['required', 'array'],
            'canvas.problem' => ['array'],
            'canvas.solution' => ['array'],
            'canvas.customer' => ['array'],
            'canvas.revenue' => ['array'],
            'canvas.cost' => ['array'],
            'canvas.channels' => ['array'],
            'tone' => ['required', 'in:short,medium,long'],
            'locale' => ['required', 'in:tr,en'],
        ]);

        $prompts = self::PROMPTS[$data['locale']];
        $canvas = $data['canvas'];
        $join = fn (string $key) => implode(', ', $canvas[$key] ?? []) ?: $prompts['unspecified'];

        $userPrompt = sprintf(
            $prompts['template'],
            $join('customer'),
            $join('problem'),
            $join('solution'),
            $join('revenue'),
            $join('cost'),
            $join('channels'),
            $prompts['length'][$data['tone']],
        );

        try {
            $pitch = $this->ai->generate(
                systemPrompt: $prompts['system'],
                userPrompt: $userPrompt,
                maxTokens: 400,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['pitch' => trim($pitch)]);
    }

    public function competitorSuggestions(Request $request)
    {
        $data = $request->validate([
            'module_id' => ['required', 'integer'],
            'locale' => ['required', 'in:tr,en'],
        ]);

        $module = $this->authorizedModule($request, $data['module_id']);
        $prompts = self::RESEARCH_PROMPTS[$data['locale']];
        $context = $this->contextBlock($module->project_id, $prompts);

        try {
            $raw = $this->ai->generate(
                systemPrompt: $prompts['system'],
                userPrompt: sprintf($prompts['suggestPrompt'], $context),
                maxTokens: 300,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $parsed = $this->parseJson($raw);
        if (! $parsed || ! isset($parsed['suggestions'])) {
            return response()->json(['message' => trans('messages.ai_response_unparseable')], 502);
        }

        return response()->json(['suggestions' => $parsed['suggestions']]);
    }

    public function competitorAnalysis(Request $request)
    {
        $data = $request->validate([
            'module_id' => ['required', 'integer'],
            'competitor_name' => ['required', 'string', 'max:255'],
            'locale' => ['required', 'in:tr,en'],
        ]);

        $module = $this->authorizedModule($request, $data['module_id']);
        $prompts = self::RESEARCH_PROMPTS[$data['locale']];
        $context = $this->contextBlock($module->project_id, $prompts);

        try {
            $raw = $this->ai->generate(
                systemPrompt: $prompts['system'],
                userPrompt: sprintf($prompts['analyzePrompt'], $context, $data['competitor_name']),
                maxTokens: 300,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $parsed = $this->parseJson($raw);
        if (! $parsed) {
            return response()->json(['message' => trans('messages.ai_response_unparseable')], 502);
        }

        return response()->json($parsed);
    }

    public function userStories(Request $request)
    {
        $data = $request->validate([
            'module_id' => ['required', 'integer'],
            'features' => ['required', 'array', 'min:1'],
            'features.*' => ['required', 'string', 'max:255'],
            'locale' => ['required', 'in:tr,en'],
        ]);

        $module = $this->authorizedModule($request, $data['module_id']);
        $prompts = self::REQUIREMENTS_PROMPTS[$data['locale']];
        $context = $this->contextBlock($module->project_id, $prompts);

        try {
            $raw = $this->ai->generate(
                systemPrompt: $prompts['system'],
                userPrompt: sprintf($prompts['prompt'], $context, implode(', ', $data['features'])),
                maxTokens: 900,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $parsed = $this->parseJson($raw);
        if (! $parsed || ! isset($parsed['stories'])) {
            return response()->json(['message' => trans('messages.ai_response_unparseable')], 502);
        }

        return response()->json(['stories' => $parsed['stories']]);
    }

    public function mvpRecommendation(Request $request)
    {
        $data = $request->validate([
            'module_id' => ['required', 'integer'],
            'features' => ['required', 'array', 'min:1'],
            'features.*' => ['required', 'string', 'max:255'],
            'locale' => ['required', 'in:tr,en'],
        ]);

        $module = $this->authorizedModule($request, $data['module_id']);
        $prompts = self::MVP_PROMPTS[$data['locale']];
        $context = $this->contextBlock($module->project_id, $prompts);
        $featureList = $this->featureListBlock($module->project_id, $data['features'], $prompts);

        try {
            $raw = $this->ai->generate(
                systemPrompt: $prompts['system'],
                userPrompt: sprintf($prompts['prompt'], $context, $featureList),
                maxTokens: 900,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $parsed = $this->parseJson($raw);
        if (! $parsed || ! isset($parsed['recommendations'])) {
            return response()->json(['message' => trans('messages.ai_response_unparseable')], 502);
        }

        return response()->json(['recommendations' => $parsed['recommendations']]);
    }

    private function featureListBlock(int $projectId, array $features, array $prompts): string
    {
        $requirementsModule = Module::where('project_id', $projectId)->where('module_type', 'requirements')->first();
        $stories = $requirementsModule
            ? ModuleItem::where('module_id', $requirementsModule->id)->where('item_type', 'requirement')->get()
                ->keyBy(fn ($item) => strtolower($item->content['feature'] ?? ''))
            : collect();

        return implode("\n", array_map(function (string $feature) use ($stories, $prompts) {
            $story = $stories->get(strtolower($feature))?->content['story'] ?? null;

            return $story ? sprintf($prompts['storyLine'], $feature, $story) : "- {$feature}";
        }, $features));
    }

    public function designSystem(Request $request)
    {
        $data = $request->validate([
            'module_id' => ['required', 'integer'],
            'pages' => ['required', 'array', 'min:1'],
            'pages.*.name' => ['required', 'string', 'max:255'],
            'pages.*.frames' => ['array'],
            'pages.*.frames.*' => ['string', 'max:255'],
            'locale' => ['required', 'in:tr,en'],
        ]);

        $module = $this->authorizedModule($request, $data['module_id']);
        $prompts = self::DESIGN_PROMPTS[$data['locale']];
        $context = $this->contextBlock($module->project_id, $prompts);

        $pageList = implode("\n", array_map(
            fn ($page) => "- {$page['name']}: " . implode(', ', $page['frames'] ?? []),
            $data['pages'],
        ));

        try {
            $raw = $this->ai->generate(
                systemPrompt: $prompts['system'],
                userPrompt: sprintf($prompts['prompt'], $context, $pageList),
                maxTokens: 1400,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $parsed = $this->parseJson($raw);
        if (! $parsed || ! isset($parsed['database_er'], $parsed['api_flow'], $parsed['architecture'])) {
            return response()->json(['message' => trans('messages.ai_response_unparseable')], 502);
        }

        return response()->json($parsed);
    }

    public function techStackRecommendation(Request $request)
    {
        $data = $request->validate([
            'module_id' => ['required', 'integer'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => ['required', 'array', 'min:2'],
            'categories.*.*' => ['required', 'string', 'max:100'],
            'locale' => ['required', 'in:tr,en'],
        ]);

        $module = $this->authorizedModule($request, $data['module_id']);
        $prompts = self::TECH_STACK_PROMPTS[$data['locale']];
        $context = $this->contextBlock($module->project_id, $prompts)
            . $this->mvpFeaturesBlock($module->project_id, $prompts, ['must']);

        $categoryList = implode("\n", array_map(
            fn ($options, $key) => "- {$key}: " . implode(', ', $options),
            $data['categories'],
            array_keys($data['categories']),
        ));

        try {
            $raw = $this->ai->generate(
                systemPrompt: $prompts['system'],
                userPrompt: sprintf($prompts['prompt'], $context, $categoryList),
                maxTokens: 1200,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $parsed = $this->parseJson($raw);
        if (! $parsed || ! isset($parsed['categories'])) {
            return response()->json(['message' => trans('messages.ai_response_unparseable')], 502);
        }

        return response()->json($parsed);
    }

    public function apiEndpoints(Request $request)
    {
        $data = $request->validate([
            'module_id' => ['required', 'integer'],
            'prompt' => ['required', 'string', 'max:500'],
            'existing' => ['array'],
            'existing.*' => ['string', 'max:255'],
            'locale' => ['required', 'in:tr,en'],
        ]);

        $module = $this->authorizedModule($request, $data['module_id']);
        $prompts = self::API_DESIGN_PROMPTS[$data['locale']];
        $context = $this->contextBlock($module->project_id, $prompts);
        $existingBlock = empty($data['existing'])
            ? $prompts['noExisting']
            : sprintf($prompts['existingHeader'], implode("\n", array_map(fn ($e) => "- {$e}", $data['existing'])));

        try {
            $raw = $this->ai->generate(
                systemPrompt: $prompts['system'],
                userPrompt: sprintf($prompts['prompt'], $context, $existingBlock, $data['prompt']),
                maxTokens: 1400,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $parsed = $this->parseJson($raw);
        if (! $parsed || ! isset($parsed['endpoints'])) {
            return response()->json(['message' => trans('messages.ai_response_unparseable')], 502);
        }

        return response()->json(['endpoints' => $parsed['endpoints']]);
    }

    public function scaffold(Request $request)
    {
        $data = $request->validate([
            'module_id' => ['required', 'integer'],
            'frontend' => ['required', 'string', 'max:100'],
            'backend' => ['required', 'string', 'max:100'],
            'database' => ['required', 'string', 'max:100'],
            'entities' => ['array'],
            'entities.*' => ['string', 'max:255'],
            'endpoints' => ['array'],
            'endpoints.*' => ['string', 'max:255'],
            'locale' => ['required', 'in:tr,en'],
        ]);

        $this->authorizedModule($request, $data['module_id']);
        $prompts = self::SCAFFOLD_PROMPTS[$data['locale']];

        $entities = empty($data['entities']) ? '-' : implode(', ', $data['entities']);
        $endpoints = empty($data['endpoints']) ? '-' : implode(', ', $data['endpoints']);

        try {
            $raw = $this->ai->generate(
                systemPrompt: $prompts['system'],
                userPrompt: sprintf($prompts['prompt'], $data['frontend'], $data['backend'], $data['database'], $entities, $endpoints),
                maxTokens: 2000,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $parsed = $this->parseJson($raw);
        if (! $parsed || ! isset($parsed['tree'])) {
            return response()->json(['message' => trans('messages.ai_response_unparseable')], 502);
        }

        return response()->json(['tree' => $parsed['tree']]);
    }

    public function sprintPlan(Request $request)
    {
        $data = $request->validate([
            'module_id' => ['required', 'integer'],
            'locale' => ['required', 'in:tr,en'],
        ]);

        $module = $this->authorizedModule($request, $data['module_id']);
        $prompts = self::SPRINT_PROMPTS[$data['locale']];
        $context = $this->sprintContextBlock($module->project_id, $prompts);
        $features = $this->mvpFeaturesBlock($module->project_id, $prompts, ['must', 'should']);

        try {
            $raw = $this->ai->generate(
                systemPrompt: $prompts['system'],
                userPrompt: sprintf($prompts['prompt'], $context, $features),
                maxTokens: 1600,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $parsed = $this->parseJson($raw);
        if (! $parsed || ! isset($parsed['sprints'])) {
            return response()->json(['message' => trans('messages.ai_response_unparseable')], 502);
        }

        return response()->json(['sprints' => $parsed['sprints']]);
    }

    private function sprintContextBlock(int $projectId, array $prompts): string
    {
        $project = Project::find($projectId);
        $ideaModule = Module::where('project_id', $projectId)->where('module_type', 'idea')->first();
        $canvasItem = $ideaModule
            ? ModuleItem::where('module_id', $ideaModule->id)->where('item_type', 'lean_canvas')->first()
            : null;

        if (! $canvasItem) {
            return $prompts['noContext'];
        }

        $canvas = $canvasItem->content;
        $join = fn (string $key) => implode(', ', $canvas[$key] ?? []) ?: '-';

        return sprintf(
            $prompts['contextHeader'],
            $project?->title ?? '-',
            $join('customer'),
            $join('problem'),
            $join('solution'),
        );
    }

    /**
     * Comma-joined list of MVP-scope feature names whose board column is in
     * $columns (e.g. ['must'] or ['must', 'should']), formatted via the
     * caller's 'featuresHeader' prompt template, or '' if there's nothing to add.
     */
    private function mvpFeaturesBlock(int $projectId, array $prompts, array $columns): string
    {
        $mvpModule = Module::where('project_id', $projectId)->where('module_type', 'mvp_scope')->first();
        if (! $mvpModule) {
            return $prompts['noFeatures'];
        }

        $features = ModuleItem::where('module_id', $mvpModule->id)
            ->where('item_type', 'mvp_item')
            ->get()
            ->filter(fn ($item) => in_array($item->content['column'] ?? null, $columns, true))
            ->pluck('content.feature')
            ->filter()
            ->values();

        if ($features->isEmpty()) {
            return $prompts['noFeatures'];
        }

        return sprintf($prompts['featuresHeader'], $features->implode(', '));
    }

    public function envSetup(Request $request)
    {
        $data = $request->validate([
            'module_id' => ['required', 'integer'],
            'frontend' => ['required', 'string', 'max:100'],
            'backend' => ['required', 'string', 'max:100'],
            'database' => ['required', 'string', 'max:100'],
            'locale' => ['required', 'in:tr,en'],
        ]);

        $module = $this->authorizedModule($request, $data['module_id']);
        $prompts = self::ENV_SETUP_PROMPTS[$data['locale']];
        $projectTitle = Project::find($module->project_id)?->title ?? 'DevPlan Project';

        try {
            $raw = $this->ai->generate(
                systemPrompt: $prompts['system'],
                userPrompt: sprintf($prompts['prompt'], $projectTitle, $data['frontend'], $data['backend'], $data['database']),
                maxTokens: 2200,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $parsed = $this->parseJson($raw);
        if (! $parsed || ! isset($parsed['gitignore'], $parsed['readme'], $parsed['dockerCompose'], $parsed['envExample'])) {
            return response()->json(['message' => trans('messages.ai_response_unparseable')], 502);
        }

        return response()->json($parsed);
    }

    public function promptInstructions(Request $request)
    {
        $data = $request->validate([
            'module_id' => ['required', 'integer'],
            'rules' => ['required', 'array', 'min:1'],
            'rules.*' => ['required', 'string', 'max:255'],
            'frontend' => ['required', 'string', 'max:100'],
            'backend' => ['required', 'string', 'max:100'],
            'database' => ['required', 'string', 'max:100'],
            'entities' => ['array'],
            'entities.*' => ['string', 'max:255'],
            'locale' => ['required', 'in:tr,en'],
        ]);

        $module = $this->authorizedModule($request, $data['module_id']);
        $prompts = self::PROMPT_ENGINEERING_PROMPTS[$data['locale']];
        $projectTitle = Project::find($module->project_id)?->title ?? 'DevPlan Project';
        $rulesList = implode("\n", array_map(fn ($r) => "- {$r}", $data['rules']));
        $entities = empty($data['entities']) ? '-' : implode(', ', $data['entities']);

        try {
            $raw = $this->ai->generate(
                systemPrompt: $prompts['system'],
                userPrompt: sprintf(
                    $prompts['prompt'],
                    $projectTitle,
                    $data['frontend'],
                    $data['backend'],
                    $data['database'],
                    $entities,
                    $rulesList,
                ),
                maxTokens: 2000,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $parsed = $this->parseJson($raw);
        if (! $parsed || ! isset($parsed['claudeMd'], $parsed['cursorRules'], $parsed['aiInstructions'])) {
            return response()->json(['message' => trans('messages.ai_response_unparseable')], 502);
        }

        return response()->json($parsed);
    }

    public function toolRecommendations(Request $request)
    {
        $data = $request->validate([
            'module_id' => ['required', 'integer'],
            'project_type' => ['required', 'string', 'max:100'],
            'frontend' => ['nullable', 'string', 'max:100'],
            'backend' => ['nullable', 'string', 'max:100'],
            'database' => ['nullable', 'string', 'max:100'],
            'locale' => ['required', 'in:tr,en'],
        ]);

        $module = $this->authorizedModule($request, $data['module_id']);
        $prompts = self::TOOL_PROMPTS[$data['locale']];
        $context = $this->contextBlock($module->project_id, $prompts);
        $stackBlock = (! empty($data['frontend']) && ! empty($data['backend']) && ! empty($data['database']))
            ? sprintf($prompts['stackHeader'], $data['frontend'], $data['backend'], $data['database'])
            : $prompts['noStack'];

        try {
            $raw = $this->ai->generate(
                systemPrompt: $prompts['system'],
                userPrompt: sprintf($prompts['prompt'], $context, $stackBlock, $data['project_type']),
                maxTokens: 1200,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $parsed = $this->parseJson($raw);
        if (! $parsed || ! isset($parsed['tools'])) {
            return response()->json(['message' => trans('messages.ai_response_unparseable')], 502);
        }

        return response()->json(['tools' => $parsed['tools']]);
    }

    private function authorizedModule(Request $request, int $moduleId): Module
    {
        $module = Module::findOrFail($moduleId);
        abort_unless($module->project()->value('user_id') === $request->user()->id, 403);

        return $module;
    }

    private function contextBlock(int $projectId, array $prompts): string
    {
        $ideaModule = Module::where('project_id', $projectId)->where('module_type', 'idea')->first();
        $canvasItem = $ideaModule
            ? ModuleItem::where('module_id', $ideaModule->id)->where('item_type', 'lean_canvas')->first()
            : null;

        if (! $canvasItem) {
            return $prompts['noContext'];
        }

        $canvas = $canvasItem->content;
        $join = fn (string $key) => implode(', ', $canvas[$key] ?? []) ?: '-';

        return sprintf($prompts['contextHeader'], $join('customer'), $join('problem'), $join('solution'));
    }

    private function parseJson(string $text): ?array
    {
        $trimmed = trim($text);
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $trimmed);

        $decoded = json_decode(trim($trimmed), true);

        return is_array($decoded) ? $decoded : null;
    }
}
