<?php

namespace Database\Seeders;

use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\LostReason;
use Illuminate\Database\Seeder;

/**
 * Default lead statuses, sources and lost reasons. Rows are created only when
 * missing (matched by slug/name) so admin edits survive re-seeding.
 */
class LeadReferenceSeeder extends Seeder
{
    public const STATUSES = [
        ['slug' => 'new', 'name' => 'New', 'color' => 'blue', 'probability' => 10, 'is_default' => true],
        ['slug' => 'contacted', 'name' => 'Contacted', 'color' => 'indigo', 'probability' => 20],
        ['slug' => 'interested', 'name' => 'Interested', 'color' => 'purple', 'probability' => 40],
        ['slug' => 'follow_up', 'name' => 'Follow-up', 'color' => 'amber', 'probability' => 40],
        ['slug' => 'meeting_scheduled', 'name' => 'Meeting Scheduled', 'color' => 'teal', 'probability' => 60],
        ['slug' => 'negotiation', 'name' => 'Negotiation', 'color' => 'orange', 'probability' => 75],
        ['slug' => 'won', 'name' => 'Won', 'color' => 'green', 'probability' => 100, 'is_won' => true],
        ['slug' => 'lost', 'name' => 'Lost', 'color' => 'red', 'probability' => 0, 'is_lost' => true],
    ];

    public const SOURCES = [
        ['slug' => 'manual', 'name' => 'Manual', 'color' => 'slate', 'is_default' => true],
        ['slug' => 'facebook', 'name' => 'Facebook', 'color' => 'blue'],
        ['slug' => 'instagram', 'name' => 'Instagram', 'color' => 'pink'],
        ['slug' => 'website', 'name' => 'Website', 'color' => 'indigo'],
        ['slug' => 'referral', 'name' => 'Referral', 'color' => 'green'],
        ['slug' => 'walk_in', 'name' => 'Walk-in', 'color' => 'amber'],
        ['slug' => 'phone_call', 'name' => 'Phone Call', 'color' => 'teal'],
        ['slug' => 'google_ads', 'name' => 'Google Ads', 'color' => 'orange'],
        ['slug' => 'other', 'name' => 'Other', 'color' => 'slate'],
    ];

    public const LOST_REASONS = [
        'Not interested', 'Budget issue', 'Purchased elsewhere', 'Wrong number',
        'Not reachable', 'Duplicate enquiry', 'Location mismatch', 'Other',
    ];

    public function run(): void
    {
        foreach (self::STATUSES as $i => $status) {
            $model = LeadStatus::firstOrNew(['slug' => $status['slug']]);
            if (! $model->exists) {
                $model->forceFill([
                    'name' => $status['name'],
                    'color' => $status['color'],
                    'probability' => $status['probability'],
                    'sort_order' => ($i + 1) * 10,
                    'is_won' => $status['is_won'] ?? false,
                    'is_lost' => $status['is_lost'] ?? false,
                    'is_default' => $status['is_default'] ?? false,
                    'is_active' => true,
                    'is_system' => true,
                ])->save();
            }
        }

        foreach (self::SOURCES as $i => $source) {
            $model = LeadSource::firstOrNew(['slug' => $source['slug']]);
            if (! $model->exists) {
                $model->forceFill([
                    'name' => $source['name'],
                    'color' => $source['color'],
                    'sort_order' => ($i + 1) * 10,
                    'is_default' => $source['is_default'] ?? false,
                    'is_active' => true,
                    'is_system' => true,
                ])->save();
            }
        }

        foreach (self::LOST_REASONS as $i => $name) {
            LostReason::firstOrCreate(['name' => $name], ['sort_order' => ($i + 1) * 10, 'is_active' => true]);
        }
    }
}
