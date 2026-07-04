<?php

namespace Database\Factories;

use App\Models\Complaint;
use App\Models\ComplaintAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ComplaintAttachment>
 */
class ComplaintAttachmentFactory extends Factory
{
    public function definition(): array
    {
        $fileName = fake()->uuid().'.jpg';

        return [
            'complaint_id' => Complaint::factory(),
            'uploaded_by' => User::factory(),
            'original_name' => fake()->word().'.jpg',
            'file_name' => $fileName,
            'file_path' => 'complaints/'.$fileName,
            'mime_type' => 'image/jpeg',
            'file_size' => fake()->numberBetween(50_000, 5_000_000),
            'disk' => 'public',
        ];
    }
}
