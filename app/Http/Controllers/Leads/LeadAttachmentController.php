<?php

namespace App\Http\Controllers\Leads;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Lead;
use App\Services\Leads\LeadAttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadAttachmentController extends Controller
{
    public function __construct(private readonly LeadAttachmentService $attachments) {}

    public function store(Request $request, Lead $lead): RedirectResponse
    {
        $this->authorize('uploadAttachment', $lead);

        $request->validate([
            'file' => [
                'required', 'file',
                'max:'.LeadAttachmentService::MAX_KILOBYTES,
                'mimes:'.implode(',', LeadAttachmentService::ALLOWED_EXTENSIONS),
                'extensions:'.implode(',', LeadAttachmentService::ALLOWED_EXTENSIONS),
            ],
        ]);

        $attachment = $this->attachments->store($lead, $request->file('file'), $request->user());

        return back()->with('success', "Uploaded {$attachment->original_name}.");
    }

    public function download(Request $request, Lead $lead, Attachment $attachment): StreamedResponse
    {
        $this->ensureBelongs($lead, $attachment);
        $this->authorize('downloadAttachment', $lead);

        return $this->attachments->download($attachment, $lead);
    }

    public function destroy(Request $request, Lead $lead, Attachment $attachment): RedirectResponse
    {
        $this->ensureBelongs($lead, $attachment);
        $this->authorize('deleteAttachment', [$lead, $attachment]);

        $this->attachments->delete($attachment, $lead);

        return back()->with('success', 'Attachment deleted.');
    }

    private function ensureBelongs(Lead $lead, Attachment $attachment): void
    {
        abort_unless(
            $attachment->attachable_type === $lead->getMorphClass() && (int) $attachment->attachable_id === $lead->id,
            404,
        );
    }
}
