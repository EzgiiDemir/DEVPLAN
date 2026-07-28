<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\FigmaImportController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\ModuleItemController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SubscriptionController;
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
    Route::get('modules/{module}', [ModuleController::class, 'show']);
    Route::put('modules/{module}', [ModuleController::class, 'update']);
    Route::apiResource('modules.items', ModuleItemController::class)->shallow();

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
        });

        Route::post('figma/import', [FigmaImportController::class, 'import']);
    });
});
