<?php

namespace App\Services\Classification;

use App\Models\ClassificationLog;
use App\Models\Complaint;
use App\Models\ComplaintCategory;
use App\Models\ComplaintClassificationRule;
use App\Models\Department;
use Illuminate\Support\Str;

class ComplaintClassificationService
{
    public const METHOD = 'rule_based_weighted_keywords';

    /**
     * This deterministic v1 classifier keeps the API contract stable so a future
     * Python or ML service can replace only this class without changing callers.
     *
     * @return array<string, mixed>
     */
    public function classify(string $title, string $description): array
    {
        $text = $this->normalize($title.' '.$description);
        $rules = ComplaintClassificationRule::query()
            ->with(['department', 'category.department'])
            ->where('is_active', true)
            ->get();

        $departmentScores = [];
        $categoryScores = [];
        $matchedKeywords = [];
        $usedRules = [];
        $totalMatchedScore = 0;

        foreach ($rules as $rule) {
            $keyword = $rule->normalized_keyword ?: $this->normalize($rule->keyword);

            if ($keyword === '' || ! Str::contains($text, $keyword)) {
                continue;
            }

            $weight = max(1, (int) $rule->weight);
            $departmentId = $rule->department_id ?: $rule->category?->department_id;
            $categoryId = $rule->category_id;

            if ($departmentId) {
                $departmentScores[$departmentId] = ($departmentScores[$departmentId] ?? 0) + $weight;
            }

            if ($categoryId) {
                $categoryScores[$categoryId] = ($categoryScores[$categoryId] ?? 0) + $weight;
            }

            $totalMatchedScore += $weight;
            $matchedKeywords[] = $rule->keyword;
            $usedRules[] = [
                'id' => $rule->id,
                'keyword' => $rule->keyword,
                'weight' => $weight,
                'department_id' => $departmentId,
                'category_id' => $categoryId,
            ];
        }

        if ($totalMatchedScore === 0) {
            return $this->emptyResult();
        }

        [$department, $category, $winningScore] = $this->winner($departmentScores, $categoryScores);
        $confidence = $this->confidence($winningScore, $totalMatchedScore);

        return [
            'department' => $this->departmentPayload($department),
            'category' => $this->categoryPayload($category),
            'confidence' => $confidence,
            'matched_keywords' => array_values(array_unique($matchedKeywords)),
            'alternatives' => $this->alternatives($categoryScores, $category?->id, $totalMatchedScore),
            'method' => self::METHOD,
            'scores' => [
                'departments' => $departmentScores,
                'categories' => $categoryScores,
                'total_matched_score' => $totalMatchedScore,
                'winning_score' => $winningScore,
            ],
            'used_rules' => $usedRules,
        ];
    }

    public function normalize(string $text): string
    {
        $text = Str::lower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    public function log(string $title, string $description, array $result, ?Complaint $complaint = null, ?bool $accepted = null): ClassificationLog
    {
        return ClassificationLog::query()->create([
            'complaint_id' => $complaint?->id,
            'title' => $title,
            'description' => $description,
            'predicted_department_id' => $result['department']['id'] ?? null,
            'predicted_category_id' => $result['category']['id'] ?? null,
            'confidence' => $result['confidence'] ?? 0,
            'scores' => $result['scores'] ?? [],
            'used_rules' => $result['used_rules'] ?? [],
            'accepted' => $accepted,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<int|string, int>  $departmentScores
     * @param  array<int|string, int>  $categoryScores
     * @return array{0: Department|null, 1: ComplaintCategory|null, 2: int}
     */
    private function winner(array $departmentScores, array $categoryScores): array
    {
        if ($categoryScores !== []) {
            arsort($categoryScores);
            $categoryId = (int) array_key_first($categoryScores);
            $category = ComplaintCategory::query()->with('department')->find($categoryId);

            return [
                $category?->department,
                $category,
                (int) $categoryScores[$categoryId],
            ];
        }

        arsort($departmentScores);
        $departmentId = (int) array_key_first($departmentScores);

        return [
            Department::query()->find($departmentId),
            null,
            (int) $departmentScores[$departmentId],
        ];
    }

    /**
     * @param  array<int|string, int>  $categoryScores
     * @return array<int, array<string, mixed>>
     */
    private function alternatives(array $categoryScores, ?int $winnerCategoryId, int $totalMatchedScore): array
    {
        if ($categoryScores === []) {
            return [];
        }

        arsort($categoryScores);
        $categoryIds = collect(array_keys($categoryScores))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id !== $winnerCategoryId)
            ->values();

        $categories = ComplaintCategory::query()
            ->with('department')
            ->whereIn('id', $categoryIds)
            ->get()
            ->keyBy('id');

        return $categoryIds
            ->map(function (int $categoryId) use ($categoryScores, $categories, $totalMatchedScore): ?array {
                $category = $categories->get($categoryId);

                if (! $category) {
                    return null;
                }

                return [
                    'department' => $this->departmentPayload($category->department),
                    'category' => $this->categoryPayload($category),
                    'confidence' => $this->confidence((int) $categoryScores[$categoryId], $totalMatchedScore),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(): array
    {
        return [
            'department' => null,
            'category' => null,
            'confidence' => 0.0,
            'matched_keywords' => [],
            'alternatives' => [],
            'method' => self::METHOD,
            'scores' => [
                'departments' => [],
                'categories' => [],
                'total_matched_score' => 0,
                'winning_score' => 0,
            ],
            'used_rules' => [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function departmentPayload(?Department $department): ?array
    {
        if (! $department) {
            return null;
        }

        return [
            'id' => $department->id,
            'name' => $department->name,
            'code' => $department->code,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function categoryPayload(?ComplaintCategory $category): ?array
    {
        if (! $category) {
            return null;
        }

        return [
            'id' => $category->id,
            'name' => $category->name,
            'code' => $category->code,
        ];
    }

    private function confidence(int $winningScore, int $totalMatchedScore): float
    {
        if ($totalMatchedScore === 0) {
            return 0.0;
        }

        return round(($winningScore / $totalMatchedScore) * 100, 2);
    }
}
