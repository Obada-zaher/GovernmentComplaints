<?php

namespace Database\Factories;

use App\Models\Complaint;
use App\Models\NotificationDeliveryLog;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationDeliveryLog>
 */
class NotificationDeliveryLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'user_notification_id' => UserNotification::factory(),
            'complaint_id' => Complaint::factory(),
            'channel' => fake()->randomElement(['database', 'email', 'push', 'sms']),
            'type' => fake()->randomElement([
                'complaint_created',
                'complaint_assigned',
                'complaint_status_updated',
                'sla_breached',
                'complaint_resolved',
                'complaint_closed',
            ]),
            'recipient' => fake()->safeEmail(),
            'status' => fake()->randomElement(['pending', 'sent', 'failed', 'skipped']),
            'provider' => 'fake',
            'provider_message_id' => fake()->optional()->uuid(),
            'error_message' => null,
            'payload' => ['test' => true],
            'sent_at' => now(),
            'failed_at' => null,
        ];
    }
}
