<?php

namespace Database\Seeders;

use App\Models\MeetingType;
use Illuminate\Database\Seeder;

/**
 * Default meeting types. Created only when missing (matched by slug) so admin
 * edits survive re-seeding. Behaviour keys off location_mode, never the name.
 */
class MeetingReferenceSeeder extends Seeder
{
    public const TYPES = [
        ['slug' => 'office_meeting', 'name' => 'Office Meeting', 'icon' => 'building', 'color' => 'blue', 'mode' => 'physical', 'duration' => 60],
        ['slug' => 'client_office', 'name' => 'Client Office', 'icon' => 'briefcase', 'color' => 'indigo', 'mode' => 'physical', 'duration' => 60],
        ['slug' => 'video_meeting', 'name' => 'Video Meeting', 'icon' => 'video', 'color' => 'purple', 'mode' => 'online', 'duration' => 30],
        ['slug' => 'google_meet', 'name' => 'Google Meet', 'icon' => 'video', 'color' => 'green', 'mode' => 'online', 'duration' => 30],
        ['slug' => 'zoom', 'name' => 'Zoom', 'icon' => 'video', 'color' => 'teal', 'mode' => 'online', 'duration' => 30],
        ['slug' => 'microsoft_teams', 'name' => 'Microsoft Teams', 'icon' => 'video', 'color' => 'indigo', 'mode' => 'online', 'duration' => 30],
        ['slug' => 'phone_call', 'name' => 'Phone Call', 'icon' => 'phone', 'color' => 'orange', 'mode' => 'phone', 'duration' => 30],
        ['slug' => 'site_visit', 'name' => 'Site Visit', 'icon' => 'map-pin', 'color' => 'amber', 'mode' => 'physical', 'duration' => 90],
        ['slug' => 'product_demo', 'name' => 'Product Demo', 'icon' => 'presentation', 'color' => 'pink', 'mode' => 'flexible', 'duration' => 60],
        ['slug' => 'other', 'name' => 'Other', 'icon' => 'calendar', 'color' => 'slate', 'mode' => 'flexible', 'duration' => 30],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $i => $type) {
            if (MeetingType::query()->where('slug', $type['slug'])->exists()) {
                continue;
            }

            $model = new MeetingType([
                'name' => $type['name'],
                'icon' => $type['icon'],
                'color' => $type['color'],
                'location_mode' => $type['mode'],
                'default_duration_minutes' => $type['duration'],
                'sort_order' => ($i + 1) * 10,
                'is_active' => true,
            ]);
            $model->slug = $type['slug'];
            $model->is_system = true;
            $model->save();
        }
    }
}
