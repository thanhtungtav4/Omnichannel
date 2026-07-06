<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\InboundMessageIngestor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MockInboundController extends Controller
{
    public function __invoke(Request $request, InboundMessageIngestor $ingestor): RedirectResponse
    {
        $data = $request->validate([
            'provider' => ['nullable', 'in:TELEGRAM,ZALO_PERSONAL,ZALO_OA,FACEBOOK,MOCK'],
            'sender_name' => ['nullable', 'string', 'max:120'],
            'text' => ['nullable', 'string', 'max:1000'],
        ]);

        $account = ChannelAccount::query()
            ->where('workspace_id', $request->user()->workspace_id)
            ->where('provider', $data['provider'] ?? 'TELEGRAM')
            ->firstOrFail();

        $senderId = 'demo-'.str()->lower(str()->random(8));
        $senderName = $data['sender_name'] ?? 'Khách demo';
        $text = $data['text'] ?? 'Tôi cần tư vấn thêm về dịch vụ.';

        $ingestor->ingest($account, $this->payloadForProvider($account, $senderId, $senderName, $text), [
            'x-crm-demo' => ['true'],
        ]);

        return back()->with('success', 'Mock inbound message created.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadForProvider(ChannelAccount $account, string $senderId, string $senderName, string $text): array
    {
        if ($account->provider === 'TELEGRAM') {
            return [
                'update_id' => random_int(100000, 999999),
                'message' => [
                    'message_id' => random_int(100000, 999999),
                    'date' => time(),
                    'from' => [
                        'id' => $senderId,
                        'first_name' => $senderName,
                        'is_bot' => false,
                    ],
                    'chat' => [
                        'id' => $senderId,
                        'type' => 'private',
                    ],
                    'text' => $text,
                ],
            ];
        }

        if ($account->provider === 'ZALO_OA' || $account->provider === 'ZALO_PERSONAL') {
            return [
                'event_name' => 'user_send_text',
                'timestamp' => now()->getTimestampMs(),
                'sender' => [
                    'id' => $senderId,
                    'name' => $senderName,
                ],
                'message' => [
                    'msg_id' => (string) str()->uuid(),
                    'text' => $text,
                ],
            ];
        }

        return [
            'event_id' => (string) str()->uuid(),
            'sender_id' => $senderId,
            'sender_name' => $senderName,
            'text' => $text,
        ];
    }
}
