<?php

return [
    'attachments' => [
        'disk' => env('COMPLAINT_ATTACHMENTS_DISK', 'public'),
    ],

    'duplicate_complaints' => [
        'radius_meters' => (float) env('DUPLICATE_COMPLAINT_RADIUS_METERS', 15),
        'max_results' => (int) env('DUPLICATE_COMPLAINT_MAX_RESULTS', 5),
    ],
];
