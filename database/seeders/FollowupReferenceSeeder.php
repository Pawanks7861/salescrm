<?php

namespace Database\Seeders;

use App\Models\FollowupType;
use Illuminate\Database\Seeder;

/**
 * Default follow-up types. Created only when missing (matched by slug) so
 * admin edits survive re-seeding. "Meeting" is intentionally absent: real
 * meetings are a separate module.
 */
class FollowupReferenceSeeder extends Seeder
{
    public const TYPES = [
        ['slug' => 'call', 'name' => 'Call', 'icon' => 'phone', 'color' => 'blue'],
        ['slug' => 'whatsapp', 'name' => 'WhatsApp', 'icon' => 'chat', 'color' => 'green'],
        ['slug' => 'email', 'name' => 'Email', 'icon' => 'envelope', 'color' => 'indigo'],
        ['slug' => 'demo', 'name' => 'Demo', 'icon' => 'video', 'color' => 'purple'],
        ['slug' => 'site_visit', 'name' => 'Site Visit', 'icon' => 'map-pin', 'color' => 'amber'],
        ['slug' => 'other', 'name' => 'Other', 'icon' => 'tag', 'color' => 'slate'],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $i => $type) {
            if (FollowupType::query()->where('slug', $type['slug'])->exists()) {
                continue;
            }

            $model = new FollowupType(['name' => $type['name'], 'icon' => $type['icon'], 'color' => $type['color'], 'sort_order' => ($i + 1) * 10, 'is_active' => true]);
            $model->slug = $type['slug'];
            $model->is_system = true;
            $model->save();
        }
    }
}
