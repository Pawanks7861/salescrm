<?php

namespace App\Enums;

/**
 *   provider        — audio stays with the provider; the CRM keeps an encrypted reference and proxies playback
 *   private_storage — audio archived to the CRM's private disk
 */
enum CallRecordingStorage: string
{
    case Provider = 'provider';
    case PrivateStorage = 'private_storage';
}
