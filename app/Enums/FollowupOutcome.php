<?php

namespace App\Enums;

enum FollowupOutcome: string
{
    case Connected = 'connected';
    case NoAnswer = 'no_answer';
    case Busy = 'busy';
    case Interested = 'interested';
    case NotInterested = 'not_interested';
    case CallBackLater = 'call_back_later';
    case WhatsappSent = 'whatsapp_sent';
    case EmailSent = 'email_sent';
    case DemoRequired = 'demo_required';
    case ProposalRequired = 'proposal_required';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NoAnswer => 'No answer',
            self::NotInterested => 'Not interested',
            self::CallBackLater => 'Call back later',
            self::WhatsappSent => 'WhatsApp sent',
            self::EmailSent => 'Email sent',
            self::DemoRequired => 'Demo required',
            self::ProposalRequired => 'Proposal required',
            default => ucfirst($this->value),
        };
    }

    /**
     * Whether the outcome proves the lead was actually reached, which updates
     * `leads.last_contacted_at`. Unanswered attempts (no answer, busy) and
     * "other" do not count.
     */
    public function countsAsContact(): bool
    {
        return ! in_array($this, [self::NoAnswer, self::Busy, self::Other], true);
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $o) => ['value' => $o->value, 'label' => $o->label()], self::cases());
    }
}
