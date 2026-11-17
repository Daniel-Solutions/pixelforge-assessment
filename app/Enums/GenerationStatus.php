<?php

declare(strict_types=1);

namespace App\Enums;

enum GenerationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Position in the lifecycle: queued < processing < succeeded | failed.
     * A generation only moves to a strictly higher rank, so it never goes backwards.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Processing => 1,
            self::Succeeded, self::Failed => 2,
        };
    }

    /**
     * @return list<self>
     */
    public static function pending(): array
    {
        return [self::Queued, self::Processing];
    }
}
