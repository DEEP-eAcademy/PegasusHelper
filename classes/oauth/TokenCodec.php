<?php

namespace SRAG\PegasusHelper\oauth;

/**
 * Class TokenCodec
 *
 * Encodes and decodes OAuth2 access/refresh tokens in the exact wire format used
 * by the (now removed) ILIAS REST plugin, so tokens minted by that plugin before
 * the migration keep working, and so tokens minted by this plugin would still be
 * accepted by the REST plugin during a transition period.
 *
 * Wire format: urlencode(base64("user_id,ilias_client,api_key,class,scope,misc,ttl,s,h"))
 *  - ttl is the absolute unix expiry time, as a decimal string.
 *  - misc carries the token's issued-at unix time, as a decimal string, so a
 *    revocation cutoff can be checked without adding a field to the wire format
 *    (see {@see RevocationRepository}). Tokens minted by the REST plugin (or by
 *    an older PegasusHelper) carry an empty `misc` and are treated as issued at
 *    time 0 -- i.e. always older than any revocation cutoff.
 *  - s is a random string (25 chars for access tokens, 30 for refresh tokens).
 *  - h = sha256("salt-user_id-ilias_client-api_key-class-scope-misc-ttl-s")
 *
 * @author  Nicolas Schäfli <ns@studer-raimann.ch>
 */
final class TokenCodec
{
    public const CLASS_ACCESS = 'access';
    public const CLASS_REFRESH = 'refresh';

    private const FIELDS = ['user_id', 'ilias_client', 'api_key', 'class', 'scope', 'misc', 'ttl', 's', 'h'];
    private const ENTROPY_ACCESS = 25;
    private const ENTROPY_REFRESH = 30;
    private const ENTROPY_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    /**
     * @var string
     */
    private $salt;

    public function __construct(string $salt)
    {
        $this->salt = $salt;
    }

    /**
     * Generates a new token array for the given class ('access' or 'refresh').
     *
     * @param int    $userId
     * @param string $iliasClient
     * @param string $apiKey
     * @param string $class       one of the CLASS_* constants
     * @param int    $ttlMinutes  lifetime in minutes, added to the current time
     *
     * @return array{user_id:string,ilias_client:string,api_key:string,class:string,scope:string,misc:string,ttl:string,s:string,h:string}
     */
    public function generate(int $userId, string $iliasClient, string $apiKey, string $class, int $ttlMinutes): array
    {
        $entropy = $class === self::CLASS_REFRESH ? self::ENTROPY_REFRESH : self::ENTROPY_ACCESS;

        $token = [
            'user_id' => (string) $userId,
            'ilias_client' => $iliasClient,
            'api_key' => $apiKey,
            'class' => $class,
            'scope' => '',
            'misc' => (string) time(),
            'ttl' => (string) (time() + ($ttlMinutes * 60)),
            's' => $this->randomString($entropy),
        ];
        $token['h'] = $this->hash($token);

        return $token;
    }

    /**
     * Serializes a token array into its wire-format string.
     *
     * @param array $token
     * @return string
     */
    public function serialize(array $token): string
    {
        $parts = [];
        foreach (self::FIELDS as $field) {
            $parts[] = str_replace(',', '', (string) $token[$field]);
        }

        return urlencode(base64_encode(implode(',', $parts)));
    }

    /**
     * Normalises a token string as received from the client. Tokens are urlencoded
     * base64; some HTTP clients (or an intermediate `encodeURI`/decode step) may leave
     * the value partially decoded, so this is tolerant of both forms.
     *
     * Static, and safe to call before ILIAS (and thus the salt) is available:
     * it does no signature verification.
     *
     * @param string $raw
     * @return string
     */
    public static function normalize(string $raw): string
    {
        $raw = trim($raw);
        if (strpos($raw, '%') !== false) {
            $raw = rawurldecode($raw);
        }

        return $raw;
    }

    /**
     * Deserializes a wire-format token string into its array form.
     * Does NOT validate the hash; use {@see isValid()} for that.
     *
     * Static, and safe to call before ILIAS (and thus the salt) is available:
     * it does no signature verification, so the result must not be trusted
     * until {@see isValid()} has checked it.
     *
     * @param string $tokenString
     * @return array|null null if the string is not a well-formed token
     */
    public static function deserialize(string $tokenString): ?array
    {
        $decoded = base64_decode(urldecode($tokenString), true);
        if ($decoded === false) {
            return null;
        }

        $parts = explode(',', $decoded);
        if (count($parts) !== count(self::FIELDS)) {
            return null;
        }

        return array_combine(self::FIELDS, $parts);
    }

    /**
     * Checks a token array's checksum (and that it's structurally valid).
     *
     * @param array $token
     * @return bool
     */
    public function isValid(array $token): bool
    {
        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $token)) {
                return false;
            }
        }

        return hash_equals($this->hash($token), (string) $token['h']);
    }

    /**
     * @param array $token
     * @return bool true if the token's ttl has passed
     */
    public static function isExpired(array $token): bool
    {
        return (int) $token['ttl'] <= time();
    }

    /**
     * @param array $token
     * @return int the token's issued-at unix time, or 0 for a token minted before
     *              this field was introduced (REST plugin tokens, or tokens minted
     *              by PegasusHelper < 7.1.0) -- always older than any revocation cutoff
     */
    public static function issuedAt(array $token): int
    {
        $misc = $token['misc'] ?? '';

        return $misc === '' ? 0 : (int) $misc;
    }

    private function hash(array $token): string
    {
        $hashStr = sprintf(
            '%s-%s-%s-%s-%s-%s-%s-%s-%s',
            $this->salt,
            $token['user_id'],
            $token['ilias_client'],
            $token['api_key'],
            $token['class'],
            $token['scope'],
            $token['misc'],
            $token['ttl'],
            $token['s']
        );

        return hash('sha256', $hashStr);
    }

    private function randomString(int $length): string
    {
        $alphabetLength = strlen(self::ENTROPY_ALPHABET);
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= self::ENTROPY_ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $result;
    }
}
