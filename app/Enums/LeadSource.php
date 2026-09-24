<?php

namespace App\Enums;

enum LeadSource: string
{
    case Website = 'website';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case Referral = 'referral';
    case Google = 'google';
    case WalkIn = 'walk_in';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Website => 'Website',
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::Referral => 'Referral',
            self::Google => 'Google',
            self::WalkIn => 'Walk-in',
            self::Other => 'Other',
        };
    }
}
