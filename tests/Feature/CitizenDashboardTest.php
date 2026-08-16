<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\Department;
use App\Models\Priority;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CitizenDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/citizen/dashboard';

    public function test_dashboard_requires_an_authenticated_citizen(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();

        Sanctum::actingAs(User::factory()->employee()->create());
        $this->getJson(self::ENDPOINT)->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson(self::ENDPOINT)->assertForbidden();
    }

    public function test_dashboard_returns_citizen_scoped_counts_bounded_ordered_summaries_and_safe_serialization(): void
    {
        $citizen = User::factory()->citizen()->create();
        $otherCitizen = User::factory()->citizen()->create();
        [$department, $category, $priority] = $this->references();

        foreach (['submitted', 'under_review', 'assigned', 'in_progress', 'escalated', 'resolved', 'closed', 'rejected'] as $status) {
            $this->complaint($citizen, $department, $category, $priority, [
                'status' => $status,
                'created_at' => Carbon::parse('2026-01-01 08:00:00', 'UTC'),
                'updated_at' => Carbon::parse('2026-01-01 08:00:00', 'UTC'),
            ]);
        }

        $waitingComplaints = [];

        foreach ([
            '2026-05-01 10:00:00',
            '2026-05-02 10:00:00',
            '2026-05-03 10:00:00',
            '2026-05-04 10:00:00',
            '2026-05-05 10:00:00',
            '2026-05-05 10:00:00',
        ] as $index => $updatedAt) {
            $waitingComplaints[] = $this->complaint($citizen, $department, $category, $priority, [
                'title' => 'Action required '.($index + 1),
                'status' => 'waiting_citizen',
                'created_at' => Carbon::parse('2026-02-01 08:00:00', 'UTC'),
                'updated_at' => Carbon::parse($updatedAt, 'UTC'),
            ]);
        }

        $oldRecent = $this->complaint($citizen, $department, $category, $priority, [
            'title' => 'Old recent complaint',
            'created_at' => Carbon::parse('2026-12-01 10:00:00', 'UTC'),
        ]);
        $tieOlder = $this->complaint($citizen, $department, $category, $priority, [
            'title' => 'Earlier ID at tied timestamp',
            'created_at' => Carbon::parse('2026-12-02 10:00:00', 'UTC'),
        ]);
        $tieNewer = $this->complaint($citizen, $department, $category, $priority, [
            'title' => 'Later ID at tied timestamp',
            'created_at' => Carbon::parse('2026-12-02 10:00:00', 'UTC'),
        ]);
        $middleRecent = $this->complaint($citizen, $department, $category, $priority, [
            'title' => 'Middle recent complaint',
            'created_at' => Carbon::parse('2026-12-03 10:00:00', 'UTC'),
        ]);
        $dueAt = Carbon::parse('2026-12-10 14:30:00', 'UTC');
        $serializedComplaint = $this->complaint($citizen, $department, $category, $priority, [
            'title' => 'Serialized recent complaint',
            'created_at' => Carbon::parse('2026-12-04 10:00:00', 'UTC'),
            'due_at' => $dueAt,
            'is_sla_breached' => true,
        ]);
        $nullableRelationships = $this->complaint($citizen, $department, $category, $priority, [
            'title' => 'Nullable related data complaint',
            'department_id' => null,
            'category_id' => null,
            'priority_id' => null,
            'created_at' => Carbon::parse('2026-12-05 10:00:00', 'UTC'),
        ]);

        $deletedComplaint = $this->complaint($citizen, $department, $category, $priority, ['status' => 'submitted']);
        $deletedComplaint->delete();

        $this->complaint($otherCitizen, $department, $category, $priority, [
            'title' => 'Other citizen waiting complaint',
            'status' => 'waiting_citizen',
            'created_at' => Carbon::parse('2026-12-31 10:00:00', 'UTC'),
            'updated_at' => Carbon::parse('2026-12-31 10:00:00', 'UTC'),
        ]);
        $this->complaint($otherCitizen, $department, $category, $priority, ['status' => 'resolved']);

        Sanctum::actingAs($citizen);
        $response = $this->getJson(self::ENDPOINT.'?citizen_id='.$otherCitizen->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Dashboard retrieved successfully.')
            ->assertJsonPath('data.counts.total', 20)
            ->assertJsonPath('data.counts.active', 17)
            ->assertJsonPath('data.counts.waiting_citizen', 6)
            ->assertJsonPath('data.counts.completed', 2)
            ->assertJsonCount(5, 'data.recent_complaints')
            ->assertJsonCount(5, 'data.action_required')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'counts' => ['total', 'active', 'waiting_citizen', 'completed'],
                    'recent_complaints' => [[
                        'id', 'complaint_number', 'title', 'status',
                        'department', 'category', 'priority', 'created_at', 'updated_at', 'due_at', 'is_sla_breached',
                    ]],
                    'action_required' => [[
                        'id', 'complaint_number', 'title', 'status', 'updated_at',
                    ]],
                ],
                'meta',
            ]);

        $this->assertSame([
            $nullableRelationships->id,
            $serializedComplaint->id,
            $middleRecent->id,
            $tieNewer->id,
            $tieOlder->id,
        ], array_column($response->json('data.recent_complaints'), 'id'));
        $this->assertSame([
            $waitingComplaints[5]->id,
            $waitingComplaints[4]->id,
            $waitingComplaints[3]->id,
            $waitingComplaints[2]->id,
            $waitingComplaints[1]->id,
        ], array_column($response->json('data.action_required'), 'id'));
        $this->assertNotContains($oldRecent->id, array_column($response->json('data.recent_complaints'), 'id'));
        $this->assertNotContains($otherCitizen->id, array_column($response->json('data.recent_complaints'), 'citizen_id'));

        $serializedItem = collect($response->json('data.recent_complaints'))->firstWhere('id', $serializedComplaint->id);
        $nullableItem = collect($response->json('data.recent_complaints'))->firstWhere('id', $nullableRelationships->id);

        $this->assertSame([
            'id' => $department->id,
            'name' => 'Road Services',
        ], $serializedItem['department']);
        $this->assertSame([
            'id' => $category->id,
            'name' => 'Road Damage',
        ], $serializedItem['category']);
        $this->assertSame([
            'id' => $priority->id,
            'name' => 'High',
            'color' => '#ef4444',
        ], $serializedItem['priority']);
        $this->assertSame($dueAt->toISOString(), $serializedItem['due_at']);
        $this->assertTrue($serializedItem['is_sla_breached']);
        $this->assertNull($nullableItem['department']);
        $this->assertNull($nullableItem['category']);
        $this->assertNull($nullableItem['priority']);
        $this->assertSame('waiting_citizen', $response->json('data.action_required.0.status'));

        $this->withHeader('Accept-Language', 'ar')->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('message', 'تم جلب لوحة معلومات المواطن بنجاح.')
            ->assertJsonPath('data.recent_complaints.1.department.name', 'خدمات الطرق')
            ->assertJsonPath('data.recent_complaints.1.category.name', 'أعطال الطرق')
            ->assertJsonPath('data.recent_complaints.1.priority.name', 'عالية');
    }

    /** @return array{0: Department, 1: ComplaintCategory, 2: Priority} */
    private function references(): array
    {
        $department = Department::factory()->create([
            'name' => 'Road Services',
            'name_ar' => 'خدمات الطرق',
        ]);
        $category = ComplaintCategory::factory()->create([
            'department_id' => $department->id,
            'name' => 'Road Damage',
            'name_ar' => 'أعطال الطرق',
        ]);
        $priority = Priority::factory()->create([
            'name' => 'High',
            'name_ar' => 'عالية',
            'color' => '#ef4444',
        ]);

        return [$department, $category, $priority];
    }

    /** @param array<string, mixed> $attributes */
    private function complaint(User $citizen, Department $department, ComplaintCategory $category, Priority $priority, array $attributes = []): Complaint
    {
        return Complaint::factory()->create(array_merge([
            'citizen_id' => $citizen->id,
            'department_id' => $department->id,
            'category_id' => $category->id,
            'priority_id' => $priority->id,
        ], $attributes));
    }
}
