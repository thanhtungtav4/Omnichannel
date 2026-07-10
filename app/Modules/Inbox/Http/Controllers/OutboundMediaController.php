<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inbox\Models\MessageAttachment;
use App\Modules\Platform\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves outbound message images from the PRIVATE disk behind a signed,
 * expiring URL. Files are never world-readable via /storage; every fetch
 * (provider pull at send time, agent viewing the thread) goes through here so
 * a leaked URL stops working once the signature expires.
 *
 * The URL is signed (tamper-proof + time-boxed). We additionally require the
 * attachment to belong to the current workspace so a signed URL minted for one
 * tenant can't be replayed against another tenant's files.
 */
class OutboundMediaController extends Controller
{
    public function __invoke(Request $request, MessageAttachment $attachment, CurrentWorkspace $workspace): StreamedResponse
    {
        // Signature is validated by the 'signed' middleware on the route.
        // Workspace fence: the file must belong to the resolved tenant.
        abort_unless(
            $workspace->id() !== null && $attachment->workspace_id === $workspace->id(),
            404,
        );

        $path = $attachment->metadata['path'] ?? null;
        abort_unless(is_string($path) && $path !== '' && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response(
            $path,
            null,
            ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream'],
        );
    }
}
