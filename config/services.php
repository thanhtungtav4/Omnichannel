<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'zalo_sidecar' => [
        'url' => env('ZALO_SIDECAR_URL', 'http://127.0.0.1:4501'),
        'token' => env('ZALO_SIDECAR_TOKEN', ''),
    ],

    // Shopee Chat VN (specs/11_SHOPEE_CHAT_VN.md). Per-tenant credentials
    // (partner_id, partner_key) live in workspace_settings under
    // shopee.partner_credentials — encrypted at rest. Region and API base
    // are global because they're the same for every VN shop.
    'shopee' => [
        'region' => env('SHOPEE_REGION', 'vn'),
        // Production: https://partner.shopeemobile.com/api/v2/
        // Sandbox:    https://partner.test-shopeemobile.com/api/v2/
        'api_base' => env('SHOPEE_API_BASE', 'https://partner.shopeemobile.com/api/v2'),
        // OAuth scopes — Shopee grants chat + shop info by default.
        // Adjust if cut 2 needs product/order scopes.
        'oauth_scopes' => ['shop_info', 'shop_auth', 'seller_chat'],
    ],

    // TikTok Shop Chat VN (specs/13_TIKTOK_SHOP_VN.md). Per-tenant
    // credentials (app_key, app_secret) live in workspace_settings under
    // tiktok.partner_credentials — encrypted at rest. Region + API base
    // are global (VN only for cut 1).
    //
    // VERIFIED against TikTok Open Platform + TikTok Shop Partner docs:
    //   - Authorization: https://auth.tiktok-shops.com/api/v2/token/authorize
    //   - Token:         https://auth.tiktok-shops.com/api/v2/token/get
    //   - API base:      https://open.tiktokglobalshop.com/api
    //   - Chat endpoints under Customer Service API:
    //       POST /im/202412/send_message
    //       GET  /im/202412/conversations
    //
    // Partner Program: requires TikTok Shop Partner approval + VN market
    // eligibility. See specs/13 § Risks T1/T3.
    'tiktok_shop' => [
        'region' => env('TIKTOK_REGION', 'vn'),
        // Authorization URL — TikTok Shop Seller Center OAuth.
        'auth_base' => env('TIKTOK_AUTH_BASE', 'https://auth.tiktok-shops.com/api/v2'),
        // API call base for actual Shop Partner API requests (chat, orders, etc.)
        'api_base' => env('TIKTOK_API_BASE', 'https://open.tiktokglobalshop.com/api'),
        // OAuth scopes — TikTok Shop Partner splits: shop.info + im.* + order.* etc.
        // Verified from grant response example. Adjust if cut 2 needs more.
        'oauth_scopes' => ['seller.im.message', 'seller.im.basic', 'seller.shop.info'],
    ],

];
