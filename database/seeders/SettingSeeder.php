<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\SettingService;
use App\Support\SettingDefinitions;
use Illuminate\Database\Seeder;

/** Inserts missing settings with their defaults; never overwrites existing values. */
class SettingSeeder extends Seeder
{
    public function run(SettingService $settings): void
    {
        foreach (SettingDefinitions::all() as $key => $definition) {
            $default = $definition['default'];

            Setting::firstOrCreate(['key' => $key], [
                'group' => SettingDefinitions::group($key),
                'type' => $definition['type'],
                'value' => match ($definition['type']) {
                    'boolean' => $default ? '1' : '0',
                    'json' => json_encode($default),
                    default => (string) $default,
                },
            ]);
        }

        $settings->flush();
    }
}
