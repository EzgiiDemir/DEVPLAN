import { IdeaModule } from "./IdeaModule";
import { ResearchModule } from "./ResearchModule";
import { RequirementsModule } from "./RequirementsModule";
import { MvpScopeModule } from "./MvpScopeModule";
import { DesignModule } from "./DesignModule";
import { TechStackModule } from "./TechStackModule";
import { ApiDesignModule } from "./ApiDesignModule";
import { FolderStructureModule } from "./FolderStructureModule";
import { TaskPlanModule } from "./TaskPlanModule";
import { EnvironmentModule } from "./EnvironmentModule";
import { PromptEngineeringModule } from "./PromptEngineeringModule";
import { AiResourcesModule } from "./AiResourcesModule";

// Yeni bir modülün gerçek arayüzü hazır olduğunda tek satır eklemek yeterli —
// routing/dashboard/dil desteği hiçbiri değişmeden otomatik çalışır.
export const MODULE_COMPONENTS = {
  idea: IdeaModule,
  research: ResearchModule,
  requirements: RequirementsModule,
  mvp_scope: MvpScopeModule,
  design: DesignModule,
  tech_stack: TechStackModule,
  api_design: ApiDesignModule,
  folder_structure: FolderStructureModule,
  task_plan: TaskPlanModule,
  environment: EnvironmentModule,
  prompt_engineering: PromptEngineeringModule,
  ai_resources: AiResourcesModule,
};
