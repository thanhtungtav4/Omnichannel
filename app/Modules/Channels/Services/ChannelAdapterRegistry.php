<?php

namespace App\Modules\Channels\Services;

use App\Modules\Channels\Adapters\FacebookAdapter;
use App\Modules\Channels\Adapters\MockAdapter;
use App\Modules\Channels\Adapters\TelegramAdapter;
use App\Modules\Channels\Adapters\ZaloOaAdapter;
use App\Modules\Channels\Adapters\ZaloPersonalAdapter;
use App\Modules\Channels\Contracts\ChannelAdapter;
use App\Modules\Channels\Models\ChannelAccount;

class ChannelAdapterRegistry
{
    public function __construct(
        private readonly TelegramAdapter $telegram,
        private readonly ZaloOaAdapter $zaloOa,
        private readonly ZaloPersonalAdapter $zaloPersonal,
        private readonly FacebookAdapter $facebook,
        private readonly MockAdapter $mock,
    ) {}

    public function for(ChannelAccount $account): ChannelAdapter
    {
        return match (strtoupper($account->provider)) {
            'TELEGRAM' => $this->telegram,
            'ZALO_OA' => $this->zaloOa,
            'ZALO_PERSONAL' => $this->zaloPersonal,
            'FACEBOOK' => $this->facebook,
            default => $this->mock,
        };
    }
}
