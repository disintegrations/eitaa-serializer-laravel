<?php

namespace Disintegrations\EitaaSerializer\Responses;

use DateTimeImmutable;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

/** Normalized outcome of the Eitaa TL token refresh response constructors. */
readonly class TokenRefreshResult implements JsonSerializable
{
    public function __construct(
        public string $status,
        #[SensitiveParameter]
        public ?string $token = null,
        public ?DateTimeImmutable $expiresAt = null,
        public ?int $providerExpire = null,
        public ?int $providerDate = null,
    ) {
    }

    /** @return array{status: string, token: ?string, expires_at: ?string, provider_expire: ?int, provider_date: ?int} */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status,
            'token' => $this->token === null ? null : '[REDACTED]',
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'provider_expire' => $this->providerExpire,
            'provider_date' => $this->providerDate,
        ];
    }

    /** @return array{status: string, token: ?string, expires_at: ?string, provider_expire: ?int, provider_date: ?int} */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Eitaa refresh token results must not be serialized.');
    }
}
