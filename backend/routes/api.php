<?php

use App\Http\Controllers\ActivityFeedController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\AiJobController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckpointController;
use App\Http\Controllers\CodebaseController;
use App\Http\Controllers\CommandAuditController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\FeatureController;
use App\Http\Controllers\FigmaImportController;
use App\Http\Controllers\MayaController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\ModuleItemController;
use App\Http\Controllers\OAuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\QualityController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\TestingController;
use App\Http\Controllers\TwoFactorController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:auth-attempts')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    // Second leg of a login that returned {requires_mfa: true} — relies on
    // the same session cookie login() already started, not auth:sanctum
    // (the user isn't fully authenticated until this succeeds).
    Route::post('/login/mfa', [AuthController::class, 'loginWithMfa']);
});

// Unauthenticated so a not-yet-logged-in invitee can see who/what they're
// being invited to before being asked to register or log in.
Route::get('invitations/{token}', [TeamInvitationController::class, 'show']);

// Unauthenticated by nature — the redirect kicks off login for a
// not-yet-authenticated visitor, and GitHub itself hits the callback
// directly (a real browser navigation, not an XHR from the SPA).
Route::get('oauth/github/redirect', [OAuthController::class, 'redirect']);
Route::get('oauth/github/callback', [OAuthController::class, 'callback']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::patch('profile', [ProfileController::class, 'update']);
    Route::put('profile/password', [ProfileController::class, 'updatePassword']);

    Route::prefix('security/two-factor')->group(function () {
        Route::get('/', [TwoFactorController::class, 'show']);
        Route::post('generate', [TwoFactorController::class, 'generate']);
        Route::post('confirm', [TwoFactorController::class, 'confirm']);
        Route::delete('/', [TwoFactorController::class, 'disable']);
    });

    Route::prefix('security/sessions')->group(function () {
        Route::get('/', [SessionController::class, 'index']);
        Route::delete('others', [SessionController::class, 'destroyOthers']);
        Route::delete('{id}', [SessionController::class, 'destroy']);
    });

    Route::get('security/audit/me', [AuditLogController::class, 'me']);

    Route::get('subscription', [SubscriptionController::class, 'show']);
    Route::patch('subscription', [SubscriptionController::class, 'update']);

    Route::post('invitations/{token}/accept', [TeamInvitationController::class, 'accept']);

    Route::apiResource('teams', TeamController::class)->except(['show']);
    Route::prefix('teams/{team}/members')->group(function () {
        Route::get('/', [TeamMemberController::class, 'index']);
        Route::post('invite', [TeamMemberController::class, 'invite']);
        Route::patch('{member}', [TeamMemberController::class, 'update']);
        Route::delete('{member}', [TeamMemberController::class, 'destroy']);
    });

    Route::apiResource('projects', ProjectController::class);
    Route::patch('projects/{project}/workspace-state', [ProjectController::class, 'updateWorkspaceState']);
    Route::get('modules/{module}', [ModuleController::class, 'show']);
    Route::put('modules/{module}', [ModuleController::class, 'update']);
    Route::apiResource('modules.items', ModuleItemController::class)->shallow();

    Route::prefix('projects/{project}/codebase')->group(function () {
        Route::get('status', [CodebaseController::class, 'status']);
        Route::get('files', [CodebaseController::class, 'files']);
        Route::post('diff', [CodebaseController::class, 'diff']);
        Route::middleware('throttle:ai')->post('index', [CodebaseController::class, 'index']);
    });

    Route::prefix('projects/{project}/features')->group(function () {
        Route::get('/', [FeatureController::class, 'index']);
        Route::get('{featureRequest}', [FeatureController::class, 'show']);
        Route::post('{featureRequest}/plan/approve', [FeatureController::class, 'approvePlan']);
        Route::post('{featureRequest}/diff/approve', [FeatureController::class, 'approveDiff']);
        Route::post('{featureRequest}/apply', [FeatureController::class, 'apply']);

        Route::middleware('throttle:ai')->group(function () {
            Route::post('/', [FeatureController::class, 'store']);
            Route::post('{featureRequest}/generate', [FeatureController::class, 'generate']);
        });
    });

    Route::get('projects/{project}/checkpoints', [CheckpointController::class, 'index']);

    Route::prefix('ai-jobs/{aiJob}')->group(function () {
        Route::get('/', [AiJobController::class, 'show']);
        Route::post('cancel', [AiJobController::class, 'cancel']);
    });

    Route::prefix('projects/{project}/members')->group(function () {
        Route::get('/', [ProjectMemberController::class, 'index']);
        Route::post('/', [ProjectMemberController::class, 'store']);
        Route::delete('{member}', [ProjectMemberController::class, 'destroy']);
    });

    Route::prefix('projects/{project}/comments')->group(function () {
        Route::get('/', [CommentController::class, 'index']);
        Route::post('/', [CommentController::class, 'store']);
        Route::delete('{comment}', [CommentController::class, 'destroy']);
    });

    Route::prefix('projects/{project}/tasks')->group(function () {
        Route::get('/', [TaskController::class, 'index']);
        Route::post('/', [TaskController::class, 'store']);
        Route::patch('{task}', [TaskController::class, 'update']);
        Route::delete('{task}', [TaskController::class, 'destroy']);
    });

    Route::get('projects/{project}/activity', [ActivityFeedController::class, 'index']);
    Route::post('projects/{project}/audit/commands', [CommandAuditController::class, 'store']);
    Route::get('projects/{project}/audit', [AuditLogController::class, 'index']);

    Route::prefix('projects/{project}/maya')->group(function () {
        Route::get('messages', [MayaController::class, 'index']);
        Route::middleware('throttle:ai')->post('messages', [MayaController::class, 'store']);
    });

    Route::prefix('projects/{project}/tests')->group(function () {
        Route::get('/', [TestingController::class, 'index']);
        Route::post('detect', [TestingController::class, 'detect']);
        Route::post('record', [TestingController::class, 'record']);

        Route::middleware('throttle:ai')->group(function () {
            Route::post('generate', [TestingController::class, 'generate']);
            Route::post('{testRun}/suggest-fix', [TestingController::class, 'suggestFix']);
        });
    });

    Route::prefix('projects/{project}/quality')->group(function () {
        Route::get('/', [QualityController::class, 'show']);
        Route::post('detect', [QualityController::class, 'detect']);
        Route::post('scan', [QualityController::class, 'scan']);
    });

    Route::prefix('projects/{project}/deployments')->group(function () {
        Route::get('/', [DeploymentController::class, 'index']);
        Route::post('/', [DeploymentController::class, 'store']);
        Route::patch('{deployment}', [DeploymentController::class, 'update']);
        Route::post('{deployment}/health-check', [DeploymentController::class, 'checkHealth']);

        Route::middleware('throttle:ai')->group(function () {
            Route::post('analyze', [DeploymentController::class, 'analyze']);
            Route::post('generate-config', [DeploymentController::class, 'generateConfig']);
        });
    });

    Route::middleware('throttle:ai')->group(function () {
        Route::prefix('ai')->group(function () {
            Route::post('pitch', [AiController::class, 'pitch']);
            Route::post('competitor-suggestions', [AiController::class, 'competitorSuggestions']);
            Route::post('competitor-analysis', [AiController::class, 'competitorAnalysis']);
            Route::post('user-stories', [AiController::class, 'userStories']);
            Route::post('mvp-recommendation', [AiController::class, 'mvpRecommendation']);
            Route::post('design-system', [AiController::class, 'designSystem']);
            Route::post('tech-stack-recommendation', [AiController::class, 'techStackRecommendation']);
            Route::post('api-endpoints', [AiController::class, 'apiEndpoints']);
            Route::post('scaffold', [AiController::class, 'scaffold']);
            Route::post('sprint-plan', [AiController::class, 'sprintPlan']);
            Route::post('env-setup', [AiController::class, 'envSetup']);
            Route::post('prompt-instructions', [AiController::class, 'promptInstructions']);
            Route::post('tool-recommendations', [AiController::class, 'toolRecommendations']);
            Route::post('package-list', [AiController::class, 'packageList']);
        });

        Route::post('figma/import', [FigmaImportController::class, 'import']);
    });
});
