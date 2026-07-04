<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ComplaintAttachmentService
{
    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function storeMany(Complaint $complaint, User $uploadedBy, array $files): void
    {
        foreach ($files as $file) {
            $this->store($complaint, $uploadedBy, $file);
        }
    }

    private function store(Complaint $complaint, User $uploadedBy, UploadedFile $file): void
    {
        $extension = $file->getClientOriginalExtension();
        $fileName = Str::uuid()->toString().($extension ? ".{$extension}" : '');
        $path = $file->storeAs("complaints/{$complaint->id}", $fileName, 'public');

        $complaint->attachments()->create([
            'uploaded_by' => $uploadedBy->id,
            'original_name' => $file->getClientOriginalName(),
            'file_name' => $fileName,
            'file_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'disk' => 'public',
        ]);
    }
}
