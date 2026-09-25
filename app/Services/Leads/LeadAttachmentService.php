<?php

namespace App\Services\Leads;

use App\Enums\AuditAction;
use App\Models\Attachment;
use App\Models\Lead;
use App\Models\User;
use App\Services\ActivityService;
use App\Services\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lead files live on the private "local" disk (storage/app/private) under a
 * random name and are only ever served through the authorised download route.
 */
class LeadAttachmentService
{
    public const DISK = 'local';

    public const MAX_KILOBYTES = 10240;

    public const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly ActivityService $activities,
        private readonly AuditService $audit,
    ) {}

    public function store(Lead $lead, UploadedFile $file, User $actor): Attachment
    {
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $storedName = Str::uuid()->toString().'.'.$extension;
        $directory = "leads/{$lead->id}";

        $path = $file->storeAs($directory, $storedName, self::DISK);

        try {
            return DB::transaction(function () use ($lead, $file, $actor, $storedName, $path) {
                $attachment = new Attachment;
                $attachment->forceFill([
                    'attachable_type' => $lead->getMorphClass(),
                    'attachable_id' => $lead->id,
                    'original_name' => Str::limit($this->safeName($file->getClientOriginalName()), 250, ''),
                    'stored_name' => $storedName,
                    'disk' => self::DISK,
                    'path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'uploaded_by' => $actor->id,
                ])->save();

                $this->activities->record($lead, ActivityService::ATTACHMENT_UPLOADED, "Uploaded {$attachment->original_name}", ['attachment_id' => $attachment->id]);
                $this->audit->log(AuditAction::LeadAttachmentUploaded, 'leads', $attachment, "File uploaded to {$lead->lead_number}", null, [
                    'lead_id' => $lead->id, 'name' => $attachment->original_name, 'size' => $attachment->size, 'mime' => $attachment->mime_type,
                ]);

                return $attachment;
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path);
            throw $e;
        }
    }

    public function download(Attachment $attachment, Lead $lead): StreamedResponse
    {
        $this->audit->log(AuditAction::LeadAttachmentDownloaded, 'leads', $attachment, "File downloaded from {$lead->lead_number}", null, [
            'lead_id' => $lead->id, 'name' => $attachment->original_name,
        ]);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function delete(Attachment $attachment, Lead $lead): void
    {
        DB::transaction(function () use ($attachment, $lead) {
            $attachment->delete();

            $this->activities->record($lead, ActivityService::ATTACHMENT_DELETED, "Deleted {$attachment->original_name}", ['attachment_id' => $attachment->id]);
            $this->audit->log(AuditAction::LeadAttachmentDeleted, 'leads', $attachment, "File deleted from {$lead->lead_number}", null, [
                'lead_id' => $lead->id, 'name' => $attachment->original_name,
            ]);
        });
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[^\pL\pN\s._()-]+/u', '_', $name) ?? 'file';

        return trim($name) !== '' ? trim($name) : 'file';
    }
}
