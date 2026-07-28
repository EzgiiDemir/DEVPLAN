import {
  Lightbulb,
  Search,
  ListChecks,
  Target,
  LayoutGrid,
  Layers,
  Webhook,
  FolderTree,
  KanbanSquare,
  TerminalSquare,
  Sparkles,
  BookOpen,
} from "lucide-react";

// id değerleri backend'deki `modules.module_type` enum değerleriyle birebir eşleşir.
// title/sub metinleri burada YOK — messages/{locale}.json içindeki Modules.<id> anahtarından gelir.
// Sıra, gerçek bir ürünün hazırlık akışını izler: teknoloji MVP'den sonra,
// tasarım teknolojiden sonra, ortam kurulumu görev planından önce gelir vb.
export const MODULES = [
  { id: "idea", n: 1, Icon: Lightbulb },
  { id: "research", n: 2, Icon: Search },
  { id: "requirements", n: 3, Icon: ListChecks },
  { id: "mvp_scope", n: 4, Icon: Target },
  { id: "tech_stack", n: 5, Icon: Layers },
  { id: "design", n: 6, Icon: LayoutGrid },
  { id: "api_design", n: 7, Icon: Webhook },
  { id: "folder_structure", n: 8, Icon: FolderTree },
  { id: "environment", n: 9, Icon: TerminalSquare },
  { id: "task_plan", n: 10, Icon: KanbanSquare },
  { id: "ai_resources", n: 11, Icon: BookOpen },
  { id: "prompt_engineering", n: 12, Icon: Sparkles },
];

export const CANVAS_FIELD_KEYS = ["problem", "solution", "customer", "revenue", "cost", "channels"];
export const IDEA_TEMPLATE_KEYS = ["ecommerce", "saas", "mobile"];
export const PITCH_TONES = ["short", "medium", "long"];
