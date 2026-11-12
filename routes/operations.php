<?php

use App\Http\Controllers\Api\CatalogTransferController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('operations')->group(function () {
    Route::get('catalog/export', [CatalogTransferController::class, 'export']);
    Route::get('bootstrap', [OperationsController::class, 'bootstrap']);
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::get('categories', [ProductController::class, 'categories']);
    Route::get('customers', [CustomerController::class, 'index']);
    Route::get('inventory/movements', [OperationsController::class, 'stock']);
    Route::get('sales/{sale}', [OperationsController::class, 'document']);
    Route::get('sales', [OperationsController::class, 'sales']);
    Route::get('reports/summary', [OperationsController::class, 'summary']);
    Route::get('returns/{return}', [OperationsController::class, 'returnDocument']);
    Route::get('held-sales', [OperationsController::class, 'held']);
    Route::middleware('verified')->group(function () {
        Route::post('catalog/import', [CatalogTransferController::class, 'import'])->middleware('throttle:6,1,catalog-import:');
        Route::match(['POST', 'DELETE'], 'products/{product}/image', [MediaController::class, 'product'])->middleware('throttle:30,1,media-products:');
        Route::post('products', [ProductController::class, 'save']);
        Route::put('products/{product}', [ProductController::class, 'save']);
        Route::post('categories', [ProductController::class, 'createCategory']);
        Route::post('customers', [CustomerController::class, 'save']);
        Route::put('customers/{customer}', [CustomerController::class, 'save']);
        Route::post('inventory/adjustments', [OperationsController::class, 'adjustment']);
        Route::post('shifts', [OperationsController::class, 'open']);
        Route::post('shifts/{shift}/close', [OperationsController::class, 'close']);
        Route::post('shifts/{shift}/cash-events', [OperationsController::class, 'cash']);
        Route::post('sales', [OperationsController::class, 'sale']);
        Route::post('sales/{sale}/returns', [OperationsController::class, 'refund']);
        Route::post('sync', [OperationsController::class, 'sync'])->middleware('throttle:30,1');
        Route::post('held-sales', [OperationsController::class, 'hold']);
        Route::delete('held-sales/{held}', [OperationsController::class, 'discard']);
    });
});
