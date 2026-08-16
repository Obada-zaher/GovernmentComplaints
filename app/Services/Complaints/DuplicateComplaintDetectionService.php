<?php

namespace App\Services\Complaints;

use App\Models\Complaint;
use Illuminate\Database\Eloquent\Builder;

class DuplicateComplaintDetectionService
{
    private const EARTH_RADIUS_METERS = 6371008.8;

    /** @var list<string> */
    private const ACTIVE_STATUSES = [
        'submitted',
        'under_review',
        'assigned',
        'in_progress',
        'waiting_citizen',
        'escalated',
    ];

    /**
     * @return list<array{complaint: Complaint, distance_meters: float}>
     */
    public function find(float $latitude, float $longitude, int $categoryId): array
    {
        if ($latitude === 0.0 && $longitude === 0.0) {
            return [];
        }

        $radiusMeters = max((float) config('gcms.duplicate_complaints.radius_meters', 15), 0.0);
        $maxResults = max((int) config('gcms.duplicate_complaints.max_results', 5), 0);

        if ($radiusMeters === 0.0 || $maxResults === 0) {
            return [];
        }

        $candidates = $this->candidateQuery($latitude, $longitude, $categoryId, $radiusMeters)->get();

        $matches = $candidates
            ->map(function (Complaint $complaint) use ($latitude, $longitude): array {
                return [
                    'complaint' => $complaint,
                    'distance_meters' => $this->distanceInMeters(
                        $latitude,
                        $longitude,
                        (float) $complaint->latitude,
                        (float) $complaint->longitude,
                    ),
                ];
            })
            ->filter(fn (array $match): bool => $match['distance_meters'] <= $radiusMeters)
            ->sortBy('distance_meters')
            ->take($maxResults)
            ->values();

        /** @var list<array{complaint: Complaint, distance_meters: float}> $matches */
        $matches = $matches->all();

        return $matches;
    }

    /**
     * @return Builder<Complaint>
     */
    private function candidateQuery(float $latitude, float $longitude, int $categoryId, float $radiusMeters): Builder
    {
        $latitudeDelta = rad2deg($radiusMeters / self::EARTH_RADIUS_METERS);
        $minimumLatitude = max(-90.0, $latitude - $latitudeDelta);
        $maximumLatitude = min(90.0, $latitude + $latitudeDelta);
        $cosineLatitude = abs(cos(deg2rad($latitude)));
        $longitudeDelta = $cosineLatitude < 1.0e-12 ? 180.0 : min(180.0, $latitudeDelta / $cosineLatitude);

        return Complaint::query()
            ->where('category_id', $categoryId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereBetween('latitude', [$minimumLatitude, $maximumLatitude])
            ->when($longitudeDelta < 180.0, function ($query) use ($longitude, $longitudeDelta): void {
                $minimumLongitude = $longitude - $longitudeDelta;
                $maximumLongitude = $longitude + $longitudeDelta;

                if ($minimumLongitude < -180.0) {
                    $query->where(function ($longitudeQuery) use ($minimumLongitude, $maximumLongitude): void {
                        $longitudeQuery
                            ->whereBetween('longitude', [-180.0, $maximumLongitude])
                            ->orWhereBetween('longitude', [$minimumLongitude + 360.0, 180.0]);
                    });

                    return;
                }

                if ($maximumLongitude > 180.0) {
                    $query->where(function ($longitudeQuery) use ($minimumLongitude, $maximumLongitude): void {
                        $longitudeQuery
                            ->whereBetween('longitude', [$minimumLongitude, 180.0])
                            ->orWhereBetween('longitude', [-180.0, $maximumLongitude - 360.0]);
                    });

                    return;
                }

                $query->whereBetween('longitude', [$minimumLongitude, $maximumLongitude]);
            });
    }

    private function distanceInMeters(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $latitudeDelta = deg2rad($latitudeB - $latitudeA);
        $longitudeDelta = deg2rad($longitudeB - $longitudeA);
        $latitudeA = deg2rad($latitudeA);
        $latitudeB = deg2rad($latitudeB);

        $haversine = min(1.0, max(0.0, sin($latitudeDelta / 2) ** 2
            + cos($latitudeA) * cos($latitudeB) * sin($longitudeDelta / 2) ** 2));

        return self::EARTH_RADIUS_METERS * 2 * atan2(sqrt($haversine), sqrt(1 - $haversine));
    }
}
