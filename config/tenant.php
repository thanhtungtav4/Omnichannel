<?php

return [
    // Root domain that tenant subdomains hang off, e.g. acme.qrf.vn.
    // Local/dev uses the CF tunnel host; override per environment via env.
    'domain' => env('APP_TENANT_DOMAIN', 'qrf.vn'),

    // Subdomain reserved for the out-of-tenant platform admin console.
    'admin_subdomain' => env('APP_ADMIN_SUBDOMAIN', 'admin'),

    // Subdomain dedicated to provider webhook ingress. Resolved by the webhook
    // nginx vhost and locked down by routes/web.php (Route::domain). Setting
    // this to empty/null disables the host binding (dev/test convenience).
    // Production convention: 'webhook' -> webhook.qrf.vn.
    'webhook_subdomain' => env('APP_WEBHOOK_SUBDOMAIN'),

    // Fully qualified webhook host, computed once so callers don't repeat the
    // concatenation. Null when no webhook subdomain is configured.
    'webhook_host' => env('APP_WEBHOOK_SUBDOMAIN')
        ? env('APP_WEBHOOK_SUBDOMAIN').'.'.env('APP_TENANT_DOMAIN', 'qrf.vn')
        : null,
];
