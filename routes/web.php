<?php

use App\Http\Controllers\MarketingController;
use App\Http\Controllers\MobileProofController;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/health', fn () => response()->json(['status' => 'ok']))->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class]);
Route::get('/payment-upload/{token}', [MobileProofController::class, 'show'])->middleware(['signed', 'throttle:20,1'])->name('payment.mobile');
Route::post('/payment-upload/{token}', [MobileProofController::class, 'store'])->middleware(['signed', 'throttle:10,1']);

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', [MarketingController::class, 'page']);
Route::get('/sitemap.xml', [MarketingController::class, 'sitemap']);
Route::get('/robots.txt', fn () => response("User-agent: *\nDisallow: /api/\nDisallow: /payment-upload/\nSitemap: ".url('/sitemap.xml'))->header('Content-Type', 'text/plain'));
Route::post('/leads', [MarketingController::class, 'lead'])->middleware('throttle:5,1');
Route::get('/login', fn () => redirect(config('app.frontend_url').'/login'))->name('login');
Route::get('/register', fn () => redirect(config('app.frontend_url').'/register'));
Route::get('/{page}', [MarketingController::class, 'page'])->where('page', implode('|', array_keys(MarketingController::PAGES)));
