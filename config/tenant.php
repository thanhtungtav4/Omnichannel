<?php

return [
    // Root domain that tenant subdomains hang off, e.g. acme.qrf.vn.
    // Local/dev uses the CF tunnel host; override per environment via env.
    'domain' => env('APP_TENANT_DOMAIN', 'qrf.vn'),

    // Subdomain reserved for the out-of-tenant platform admin console.
    'admin_subdomain' => env('APP_ADMIN_SUBDOMAIN', 'admin'),
];
