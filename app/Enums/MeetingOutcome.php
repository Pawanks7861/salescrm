<?php

namespace App\Enums;

enum MeetingOutcome: string
{
    case Interested = 'interested';
    case FollowUpRequired = 'follow_up_required';
    case ProposalRequired = 'proposal_required';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';
    case NoShow = 'no_show';
    case NotInterested = 'not_interested';
    case DemoCompleted = 'demo_completed';
    case DecisionPending = 'decision_pending';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FollowUpRequired => 'Follow-up required',
            self::ProposalRequired => 'Proposal required',
            self::NoShow => 'No show',
            self::NotInterested => 'Not interested',
            self::DemoCompleted => 'Demo completed',
            self::DecisionPending => 'Decision pending',
            default => ucfirst($this->value),
        };
    }

    /** Whether the meeting proves the lead was reached (updates leads.last_contacted_at). */
    public function countsAsContact(): bool
    {
        return ! in_array($this, [self::NoShow, self::Other], true);
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $o) => ['value' => $o->value, 'label' => $o->label()], self::cases());
    }
}
