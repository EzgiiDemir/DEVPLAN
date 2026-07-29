<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckpointController;
use App\Http\Controllers\CodebaseController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\FeatureController;
use App\Http\Controllers\FigmaImportController;
use App\Http\Controllers\MayaController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\ModuleItemController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\QualityController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TestingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:auth-attempts')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::patch('profile', [ProfileController::class, 'update']);
    Route::put('profile/password', [ProfileController::class, 'updatePassword']);

    Route::get('subscription', [SubscriptionController::class, 'show']);
    Route::patch('subscription', [SubscriptionController::class, 'update']);

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
