<?php

namespace Reach\StatamicTolk\Runs;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Reach\StatamicTolk\State\Concerns\ReadsStoredValues;

/**
 * A worker's hold on a run item (§A12). The holder extends it by its `ttl` before every provider call.
 */
final readonly class Claim
{
    use ReadsStoredValues;

    public function __construct(
        public string $by,
        public string $token,
        public CarbonImmutable $expiresAt,
        public int $ttl,
    ) {}

    public static function for(string $workerId, int $ttl): self
    {
        $ttl = max(1, $ttl);

        return new self($workerId, Str::random(32), self::now()->addSeconds($ttl), $ttl);
    }

    public function extended(): self
    {
        return new self($this->by, $this->token, self::now()->addSeconds($this->ttl), $this->ttl);
    }

    public function isExpired(): bool
    {
        return $this->expiresAt->lessThanOrEqualTo(CarbonImmutable::now());
    }

    public function toArray(): array
    {
        return [
            'by' => $this->by,
            'token' => $this->token,
            'expires_at' => self::timeToString($this->expiresAt),
            'ttl' => $this->ttl,
        ];
    }

    /**
     * Returns null for a claim that lacks its holder, token or expiry.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data)) {
            return null;
        }

        $by = self::storedString($data, 'by');
        $token = self::storedString($data, 'token');
        $expiresAt = self::storedTime($data, 'expires_at');

        if ($by === null || $token === null || $expiresAt === null) {
            return null;
        }

        return new self($by, $token, $expiresAt, max(1, (int) ($data['ttl'] ?? 1)));
    }

    // Stored times have whole seconds, so a claim reads back as it was handed out.
    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::now()->utc()->startOfSecond();
    }
}
