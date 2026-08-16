<?php

namespace App\Http\Requests\Api\V1\Citizen;

use App\Models\ComplaintCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CheckDuplicateComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('complaint_categories', 'id')->where(
                    fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'),
                ),
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('category_id')) {
                return;
            }

            $categoryIsAvailable = ComplaintCategory::query()
                ->whereKey($this->integer('category_id'))
                ->where('is_active', true)
                ->whereHas('department', fn ($query) => $query->where('is_active', true))
                ->exists();

            if (! $categoryIsAvailable) {
                $validator->errors()->add('category_id', 'The selected category is invalid.');
            }
        }];
    }
}
