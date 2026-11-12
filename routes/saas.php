<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessSetupController;
use App\Http\Controllers\Api\ConfigurationController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Middleware\RejectRevokedSession;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Explicit web middleware supplies sessions and CSRF even for non-browser API clients.
    Route::middleware(['web', 'throttle:api'])->group(function () {
        Route::get('catalog', [PaymentController::class, 'catalog']);
        Route::get('industries', [ConfigurationController::class, 'industries']);
        Route::get('invitations/{token}', [InvitationController::class, 'preview'])->middleware('throttle:20,1');
        Route::post('invitations/{token}/register', [InvitationController::class, 'register'])->middleware('throttle:registration');
        Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:registration');
        Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('auth/two-factor-challenge', [TwoFactorController::class, 'challenge'])->middleware('throttle:10,1');
        Route::post('auth/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:5,1');
        Route::post('auth/reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1');
        Route::middleware(['auth:sanctum', RejectRevokedSession::class])->group(function () {
            Route::get('media/{media}', [MediaController::class, 'show'])->whereUuid('media');
            Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware(['verified', 'throttle:10,1']);
            Route::get('account/sessions', [AccountController::class, 'sessions']);
            Route::get('account/two-factor', [TwoFactorController::class, 'status']);
            Route::middleware(['verified', 'throttle:10,1'])->group(function () {
                Route::post('account/two-factor/setup', [TwoFactorController::class, 'setup']);
                Route::post('account/two-factor/confirm', [TwoFactorController::class, 'confirm']);
                Route::post('account/two-factor/disable', [TwoFactorController::class, 'disable']);
                Route::post('account/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate']);
            });
            Route::post('account/sessions/revoke-others', [AccountController::class, 'revokeOthers'])->middleware('throttle:10,1');
            Route::post('account/sessions/{session}/revoke', [AccountController::class, 'revokeSession'])->middleware('throttle:10,1');
            Route::post('account/devices/{device}/revoke', [AccountController::class, 'revokeDevice'])->middleware('throttle:10,1');
            Route::prefix('platform')->middleware(['platform', 'verified'])->group(function () { require __DIR__.'/platform.php'; });
            Route::get('auth/me', [AuthController::class, 'me']);
            Route::post('auth/logout', [AuthController::class, 'logout']);
            Route::post('auth/verification-notification', [AuthController::class, 'resend'])->middleware('throttle:3,1');
            Route::get('auth/verify-email/{id}/{hash}', function (EmailVerificationRequest $r) {
                $r->fulfill();

                return redirect(config('app.frontend_url').'/');
            })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
            Route::middleware('tenant')->group(function () {
                Route::match(['POST', 'DELETE'], 'business/logo', [MediaController::class, 'logo'])->middleware('throttle:20,1,media-logo:');
                Route::get('business/setup-checklist', [BusinessSetupController::class, 'checklist']);
                Route::get('settings/tax', [BusinessSetupController::class, 'tax']);
                Route::put('settings/tax', [BusinessSetupController::class, 'saveTax'])->middleware('verified');
                require __DIR__.'/operations.php';
                require __DIR__.'/intelligence.php';
                Route::get('invitations', [InvitationController::class, 'index']);
                Route::post('invitations', [InvitationController::class, 'create'])->middleware(['verified', 'throttle:10,1']);
                Route::post('invitations/{invitation}/revoke', [InvitationController::class, 'revoke']);
                Route::post('members/{member}/remove', [InvitationController::class, 'remove']);
                Route::get('onboarding', [ConfigurationController::class, 'onboarding']);
                Route::put('onboarding', [ConfigurationController::class, 'saveOnboarding']);
                Route::get('modules', [ConfigurationController::class, 'modules']);
                Route::put('modules/{key}', [ConfigurationController::class, 'module']);
                Route::get('labels', [ConfigurationController::class, 'labels']);
                Route::put('labels', [ConfigurationController::class, 'saveLabels']);
                Route::post('labels/reset', [ConfigurationController::class, 'resetLabels']);
                Route::get('roles', [RoleController::class, 'index']);
                Route::post('roles', [RoleController::class, 'save']);
                Route::put('roles/{role}', [RoleController::class, 'save']);
                Route::get('members', [RoleController::class, 'members']);
                Route::post('role-assignments', [RoleController::class, 'assign']);
                Route::get('payments', [PaymentController::class, 'index']);
                Route::middleware('verified')->group(function () {
                    Route::post('payments', [PaymentController::class, 'create']);
                    Route::post('payments/{payment}/proof', [PaymentController::class, 'proof']);
                    Route::post('payments/{payment}/submit', [PaymentController::class, 'submit']);
                    Route::post('payments/{payment}/upload-link', [PaymentController::class, 'uploadLink']);
                });
                Route::get('payments/{payment}/proof', [PaymentController::class, 'download']);
                Route::get('business', [TenantController::class, 'show']);
                Route::post('business/switch', [TenantController::class, 'switch']);
                Route::post('branches', [TenantController::class, 'branches'])->middleware('verified');
                Route::post('devices', [TenantController::class, 'device']);
            });
        });
    });
});
