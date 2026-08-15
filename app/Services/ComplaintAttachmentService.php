<?php

namespace App\Services;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ComplaintAttachmentService
{
    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function storeMany(Complaint $complaint, User $uploadedBy, array $files): array
    {
        $storedFiles = [];

        try {
            foreach ($files as $file) {
                $this->store($complaint, $uploadedBy, $file, $storedFiles);
            }
        } catch (Throwable $exception) {
            $this->deleteStoredFiles($storedFiles);

            throw $exception;
        }

        return $storedFiles;
    }

    /**
     * @param  array<int, array{disk: string, path: string}>  $storedFiles
     */
    private function store(Complaint $complaint, User $uploadedBy, UploadedFile $file, array &$storedFiles): void
    {
        $disk = config('gcms.attachments.disk', 'public');
        $extension = $file->getClientOriginalExtension();
        $fileName = Str::uuid()->toString().($extension ? ".{$extension}" : '');
        $path = $file->storeAs("complaints/{$complaint->id}", $fileName, $disk);

        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException('Unable to store the complaint attachment.');
        }

        $storedFiles[] = ['disk' => $disk, 'path' => $path];

        $complaint->attachments()->create([
            'uploaded_by' => $uploadedBy->id,
            'original_name' => $file->getClientOriginalName(),
            'file_name' => $fileName,
            'file_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'disk' => $disk,
        ]);
    }

    /**
     * @param  array<int, array{disk: string, path: string}>  $storedFiles
     */
    public function deleteStoredFiles(array $storedFiles): void
    {
        foreach ($storedFiles as $storedFile) {
            Storage::disk($storedFile['disk'])->delete($storedFile['path']);
        }
    }
}
