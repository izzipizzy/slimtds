<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use App\Admin\Repository\Admin;
use App\Admin\Repository\WebAuthnCredentialRepository;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Hardware-key (YubiKey / FIDO2) registration and sign-in.
 *
 * The one-time challenge lives in the PHP session between the "options" call
 * and the "finish" call, bound to its purpose and admin, and expires after
 * TTL seconds. It is removed on first use whatever the outcome, so a captured
 * response can never be replayed.
 *
 * Attestation is "none": we trust the key the operator plugs in while already
 * signed in, not a vendor certificate chain.
 */
final class WebAuthnService
{
    private const SESSION_KEY = 'webauthn_challenge';
    private const TTL = 120;
    private const TIMEOUT = 60;

    public function __construct(private readonly WebAuthnCredentialRepository $credentials) {}

    /** @return array<string,mixed> PublicKeyCredentialCreationOptions (binary fields base64url) */
    public function registrationOptions(Admin $admin, ServerRequestInterface $request): array
    {
        $wa = $this->lib($request);
        $exclude = array_map(
            static fn (string $id): ByteBuffer => ByteBuffer::fromBase64Url($id),
            $this->credentials->credentialIdsForAdmin($admin->id),
        );
        $args = $wa->getCreateArgs(
            pack('J', $admin->id),
            $admin->login,
            $admin->login,
            self::TIMEOUT,
            'discouraged',   // server-side credential: works on keys without a FIDO2 PIN
            'preferred',     // the key asks for its PIN if one is set
            null,
            $exclude,
        );
        $this->park('register', $admin->id, $wa->getChallenge());
        return $this->toArray($args);
    }

    /**
     * @param array<string,mixed> $body  {name, clientDataJSON, attestationObject} (base64url)
     * @throws WebAuthnException|\InvalidArgumentException
     */
    public function finishRegistration(Admin $admin, array $body, ServerRequestInterface $request): string
    {
        $challenge = $this->take('register', $admin->id);
        $name = trim(is_string($body['name'] ?? null) ? $body['name'] : '');
        $name = $name === '' ? 'YubiKey' : mb_substr($name, 0, 64);

        $data = $this->lib($request)->processCreate(
            $this->b64($body, 'clientDataJSON'),
            $this->b64($body, 'attestationObject'),
            $challenge,
            false,
            true,
            false,
        );

        $credentialId = self::toB64Url((string)$data->credentialId);
        $aaguid = is_string($data->AAGUID ?? null) ? bin2hex($data->AAGUID) : null;
        $this->credentials->add(
            $admin->id,
            $credentialId,
            (string)$data->credentialPublicKey,
            (int)($data->signatureCounter ?? 0),
            $name,
            $aaguid,
        );
        return $name;
    }

    /** @return array<string,mixed>|null null when this admin has no keys */
    public function loginOptions(Admin $admin, ServerRequestInterface $request): ?array
    {
        $ids = $this->credentials->credentialIdsForAdmin($admin->id);
        if ($ids === []) {
            return null;
        }
        $wa = $this->lib($request);
        $args = $wa->getGetArgs(
            array_map(static fn (string $id): ByteBuffer => ByteBuffer::fromBase64Url($id), $ids),
            self::TIMEOUT,
            true, true, true, true, true,
            'preferred',
        );
        $this->park('login', $admin->id, $wa->getChallenge());
        return $this->toArray($args);
    }

    /**
     * Verifies the assertion and returns the admin id it proves.
     *
     * @param array<string,mixed> $body  {id, clientDataJSON, authenticatorData, signature} (base64url)
     * @throws WebAuthnException|\InvalidArgumentException
     */
    public function finishLogin(array $body, ServerRequestInterface $request): int
    {
        $adminId = $this->pendingAdminId('login');
        $challenge = $this->take('login', $adminId);

        $credentialId = is_string($body['id'] ?? null) ? $body['id'] : '';
        $cred = $credentialId !== '' ? $this->credentials->findByCredentialId($credentialId) : null;
        if ($cred === null || $cred['admin_id'] !== $adminId) {
            throw new \InvalidArgumentException('unknown key');
        }

        $wa = $this->lib($request);
        $wa->processGet(
            $this->b64($body, 'clientDataJSON'),
            $this->b64($body, 'authenticatorData'),
            $this->b64($body, 'signature'),
            $cred['public_key'],
            $challenge,
            $cred['sign_count'],
            false,
            true,
        );

        $counter = $wa->getSignatureCounter();
        $this->credentials->touch($cred['id'], is_int($counter) ? $counter : $cred['sign_count']);
        return $adminId;
    }

    /** Admin id the current login challenge was issued for (for audit on failure). */
    public function pendingAdminId(string $purpose): int
    {
        $p = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($p) || ($p['purpose'] ?? null) !== $purpose || !is_int($p['admin_id'] ?? null)) {
            throw new \InvalidArgumentException('no pending challenge');
        }
        return $p['admin_id'];
    }

    private function lib(ServerRequestInterface $request): WebAuthn
    {
        return new WebAuthn('slim/TDS', self::rpId($request), ['none'], true);
    }

    /** RP ID = host of APP_URL (authoritative), falling back to the request host. */
    public static function rpId(ServerRequestInterface $request): string
    {
        $host = parse_url((string)($_ENV['APP_URL'] ?? ''), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return strtolower($host);
        }
        return strtolower($request->getUri()->getHost());
    }

    private function park(string $purpose, int $adminId, ByteBuffer $challenge): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'purpose'   => $purpose,
            'admin_id'  => $adminId,
            'challenge' => $challenge->getHex(),
            'exp'       => time() + self::TTL,
        ];
    }

    private function take(string $purpose, int $adminId): ByteBuffer
    {
        $p = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        if (
            !is_array($p)
            || ($p['purpose'] ?? null) !== $purpose
            || ($p['admin_id'] ?? null) !== $adminId
            || !is_string($p['challenge'] ?? null)
            || !is_int($p['exp'] ?? null)
            || $p['exp'] < time()
        ) {
            throw new \InvalidArgumentException('challenge missing or expired');
        }
        return ByteBuffer::fromHex($p['challenge']);
    }

    /** @param array<string,mixed> $body */
    private function b64(array $body, string $field): string
    {
        $v = $body[$field] ?? null;
        if (!is_string($v) || $v === '' || !preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $v)) {
            throw new \InvalidArgumentException("missing {$field}");
        }
        $bin = base64_decode(strtr($v, '-_', '+/'), true);
        if ($bin === false) {
            throw new \InvalidArgumentException("bad {$field}");
        }
        return $bin;
    }

    private static function toB64Url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** @return array<string,mixed> */
    private function toArray(object $args): array
    {
        ByteBuffer::$useBase64UrlEncoding = true;
        /** @var array<string,mixed> */
        return json_decode(json_encode($args, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
