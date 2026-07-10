<?php

use App\Modules\Admin\Http\Controllers\AdminController;
use App\Modules\Admin\Http\Controllers\MockInboundController;
use App\Modules\Channels\Http\Controllers\ChannelAccountController;
use App\Modules\Channels\Http\Controllers\ProviderWebhookController;
use App\Modules\Channels\Http\Controllers\ShopeeOAuthController;
use App\Modules\Channels\Http\Controllers\TikTokOAuthController;
use App\Modules\Crm\Http\Controllers\ContactController;
use App\Modules\Crm\Http\Controllers\ContactMergeController;
use App\Modules\Crm\Http\Controllers\IngestTokenAdminController;
use App\Modules\Crm\Http\Controllers\LeadController;
use App\Modules\Crm\Http\Controllers\PublicIngestController;
use App\Modules\Inbox\Http\Controllers\ConversationActionController;
use App\Modules\Inbox\Http\Controllers\OutboundMediaController;
use App\Modules\Inbox\Http\Controllers\QuickReplyController;
use App\Modules\Platform\Http\Controllers\PlatformAdminController;
use App\Modules\Routing\Http\Controllers\PresenceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
    $adminSubdomain = config('tenant.admin_subdomain', 'admin');
    $domain = config('tenant.domain', 'qrf.vn');

    if ($request->getHost() === "{$adminSubdomain}.{$domain}") {
        return redirect()->route('platform.workspaces');
    }

    return redirect('/admin');
})->name('home');

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
        // Merge UI MUST come BEFORE the /{contact} show route — Laravel
        // matches in declaration order, otherwise "merge" is bound as a
        // contact UUID and 404s.
        Route::get('admin/contacts/merge', [ContactMergeController::class, 'index'])
            ->name('admin.contacts.merge');
        Route::get('admin/contacts/{contact}', [AdminController::class, 'contactShow'])->name('admin.contacts.show');

        // Merge UI + JSON endpoints (spec 15 § C5). Owner-only — see
        // ContactMergeController::authorizeMerge.
        Route::get('api/admin/contacts/duplicates', [ContactMergeController::class, 'duplicates'])
            ->name('admin.contacts.duplicates');
        Route::post('api/admin/contacts/{contactId}/merge/preview', [ContactMergeController::class, 'preview'])
            ->name('admin.contacts.merge.preview');
        Route::post('api/admin/contacts/{contactId}/merge', [ContactMergeController::class, 'store'])
            ->name('admin.contacts.merge.store');
        Route::post('api/admin/contacts', [ContactController::class, 'store'])->name('admin.contacts.store');
        Route::put('api/admin/contacts/{contact}', [ContactController::class, 'update'])->name('admin.contacts.update');
        Route::delete('api/admin/contacts/{contact}', [ContactController::class, 'destroy'])->name('admin.contacts.destroy');
        Route::post('api/admin/contacts/{contact}/refresh-profile', [ContactController::class, 'refreshProfile'])->name('admin.contacts.refresh-profile');
        Route::put('api/admin/contacts/{contact}/tags', [ContactController::class, 'updateTags'])->name('admin.contacts.tags');
        Route::get('api/admin/workspaces/{workspace}/tag-vocabulary', [ContactController::class, 'vocabulary'])->name('admin.workspaces.tag-vocabulary');
        Route::post('api/admin/contacts/{contact}/notes', [ContactController::class, 'storeNote'])->name('admin.contacts.notes.store');
        Route::put('api/admin/contact-notes/{note}', [ContactController::class, 'updateNote'])->name('admin.contacts.notes.update');
        Route::delete('api/admin/contact-notes/{note}', [ContactController::class, 'destroyNote'])->name('admin.contacts.notes.destroy');
        // Status + owner are sub-resources of contact; intentionally split
        // from PUT /contacts/{id} so the contact-show UI can update them
        // granularly without resending the whole contact payload.
        Route::put('api/admin/contacts/{contact}/status', [ContactController::class, 'updateStatus'])->name('admin.contacts.status');
        Route::put('api/admin/contacts/{contact}/owner', [ContactController::class, 'updateOwner'])->name('admin.contacts.owner');
        Route::post('api/admin/contacts/{contact}/leads', [LeadController::class, 'createFromContact'])->name('admin.contacts.leads.store');
        Route::get('admin/leads', [AdminController::class, 'leads'])->name('admin.leads');
        Route::put('api/admin/leads/{lead}/status', [LeadController::class, 'updateStatus'])->name('admin.leads.status');
        Route::get('admin/channels', [AdminController::class, 'channels'])->name('admin.channels');
        Route::get('admin/routing', [AdminController::class, 'routing'])->name('admin.routing');

        // Contact ingest token management (spec 15 § C3). Same RBAC as channel
        // accounts — owner/admin only. Settings page is the Inertia route;
        // the JSON endpoints back the buttons (mint/revoke/rotate).
        //
        // The {tokenId} routes use a raw UUID segment (NOT route-model
        // binding) because the WorkspaceScope would 404 a cross-workspace
        // token lookup. The controller resolves the token explicitly with
        // withoutWorkspaceScope() and 403s on workspace mismatch.
        Route::get('admin/settings/integrations', [IngestTokenAdminController::class, 'index'])
            ->name('admin.settings.integrations');
        Route::get('api/admin/ingest-tokens', [IngestTokenAdminController::class, 'list'])
            ->name('admin.ingest-tokens.index');
        Route::post('api/admin/ingest-tokens', [IngestTokenAdminController::class, 'store'])
            ->name('admin.ingest-tokens.store');
        Route::delete('api/admin/ingest-tokens/{tokenId}', [IngestTokenAdminController::class, 'destroy'])
            ->name('admin.ingest-tokens.destroy');
        Route::post('api/admin/ingest-tokens/{tokenId}/rotate', [IngestTokenAdminController::class, 'rotate'])
            ->name('admin.ingest-tokens.rotate');

        // Quick replies (canned responses). Owner/admin only — gated in the
        // controller / QuickReplyRequest. Route-model binding is workspace-safe
        // because QuickReply uses the WorkspaceScope global scope.
        Route::get('admin/settings/quick-replies', [QuickReplyController::class, 'index'])
            ->name('admin.settings.quick-replies');
        Route::post('api/admin/quick-replies', [QuickReplyController::class, 'store'])
            ->name('admin.quick-replies.store');
        Route::put('api/admin/quick-replies/{quickReply}', [QuickReplyController::class, 'update'])
            ->name('admin.quick-replies.update');
        Route::delete('api/admin/quick-replies/{quickReply}', [QuickReplyController::class, 'destroy'])
            ->name('admin.quick-replies.destroy');

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

        // Shopee Chat VN OAuth round-trip (specs/11). The callback is on the
        // tenant host (not webhook.qrf.vn) because it's a Shopee→CRM redirect,
        // not a provider webhook.
        Route::get('admin/channels/shopee/connect', [ShopeeOAuthController::class, 'connect'])
            ->name('admin.channels.shopee.connect');
        Route::get('admin/channels/shopee/callback', [ShopeeOAuthController::class, 'callback'])
            ->name('admin.channels.shopee.callback');

        // TikTok Shop Chat VN OAuth round-trip (specs/13). Same pattern as
        // Shopee: tenant-host, not webhook host.
        Route::get('admin/channels/tiktok/connect', [TikTokOAuthController::class, 'connect'])
            ->name('admin.channels.tiktok.connect');
        Route::get('admin/channels/tiktok/callback', [TikTokOAuthController::class, 'callback'])
            ->name('admin.channels.tiktok.callback');
    });

