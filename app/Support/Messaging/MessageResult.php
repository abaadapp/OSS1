<?php

namespace App\Support\Messaging;

/** ما جرى — وصل، أو لم يصل ولماذا */
final class MessageResult
{
    public function __construct(
        public readonly bool $sent,
        public readonly ?string $reason = null,
        public readonly ?string $providerMessageId = null,
    ) {}

    public static function ok(?string $providerMessageId = null): self
    {
        return new self(true, null, $providerMessageId);
    }

    public static function failed(string $reason): self
    {
        return new self(false, $reason);
    }
}
