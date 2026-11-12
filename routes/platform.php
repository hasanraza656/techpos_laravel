<?php

use App\Http\Controllers\Api\Platform\AccessController as Access;
use App\Http\Controllers\Api\Platform\DocumentController as Document;
use App\Http\Controllers\Api\Platform\InventoryController as Inventory;
use App\Http\Controllers\Api\Platform\ManageController as Manage;
use App\Http\Controllers\Api\Platform\ReadController as Read;
use App\Http\Controllers\Api\PlatformController as Legacy;
use Illuminate\Support\Facades\Route;

Route::get('me', [Read::class, 'me']);
Route::get('lookup', [Read::class, 'lookup']);
Route::get('tenants', [Read::class, 'clients'])->middleware('platform.permission:clients.view');
Route::get('payments/{payment}', [Read::class, 'payment'])->middleware('platform.permission:payments.view');
$routes = [
    ['get', 'overview', Read::class, 'overview', 'overview.view'], ['get', 'clients', Read::class, 'clients', 'clients.view'], ['get', 'clients/{client}', Read::class, 'client', 'clients.view'],
    ['post', 'clients', Manage::class, 'createClient', 'clients.manage'], ['put', 'clients/{client}', Manage::class, 'updateClient', 'clients.manage'], ['post', 'clients/{client}/state', Manage::class, 'clientState', 'clients.manage'],
    ['get', 'inventory', Inventory::class, 'index', 'inventory.view'], ['get', 'inventory/{product}/movements', Inventory::class, 'movements', 'inventory.view'], ['get', 'media/{media}', Inventory::class, 'media', 'inventory.view'], ['get', 'client-sales', Inventory::class, 'sales', 'client_sales.view'], ['get', 'client-sales/{sale}/document', Document::class, 'sale', 'client_sales.view'],
    ['get', 'subscriptions', Read::class, 'subscriptions', 'subscriptions.view'], ['get', 'clients/{client}/subscription-events', Read::class, 'events', 'subscriptions.view'], ['post', 'clients/{client}/subscription', Manage::class, 'subscription', 'subscriptions.manage'],
    ['get', 'payments', Read::class, 'payments', 'payments.view'], ['get', 'payments/{payment}/proof', Legacy::class, 'proof', 'payments.view'], ['post', 'payments/{payment}/review', Legacy::class, 'review', 'payments.review'],
    ['get', 'invoices', Read::class, 'invoices', 'billing.view'], ['post', 'invoices', Manage::class, 'issueInvoice', 'billing.manage'], ['post', 'invoices/{invoice}/void', Manage::class, 'voidInvoice', 'billing.manage'], ['get', 'invoices/{invoice}/document', Document::class, 'invoice', 'billing.view'],
    ['get', 'users', Read::class, 'users', 'users.view'], ['get', 'users/{user}', Read::class, 'user', 'users.view'], ['post', 'users', Manage::class, 'createUser', 'users.manage'], ['put', 'users/{user}', Manage::class, 'updateUser', 'users.manage'], ['post', 'users/{user}/state', Manage::class, 'userState', 'users.manage'],
    ['get', 'roles', Access::class, 'index', 'access.view'], ['post', 'roles', Access::class, 'save', 'access.manage'], ['put', 'roles/{role}', Access::class, 'save', 'access.manage'], ['delete', 'roles/{role}', Access::class, 'remove', 'access.manage'], ['post', 'users/{user}/platform-roles', Access::class, 'assignPlatform', 'access.manage'],
    ['get', 'clients/{client}/roles', Access::class, 'tenantIndex', 'client_access.view'], ['post', 'clients/{client}/roles', Access::class, 'tenantSave', 'client_access.manage'], ['put', 'clients/{client}/roles/{role}', Access::class, 'tenantSave', 'client_access.manage'], ['delete', 'clients/{client}/roles/{role}', Access::class, 'tenantRemove', 'client_access.manage'], ['post', 'clients/{client}/memberships', Access::class, 'membership', 'client_access.manage'],
    ['get', 'plans', Legacy::class, 'plans', 'plans.view'], ['put', 'plans/{plan}', Legacy::class, 'updatePlan', 'plans.manage'], ['get', 'entitlements', Manage::class, 'entitlements', 'plans.view'], ['put', 'plans/{plan}/features', Manage::class, 'features', 'plans.manage'],
    ['get', 'payment-methods', Legacy::class, 'methods', 'settings.view'], ['put', 'payment-methods/{method}', Legacy::class, 'updateMethod', 'settings.manage'], ['get', 'settings', Manage::class, 'settings', 'settings.view'], ['put', 'settings', Manage::class, 'saveSettings', 'settings.manage'],
    ['get', 'leads', Read::class, 'leads', 'leads.view'], ['put', 'leads/{lead}', Manage::class, 'lead', 'leads.manage'], ['get', 'audit', Read::class, 'audit', 'audit.view'],
];
foreach ($routes as [$method,$uri,$class,$action,$permission]) {
    Route::$method($uri, [$class, $action])->middleware('platform.permission:'.$permission);
}
