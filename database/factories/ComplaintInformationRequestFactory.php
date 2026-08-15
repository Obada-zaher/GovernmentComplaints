<?php

namespace Database\Factories;

use App\Models\Complaint;
use App\Models\ComplaintInformationRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ComplaintInformationRequest>
 */
class ComplaintInformationRequestFactory extends Factory
{
    protected $model = ComplaintInformationRequest::class;

    public function definition(): array
    {
        return [
            'complaint_id' => Complaint::factory(),
            'requested_by' => User::factory()->employee(),
            'message' => fake()->sentence(),
            'status' => 'pending',
            'requested_at' => now(),
            'responded_at' => null,
            'completed_at' => null,
        ];
    }
}
