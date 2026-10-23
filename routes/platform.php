<?php

use App\Http\Controllers\PlatformApiController as Api;
use App\Http\Middleware\ActiveAccount;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [Api::class, 'login']);
    Route::middleware(['auth:sanctum', ActiveAccount::class])->group(function () {
        Route::post('auth/logout', [Api::class, 'logout']);
        Route::match(['GET', 'PATCH'], 'me', [Api::class, 'me']);
        Route::put('me/password', [Api::class, 'password']);
        Route::get('dashboard', [Api::class, 'dashboard']);
        foreach (['profiles', 'plans', 'orders', 'campaigns', 'commands', 'announcements', 'commissions', 'subscription', 'customers', 'automations', 'opt-outs', 'inbox', 'friend-requests', 'friends', 'group-list'] as $resource) {
            Route::get($resource, [Api::class, 'read'])->defaults('resource', $resource);
            if (! in_array($resource, ['subscription', 'announcements'], true)) {
                Route::get($resource.'/{id}', [Api::class, 'read'])->defaults('resource', $resource);
            }
        }
        foreach (['login-qr' => 'qr', 'contacts' => 'contacts', 'groups' => 'groups'] as $path => $resource) {
            Route::get('profiles/{id}/'.$path, [Api::class, 'read'])->defaults('resource', $resource);
        }
        $mutations = [
            ['POST', 'profiles', 'profiles', 'create'], ['PATCH', 'profiles/{id}', 'profiles', 'rename'],
            ['DELETE', 'profiles/{id}', 'profiles', 'delete'], ['POST', 'profiles/{id}/login-qr', 'profiles', 'login'],
            ['POST', 'profiles/{id}/logout', 'profiles', 'logout'], ['POST', 'profiles/{id}/sync', 'profiles', 'sync'],
            ['POST', 'messages', 'inbox', 'reply'], ['POST', 'orders', 'billing', 'create'],
            ['POST', 'orders/{id}/cancel', 'billing', 'cancel'], ['POST', 'commands/{id}/cancel', 'command', 'cancel'],
            ['POST', 'customers', 'contact-lists', 'create'], ['PATCH', 'customers/{id}', 'contact-lists', 'update'],
            ['DELETE', 'customers/{id}', 'contact-lists', 'delete'], ['POST', 'customers/import', 'contact-lists', 'import'],
            ['POST', 'opt-outs', 'opt-outs', 'create'], ['DELETE', 'opt-outs/{id}', 'opt-outs', 'delete'],
            ['POST', 'commissions/{id}/request', 'affiliate', 'request'],
            ['POST', 'friends/sync', 'friends', 'sync'], ['POST', 'friend-requests/{id}/accept', 'friend-requests', 'accept'],
            ['POST', 'inbox/{id}/read', 'inbox', 'read'],
            ['POST', 'campaigns/{id}/pause', 'message-friends', 'pause'], ['POST', 'campaigns/{id}/resume', 'message-friends', 'resume'], ['POST', 'campaigns/{id}/cancel', 'message-friends', 'cancel'],
            ['POST', 'automations/{id}/pause', 'drip', 'pause'], ['POST', 'automations/{id}/activate', 'drip', 'activate'], ['DELETE', 'automations/{id}', 'drip', 'delete'],
            ['POST', 'operations/message-birthdays/rule', 'message-birthdays', 'create-rule'],
        ];
        foreach (['message-friends', 'message-groups', 'message-lists', 'message-phones', 'message-labels', 'message-birthdays', 'friend-invites', 'scan-members', 'scan-links', 'group-invite', 'group-join', 'group-check', 'group-leave', 'automation-labels', 'drip', 'rotation'] as $section) {
            $mutations[] = ['POST', 'operations/'.$section, $section, 'create'];
        }
        foreach ($mutations as [$method, $path, $section, $operation]) {
            Route::match([$method], $path, [Api::class, 'mutate'])->defaults('section', $section)->defaults('operation', $operation);
        }
        Route::prefix('admin')->middleware('role:admin')->group(function () {
            Route::get('dashboard', [Api::class, 'dashboard'])->defaults('admin', true);
            foreach (['users', 'plans', 'orders', 'payments', 'subscriptions', 'profiles', 'commands', 'nodes', 'audit-logs', 'settings', 'announcements', 'proxies', 'commissions'] as $resource) {
                Route::get($resource, [Api::class, 'read'])->defaults('admin', true)->defaults('resource', $resource);
                if (! in_array($resource, ['nodes', 'settings', 'audit-logs'], true)) {
                    Route::get($resource.'/{id}', [Api::class, 'read'])->defaults('admin', true)->defaults('resource', $resource);
                }
            }
            $mutations = [
                ['POST', 'users', 'users', 'create'], ['PATCH', 'users/{id}', 'users', 'update'], ['PUT', 'users/{id}/role', 'users', 'role'],
                ['POST', 'users/{id}/suspend', 'users', 'suspend'], ['POST', 'users/{id}/activate', 'users', 'activate'], ['PUT', 'users/{id}/password', 'users', 'password'],
                ['POST', 'plans', 'plans', 'create'], ['PATCH', 'plans/{id}', 'plans', 'update'], ['POST', 'plans/{id}/archive', 'plans', 'archive'], ['POST', 'plans/{id}/activate', 'plans', 'activate'],
                ['POST', 'orders/{id}/confirm-payment', 'payments', 'confirm'], ['POST', 'orders/{id}/cancel', 'payments', 'cancel'], ['POST', 'orders/{id}/refund', 'payments', 'refund'],
                ['POST', 'nodes/{id}/disable', 'nodes', 'disable'], ['POST', 'nodes/{id}/enable', 'nodes', 'enable'], ['PATCH', 'nodes/default', 'nodes', 'update'],
                ['POST', 'commands/{id}/cancel', 'queue', 'cancel'], ['POST', 'commands/{id}/resolve', 'queue', 'resolve'], ['POST', 'commands/{id}/approve', 'queue', 'approve'],
                ['PATCH', 'settings', 'settings', 'save'], ['POST', 'announcements', 'announcements', 'create'], ['PATCH', 'announcements/{id}', 'announcements', 'update'], ['DELETE', 'announcements/{id}', 'announcements', 'delete'],
                ['POST', 'proxies', 'proxies', 'create'], ['PATCH', 'proxies/{id}', 'proxies', 'update'], ['DELETE', 'proxies/{id}', 'proxies', 'delete'], ['PUT', 'profiles/{id}/proxy', 'profiles', 'proxy'],
                ['POST', 'commissions/{id}/approve', 'affiliate', 'approve'], ['POST', 'commissions/{id}/paid', 'affiliate', 'paid'],
                ['POST', 'moderation/words', 'moderation', 'create'], ['DELETE', 'moderation/words/{id}', 'moderation', 'delete'],
            ];
            foreach ($mutations as [$method, $path, $section, $operation]) {
                Route::match([$method], $path, [Api::class, 'mutate'])->defaults('admin', true)->defaults('section', $section)->defaults('operation', $operation);
            }
        });
    });
});
