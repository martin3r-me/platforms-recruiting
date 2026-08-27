<?php

namespace Platform\Recruiting\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecDispoAttachment;

/** HR-seitiger Download eines Anhangs (authentifizierte Dispo-Route, Runde 3 #8). */
class DispoAttachmentHrController extends Controller
{
    public function __invoke(string $uuid)
    {
        $attachment = RecDispoAttachment::query()->where('uuid', $uuid)->firstOrFail();

        return Storage::disk($attachment->disk)->response(
            $attachment->stored_path,
            $attachment->original_filename,
            ['Cache-Control' => 'private, no-store']
        );
    }
}