// Provider webhook ingress. Bind to the dedicated webhook host (webhook.qrf.vn)
// when configured so these routes only answer on the ingress vhost — defense in
// depth on top of the per-account secret / signature verification. The host
// binding is env-gated (APP_WEBHOOK_SUBDOMAIN) so dev/test can run without it.
$webhookRoutes = function (): void {
    Route::middleware(['throttle:600,1', 'workspace.channel'])->group(function () {
        Route::post('webhooks/telegram/{channelAccount}', [ProviderWebhookController::class, 'telegram'])->name('webhooks.telegram');
        Route::post('webhooks/zalo/{channelAccount}', [ProviderWebhookController::class, 'zalo'])->name('webhooks.zalo');
        Route::get('webhooks/facebook/{channelAccount}', [ProviderWebhookController::class, 'facebookVerify'])->name('webhooks.facebook.verify');
        Route::post('webhooks/facebook/{channelAccount}', [ProviderWebhookController::class, 'facebook'])->name('webhooks.facebook');
    });

    // Shopee Chat VN (specs/11). HMAC verification in middleware — must run
    // before the controller body. Mounted INSIDE the webhook host binding so
    // the route only resolves on webhook.qrf.vn (defense in depth).
    Route::middleware(['throttle:600,1', 'workspace.channel', 'shopee.signature'])
        ->post('webhooks/shopee/{channelAccount}', [ProviderWebhookController::class, 'shopee'])
        ->name('webhooks.shopee');

    // TikTok Shop Chat VN (specs/13). HMAC verification in middleware. Same
    // host binding + throttling posture as Shopee. Signature scheme: TikTok
    // Open Platform format (TikTok-Signature: t=<unix>,s=<hex>); to be
    // re-validated against Shop Partner API on first real partner webhook.
    Route::middleware(['throttle:600,1', 'workspace.channel', 'tiktok.signature'])
        ->post('webhooks/tiktok-shop/{channelAccount}', [ProviderWebhookController::class, 'tiktok'])
        ->name('webhooks.tiktok-shop');
};

