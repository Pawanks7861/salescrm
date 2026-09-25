<?php

namespace Database\Seeders;

use App\Models\CallDisposition;
use Illuminate\Database\Seeder;

/**
 * Default call dispositions. Created only when missing (matched by slug) so
 * admin edits survive re-seeding. Behaviour keys off the flags, never the name.
 */
class TelephonyReferenceSeeder extends Seeder
{
    public const DISPOSITIONS = [
        ['slug' => 'connected', 'name' => 'Connected', 'color' => 'green', 'contact' => true, 'note' => false, 'next' => false],
        ['slug' => 'interested', 'name' => 'Interested', 'color' => 'teal', 'contact' => true, 'note' => false, 'next' => false],
        ['slug' => 'call_back', 'name' => 'Call Back', 'color' => 'blue', 'contact' => true, 'note' => false, 'next' => true],
        ['slug' => 'no_answer', 'name' => 'No Answer', 'color' => 'amber', 'contact' => false, 'note' => false, 'next' => false],
        ['slug' => 'busy', 'name' => 'Busy', 'color' => 'amber', 'contact' => false, 'note' => false, 'next' => false],
        ['slug' => 'not_interested', 'name' => 'Not Interested', 'color' => 'red', 'contact' => true, 'note' => false, 'next' => false],
        ['slug' => 'wrong_number', 'name' => 'Wrong Number', 'color' => 'slate', 'contact' => false, 'note' => false, 'next' => false],
        ['slug' => 'proposal_required', 'name' => 'Proposal Required', 'color' => 'indigo', 'contact' => true, 'note' => false, 'next' => true],
        ['slug' => 'meeting_required', 'name' => 'Meeting Required', 'color' => 'purple', 'contact' => true, 'note' => false, 'next' => true],
        ['slug' => 'followup_required', 'name' => 'Follow-up Required', 'color' => 'blue', 'contact' => true, 'note' => false, 'next' => true],
        ['slug' => 'converted', 'name' => 'Converted', 'color' => 'green', 'contact' => true, 'note' => false, 'next' => false],
        ['slug' => 'other', 'name' => 'Other', 'color' => 'slate', 'contact' => true, 'note' => true, 'next' => false],
    ];

    public function run(): void
    {
        foreach (self::DISPOSITIONS as $i => $row) {
            if (CallDisposition::query()->where('slug', $row['slug'])->exists()) {
                continue;
            }

            $model = new CallDisposition([
                'name' => $row['name'],
                'color' => $row['color'],
                'is_contact' => $row['contact'],
                'requires_note' => $row['note'],
                'requires_next_action' => $row['next'],
                'is_active' => true,
                'sort_order' => ($i + 1) * 10,
            ]);
            $model->slug = $row['slug'];
            $model->is_system = true;
            $model->save();
        }
    }
}
