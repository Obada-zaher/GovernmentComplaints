<?php

namespace Database\Factories;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'database_enabled' => true,
            'email_enabled' => true,
            'push_enabled' => true,
            'sms_enabled' => false,
            'complaint_created' => true,
            'complaint_assigned' => true,
            'complaint_status_updated' => true,
            'sla_breached' => true,
            'complaint_resolved' => true,
            'complaint_closed' => true,
        ];
    }
}