if (config('tenant.webhook_host')) {
    Route::domain(config('tenant.webhook_host'))->group($webhookRoutes);

    // Zalo Personal sidecar runs on the same VPS and posts to loopback to avoid
    // public CDN/WAF hops. Keep this fallback local-only; public provider
    // webhooks still resolve on the dedicated webhook host above.
    Route::middleware(['throttle:600,1', 'workspace.channel', 'sidecar.loopback'])
        ->post('webhooks/zalo/{channelAccount}', [ProviderWebhookController::class, 'zalo'])
        ->name('webhooks.zalo.loopback');
} else {
    $webhookRoutes();
}

// Public contact-ingest endpoint (specs/15 § C3). OUTSIDE the tenant auth
// stack — no workspace.member, no auth middleware. The workspace is
// resolved from X-Workspace-Key by the ingest.token middleware. CSRF is
// already exempted for this path in bootstrap/app.php.
//
// Middleware order matters:
//   1. ingest.token         — authenticate + pin workspace
//   2. throttle:ingest.token — per-token rate limit (uses the resolved token)
//   3. throttle:ingest.workspace — per-workspace total rate limit (defense in depth)
//   4. ingest.source        — verify X-Source is in token.allowed_sources (403)
//   5. ingest.signature     — HMAC verify (only enforced when source = ZALO_MINIAPP)
Route::middleware([
    'ingest.token',
    'throttle:ingest.token',
    'throttle:ingest.workspace',
    'ingest.source',
    'ingest.signature',
])->group(function () {
    Route::post('api/public/ingest/contact', [PublicIngestController::class, 'ingest'])
        ->name('public.ingest.contact');
});

// Outbound message images: served from the PRIVATE disk behind a signed,
// expiring URL. No auth — the signature is the credential — but tenant-scoped
// (workspace.required 404s off-tenant hosts) so providers (Telegram/Shopee/
// TikTok fetch the URL at send time) and agents both go through one gate.
Route::middleware(['workspace.required', 'signed'])
    ->get('media/outbound/{attachment}', OutboundMediaController::class)
    ->name('media.outbound');

require __DIR__.'/settings.php';
