<?php

use App\Modules\Admin\Http\Controllers\AdminController;
use App\Modules\Admin\Http\Controllers\MockInboundController;
use App\Modules\Channels\Http\Controllers\ChannelAccountController;
use App\Modules\Channels\Http\Controllers\ProviderWebhookController;
use App\Modules\Crm\Http\Controllers\ContactController;
use App\Modules\Crm\Http\Controllers\LeadController;
use App\Modules\Inbox\Http\Controllers\ConversationActionController;
use App\Modules\Platform\Http\Controllers\PlatformAdminController;
use App\Modules\Routing\Http\Controllers\PresenceController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin')->name('home');

// Platform admin console on admin.crm.nttung.dev — out-of-tenant, manages
// workspaces and their first owner. Requires an is_platform_admin account.
Route::domain(config('tenant.admin_subdomain').'.'.config('tenant.domain'))
    ->middleware(['auth', 'verified', 'platform.admin'])
    ->prefix('admin')
    ->name('platform.')
    ->group(function () {
        Route::get('workspaces', [PlatformAdminController::class, 'index'])->name('workspaces');
        Route::post('workspaces', [PlatformAdminController::class, 'store'])->name('workspaces.store');
    });

// Tenant admin app. ResolveWorkspace (global web middleware) pins the tenant
// from the {slug}.crm.nttung.dev subdomain; workspace.required 404s on non-tenant
// hosts; workspace.member enforces the signed-in user belongs to that tenant.
Route::middleware(['workspace.required', 'auth', 'verified', 'workspace.member'])
    ->group(function () {
        Route::get('dashboard', [AdminController::class, 'overview'])->name('dashboard');

        Route::get('admin', [AdminController::class, 'overview'])->name('admin.overview');
        Route::get('admin/inbox', [AdminController::class, 'inbox'])->name('admin.inbox');
        Route::get('api/admin/conversations/{conversation}/messages-older', [AdminController::class, 'messagesOlder'])->name('admin.conversations.messages-older');
        Route::post('api/admin/presence/heartbeat', [PresenceController::class, 'heartbeat'])->name('admin.presence.heartbeat');
        Route::post('api/admin/presence/offline', [PresenceController::class, 'offline'])->name('admin.presence.offline');
        Route::get('admin/contacts', [AdminController::class, 'contacts'])->name('admin.contacts');
        Route::get('admin/contacts/{contact}', [AdminController::class, 'contactShow'])->name('admin.contacts.show');
        Route::post('api/admin/contacts', [ContactController::class, 'store'])->name('admin.contacts.store');
        Route::put('api/admin/contacts/{contact}', [ContactController::class, 'update'])->name('admin.contacts.update');
        Route::delete('api/admin/contacts/{contact}', [ContactController::class, 'destroy'])->name('admin.contacts.destroy');
        Route::post('api/admin/contacts/{contact}/refresh-profile', [ContactController::class, 'refreshProfile'])->name('admin.contacts.refresh-profile');
        Route::put('api/admin/contacts/{contact}/tags', [ContactController::class, 'updateTags'])->name('admin.contacts.tags');
        Route::post('api/admin/contacts/{contact}/notes', [ContactController::class, 'storeNote'])->name('admin.contacts.notes.store');
        Route::delete('api/admin/contact-notes/{note}', [ContactController::class, 'destroyNote'])->name('admin.contacts.notes.destroy');
        Route::get('admin/leads', [AdminController::class, 'leads'])->name('admin.leads');
        Route::put('api/admin/leads/{lead}/status', [LeadController::class, 'updateStatus'])->name('admin.leads.status');
        Route::get('admin/channels', [AdminController::class, 'channels'])->name('admin.channels');
        Route::get('admin/routing', [AdminController::class, 'routing'])->name('admin.routing');

        Route::post('api/admin/mock/inbound', MockInboundController::class)->name('admin.mock-inbound');

        // Cap per-user write bursts on conversation actions (60/min is generous for
        // a human agent, but stops a runaway client from spamming the provider).
        Route::middleware('throttle:60,1')->group(function () {
            Route::post('api/admin/conversations/{conversation}/reply', [ConversationActionController::class, 'reply'])->name('admin.conversations.reply');
            Route::post('api/admin/conversations/{conversation}/comment', [ConversationActionController::class, 'comment'])->name('admin.conversations.comment');
            Route::post('api/admin/conversations/{conversation}/transfer', [ConversationActionController::class, 'transfer'])->name('admin.conversations.transfer');
            Route::post('api/admin/conversations/{conversation}/close', [ConversationActionController::class, 'close'])->name('admin.conversations.close');
            Route::post('api/admin/conversations/{conversation}/reopen', [ConversationActionController::class, 'reopen'])->name('admin.conversations.reopen');
        });

        Route::post('api/admin/channels', [ChannelAccountController::class, 'store'])->name('admin.channels.store');
        Route::put('api/admin/channels/{channelAccount}', [ChannelAccountController::class, 'update'])->name('admin.channels.update');
        Route::post('api/admin/channels/{channelAccount}/register-webhook', [ChannelAccountController::class, 'registerWebhook'])->name('admin.channels.register-webhook');
        Route::post('api/admin/channels/{channelAccount}/zalo-login-qr', [ChannelAccountController::class, 'zaloLoginQr'])->name('admin.channels.zalo-qr');
        Route::get('api/admin/channels/{channelAccount}/zalo-status', [ChannelAccountController::class, 'zaloStatus'])->name('admin.channels.zalo-status');
        Route::post('api/admin/channels/{channelAccount}/zalo-sync', [ChannelAccountController::class, 'zaloSync'])->name('admin.channels.zalo-sync');
        Route::delete('api/admin/channels/{channelAccount}', [ChannelAccountController::class, 'destroy'])->name('admin.channels.destroy');
    });

// Throttle inbound webhooks per IP so a spammer can't flood ingest / bloat the
// DB with fake contacts. 600/min is well above any real provider's send rate.
Route::middleware(['throttle:600,1', 'workspace.channel'])->group(function () {
    Route::post('webhooks/telegram/{channelAccount}', [ProviderWebhookController::class, 'telegram'])->name('webhooks.telegram');
    Route::post('webhooks/zalo/{channelAccount}', [ProviderWebhookController::class, 'zalo'])->name('webhooks.zalo');
    Route::get('webhooks/facebook/{channelAccount}', [ProviderWebhookController::class, 'facebookVerify'])->name('webhooks.facebook.verify');
    Route::post('webhooks/facebook/{channelAccount}', [ProviderWebhookController::class, 'facebook'])->name('webhooks.facebook');
});

require __DIR__.'/settings.php';
