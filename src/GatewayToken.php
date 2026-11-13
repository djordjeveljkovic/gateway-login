<?php

namespace NewLogin\Laravel;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Verifies the one-time token the gateway hands over after login: a compact JWS
 * signed with the gateway's Ed25519 identity key.
 */
class GatewayToken
{
    private const HEADER = '{"alg":"EdDSA","typ":"JWT"}';

    /** Clock difference tolerated between the gateway and this server. */
    private const LEEWAY = 30;

    /**
     * @return array{sub: string, name: string, org: string, access_until: int, jti: string}
     *
     * @throws RuntimeException when the token is not valid for this app
     */
    public static function verify(string $token, ?int $now = null): array
    {
        $now ??= now()->getTimestamp();
        $publicKey = base64_decode((string) config('gateway.public_key'), true);
        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('GATEWAY_PUBLIC_KEY is missing or invalid');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('malformed token');
        }
        [$h, $p, $s] = $parts;
        if (self::b64url($h) !== self::HEADER) {
            throw new RuntimeException('unexpected token header');
        }
        $signature = self::b64url($s);
        if ($signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signature, "$h.$p", $publicKey)) {
            throw new RuntimeException('signature mismatch');
        }

        $claims = json_decode((string) self::b64url($p), true);
        if (! is_array($claims)) {
            throw new RuntimeException('bad claims');
        }
        foreach (['aud' => 'string', 'sub' => 'string', 'jti' => 'string', 'iat' => 'integer', 'exp' => 'integer', 'access_until' => 'integer'] as $field => $type) {
            if (gettype($claims[$field] ?? null) !== $type) {
                throw new RuntimeException("missing claim $field");
            }
        }
        if (! hash_equals((string) config('gateway.app_id'), $claims['aud'])) {
            throw new RuntimeException('token is for another app');
        }
        $issuer = config('gateway.issuer');
        if ($issuer !== null && $issuer !== '' && ($claims['iss'] ?? null) !== $issuer) {
            throw new RuntimeException('token from another gateway');
        }
        if ($claims['exp'] < $now - self::LEEWAY || $claims['iat'] > $now + self::LEEWAY) {
            throw new RuntimeException('token expired');
        }
        if ($claims['access_until'] <= $now || strlen($claims['jti']) < 16) {
            throw new RuntimeException('token not usable');
        }
        // Each token works once: a copied link (history, logs) is useless.
        if (! Cache::add('gateway:jti:'.hash('sha256', $claims['jti']), true, 600)) {
            throw new RuntimeException('token already used');
        }

        return [
            'sub' => $claims['sub'],
            'name' => (string) ($claims['name'] ?? ''),
            'org' => (string) ($claims['org'] ?? ''),
            'access_until' => $claims['access_until'],
            'jti' => $claims['jti'],
        ];
    }

    private static function b64url(string $data): ?string
    {
        if ($data === '' || preg_match('/[^A-Za-z0-9_-]/', $data)) {
            return null;
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
