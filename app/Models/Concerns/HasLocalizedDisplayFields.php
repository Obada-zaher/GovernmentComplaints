<?php

namespace App\Models\Concerns;

trait HasLocalizedDisplayFields
{
    public function localizedName(): ?string
    {
        return $this->localizedDisplayValue('name');
    }

    public function localizedDescription(): ?string
    {
        return $this->localizedDisplayValue('description');
    }

    private function localizedDisplayValue(string $attribute): ?string
    {
        if (app()->getLocale() === 'ar') {
            $localized = $this->getAttribute($attribute.'_ar');

            if (is_string($localized) && $localized !== '') {
                return $localized;
            }
        }

        return $this->getAttribute($attribute);
    }
}
