<?php

namespace App\Services\Generation;

use App\Models\GenerationProfile;
use App\Models\Source;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class GenerationSourceSelector
{
    public function select(GenerationProfile $profile): Collection
    {
        $profile->load([
            'categories',
            'countries',
            'countryGroups.countries',
            'languages',
            'sources',
        ]);

        $categoryIds = $profile->categories
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $languageIds = $profile->languages
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $countryIds = $profile->countries
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $groupIds = $profile->countryGroups
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        foreach ($profile->countryGroups as $group) {
            if (!$group->is_active) {
                continue;
            }

            $countryIds = array_merge(
                $countryIds,
                $group->countries
                    ->where('is_active', true)
                    ->pluck('id')
                    ->all()
            );
        }

        $countryIds = array_values(array_unique($countryIds));

        if (
            empty($categoryIds) ||
            empty($languageIds) ||
            (empty($countryIds) && empty($groupIds))
        ) {
            return new Collection();
        }

        return Source::query()
            ->where('status', 'active')
            ->whereIn('language_id', $languageIds)
            ->whereHas('language', function (Builder $query) {
                $query->where('is_active', true);
            })
            ->whereHas('categories', function (Builder $query) use ($categoryIds) {
                $query->whereIn('categories.id', $categoryIds)
                    ->where('categories.is_active', true);
            })
            ->where(function (Builder $query) use ($countryIds, $groupIds) {
                $query->whereHas(
                    'countries',
                    function (Builder $countryQuery) use ($countryIds) {
                        $countryQuery->whereIn('countries.id', $countryIds)
                            ->where('countries.is_active', true);
                    }
                );

                if (!empty($groupIds)) {
                    $query->orWhereHas(
                        'countryGroups',
                        function (Builder $groupQuery) use ($groupIds) {
                            $groupQuery->whereIn('country_groups.id', $groupIds)
                                ->where('country_groups.is_active', true);
                        }
                    );
                }

                if (!empty($countryIds)) {
                    $query->orWhereHas(
                        'countryGroups.countries',
                        function (Builder $countryQuery) use ($countryIds) {
                            $countryQuery->whereIn('countries.id', $countryIds)
                                ->where('countries.is_active', true);
                        }
                    );
                }
            })
            ->when(
                $profile->sources->isNotEmpty(),
                function (Builder $query) use ($profile) {
                    $query->whereIn(
                        'sources.id',
                        $profile->sources->pluck('id')->all()
                    );
                }
            )
            ->with([
                'language',
                'categories',
                'countries',
                'countryGroups.countries',
            ])
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();
    }
}
