<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CountryGroupSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | GEO Groups
        |--------------------------------------------------------------------------
        |
        | These are product-level geographic groups used by Discovery.
        |
        | A country may belong to multiple groups.
        |
        | "worldwide" is special: it means no country restriction and therefore
        | intentionally has no rows in country_group_country.
        |
        */

        $groups = [
            [
                'slug' => 'worldwide',
                'name' => 'Worldwide',
                'countries' => [],
            ],

            /*
            |--------------------------------------------------------------------------
            | Europe
            |--------------------------------------------------------------------------
            */

            [
                'slug' => 'europe',
                'name' => 'Europe',
                'countries' => [
                    'AL', 'AD', 'AT', 'BY', 'BE', 'BA', 'BG', 'HR',
                    'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
                    'HU', 'IS', 'IE', 'IT', 'LV', 'LI', 'LT', 'LU',
                    'MT', 'MD', 'MC', 'ME', 'NL', 'MK', 'NO', 'PL',
                    'PT', 'RO', 'RU', 'SM', 'RS', 'SK', 'SI', 'ES',
                    'SE', 'CH', 'UA', 'GB', 'VA',
                ],
            ],
            [
                'slug' => 'european-union',
                'name' => 'European Union',
                'countries' => [
                    'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE',
                    'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV',
                    'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
                    'SI', 'ES', 'SE',
                ],
            ],
            [
                'slug' => 'balkans',
                'name' => 'Balkans',
                'countries' => [
                    'AL', 'BA', 'BG', 'HR', 'GR', 'ME', 'MK', 'RO',
                    'RS', 'SI',
                ],
            ],
            [
                'slug' => 'eastern-europe',
                'name' => 'Eastern Europe',
                'countries' => [
                    'BY', 'BG', 'CZ', 'HU', 'MD', 'PL', 'RO', 'RU',
                    'SK', 'UA',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Americas
            |--------------------------------------------------------------------------
            */

            [
                'slug' => 'north-america',
                'name' => 'North America',
                'countries' => [
                    'CA', 'MX', 'US',
                ],
            ],
            [
                'slug' => 'latin-america',
                'name' => 'Latin America',
                'countries' => [
                    'AR', 'BO', 'BR', 'CL', 'CO', 'CR', 'CU', 'DO',
                    'EC', 'SV', 'GT', 'HT', 'HN', 'MX', 'NI', 'PA',
                    'PY', 'PE', 'PR', 'UY', 'VE',
                ],
            ],
            [
                'slug' => 'south-america',
                'name' => 'South America',
                'countries' => [
                    'AR', 'BO', 'BR', 'CL', 'CO', 'EC', 'GY', 'PY',
                    'PE', 'SR', 'UY', 'VE',
                ],
            ],
            [
                'slug' => 'central-america',
                'name' => 'Central America',
                'countries' => [
                    'BZ', 'CR', 'SV', 'GT', 'HN', 'NI', 'PA',
                ],
            ],
            [
                'slug' => 'caribbean',
                'name' => 'Caribbean',
                'countries' => [
                    'AG', 'BS', 'BB', 'CU', 'DM', 'DO', 'GD', 'HT',
                    'JM', 'KN', 'LC', 'VC', 'TT',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Middle East & Africa
            |--------------------------------------------------------------------------
            */

            [
                'slug' => 'middle-east',
                'name' => 'Middle East',
                'countries' => [
                    'BH', 'CY', 'EG', 'IR', 'IQ', 'IL', 'JO', 'KW',
                    'LB', 'OM', 'PS', 'QA', 'SA', 'SY', 'TR', 'AE',
                    'YE',
                ],
            ],
            [
                'slug' => 'north-africa',
                'name' => 'North Africa',
                'countries' => [
                    'DZ', 'EG', 'LY', 'MA', 'SD', 'TN',
                ],
            ],
            [
                'slug' => 'sub-saharan-africa',
                'name' => 'Sub-Saharan Africa',
                'countries' => [
                    'AO', 'BJ', 'BW', 'BF', 'BI', 'CV', 'CM', 'CF',
                    'TD', 'KM', 'CG', 'CD', 'CI', 'DJ', 'GQ', 'ER',
                    'SZ', 'ET', 'GA', 'GM', 'GH', 'GN', 'GW', 'KE',
                    'LS', 'LR', 'MG', 'MW', 'ML', 'MR', 'MU', 'MZ',
                    'NA', 'NE', 'NG', 'RW', 'ST', 'SN', 'SC', 'SL',
                    'SO', 'ZA', 'SS', 'TZ', 'TG', 'UG', 'ZM', 'ZW',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Asia
            |--------------------------------------------------------------------------
            */

            [
                'slug' => 'central-asia',
                'name' => 'Central Asia',
                'countries' => [
                    'KZ', 'KG', 'TJ', 'TM', 'UZ',
                ],
            ],
            [
                'slug' => 'south-asia',
                'name' => 'South Asia',
                'countries' => [
                    'AF', 'BD', 'BT', 'IN', 'MV', 'NP', 'PK', 'LK',
                ],
            ],
            [
                'slug' => 'southeast-asia',
                'name' => 'Southeast Asia',
                'countries' => [
                    'BN', 'KH', 'ID', 'LA', 'MY', 'MM', 'PH', 'SG',
                    'TH', 'TL', 'VN',
                ],
            ],
            [
                'slug' => 'east-asia',
                'name' => 'East Asia',
                'countries' => [
                    'CN', 'HK', 'JP', 'MO', 'MN', 'KP', 'KR', 'TW',
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Oceania
            |--------------------------------------------------------------------------
            */

            [
                'slug' => 'oceania',
                'name' => 'Oceania',
                'countries' => [
                    'AU', 'FJ', 'KI', 'MH', 'FM', 'NR', 'NZ', 'PW',
                    'PG', 'WS', 'SB', 'TO', 'TV', 'VU',
                ],
            ],
        ];

        foreach ($groups as $group) {
            $groupId = DB::table('country_groups')->updateOrInsert(
                ['slug' => $group['slug']],
                [
                    'name' => $group['name'],
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $groupRecord = DB::table('country_groups')
                ->where('slug', $group['slug'])
                ->first();

            /*
             * Keep seeding deterministic.
             * If the composition changes later, stale links are removed.
             */
            DB::table('country_group_country')
                ->where('country_group_id', $groupRecord->id)
                ->delete();

            foreach ($group['countries'] as $iso2) {
                $countryId = DB::table('countries')
                    ->where('iso2', $iso2)
                    ->value('id');

                if (!$countryId) {
                    throw new RuntimeException(
                        "Country with ISO2 code {$iso2} does not exist."
                    );
                }

                DB::table('country_group_country')->insert([
                    'country_group_id' => $groupRecord->id,
                    'country_id' => $countryId,
                ]);
            }
        }
    }
}
