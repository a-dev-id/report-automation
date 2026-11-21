<?php

namespace Database\Seeders;

use App\Models\Property;
use Illuminate\Database\Seeder;

class PropertySeeder extends Seeder
{
    /**
     * Creates the first two hotels as inactive. Fill in the GA4 property ID, Search Console URL
     * and booking engine rule (in the database or an admin page), then set active to true.
     */
    public function run(): void
    {
        $defaults = config('report.defaults');

        foreach ([
            ['name' => 'Nandini', 'slug' => 'nandini'],
            ['name' => 'Kamandalu', 'slug' => 'kamandalu'],
        ] as $hotel) {
            Property::query()->firstOrCreate(
                ['slug' => $hotel['slug']],
                $hotel + [
                    'active' => false,
                    'currency' => $defaults['currency'],
                    'timezone' => $defaults['timezone'],
                    'language' => $defaults['language'],
                    'report_types' => 'both',
                ],
            );
        }
    }
}
