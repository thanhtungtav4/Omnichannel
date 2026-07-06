<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Adapters\MockAdapter;
use App\Modules\Channels\Adapters\TelegramAdapter;
use App\Modules\Channels\Adapters\ZaloOaAdapter;
use App\Modules\Channels\Adapters\ZaloPersonalAdapter;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\ChannelAdapterRegistry;
use Tests\TestCase;

class ChannelAdapterRegistryTest extends TestCase
{
    public function test_registry_returns_adapter_for_each_current_provider(): void
    {
        $registry = app(ChannelAdapterRegistry::class);

        $this->assertInstanceOf(
            TelegramAdapter::class,
            $registry->for(ChannelAccount::make(['provider' => 'TELEGRAM'])),
        );

        $this->assertInstanceOf(
            ZaloOaAdapter::class,
            $registry->for(ChannelAccount::make(['provider' => 'ZALO_OA'])),
        );

        $this->assertInstanceOf(
            ZaloPersonalAdapter::class,
            $registry->for(ChannelAccount::make(['provider' => 'ZALO_PERSONAL'])),
        );

        $this->assertInstanceOf(
            MockAdapter::class,
            $registry->for(ChannelAccount::make(['provider' => 'UNKNOWN_TEST_PROVIDER'])),
        );
    }
}
