<?php

declare(strict_types=1);

use App\Admin\Controller\LoginController;
use App\Admin\Repository\AdminRepository;
use App\Admin\Repository\WebAuthnCredentialRepository;
use App\Shared\Asset\Manifest;
use App\Shared\Auth\AuthEventLogger;
use App\Shared\Auth\PasswordHasher;
use App\Shared\Auth\WebAuthnService;
use App\Shared\Db\Connection;
use App\Shared\I18n\I18n;
use App\Shared\I18n\TranslatorFactory;
use App\Shared\Version\BuildInfo;
use App\Shared\Version\UpdateState;
use App\Shared\Version\UpdateStateReader;
use App\Shared\Version\UpdateStatus;
use App\Shared\View\View;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/*
 * Hardware-key sign-in, end to end against a software authenticator:
 * an ES256 key pair with "none" attestation, encoded the way a YubiKey
 * answers navigator.credentials.create() / .get().
 */

const WA_ORIGIN = 'https://slimtds.test';
const WA_RP_ID  = 'slimtds.test';

function wa_cbor(mixed $v): string
{
    $head = static function (int $major, int $n): string {
        if ($n < 24) return chr(($major << 5) | $n);
        if ($n < 256) return chr(($major << 5) | 24) . chr($n);
        return chr(($major << 5) | 25) . pack('n', $n);
    };
    if (is_int($v)) return $v >= 0 ? $head(0, $v) : $head(1, -1 - $v);
    if (is_array($v) && array_key_exists('__bytes', $v)) return $head(2, strlen($v['__bytes'])) . $v['__bytes'];
    if (is_string($v)) return $head(3, strlen($v)) . $v;
    if (is_array($v)) {
        $o = $head(5, count($v));
        foreach ($v as $k => $x) {
            $o .= wa_cbor($k) . wa_cbor($x);
        }
        return $o;
    }
    throw new LogicException('unsupported CBOR value');
}

function wa_b64(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** Software authenticator: one ES256 credential. */
final class SoftKey
{
    public \OpenSSLAsymmetricKey $key;
    public string $credId;

    public function __construct()
    {
        $this->key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->credId = random_bytes(32);
    }

    /** @return array<string,string> body for finishRegistration() */
    public function attest(string $challenge, string $origin = WA_ORIGIN): array
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];
        // OpenSSL drops leading zero bytes; COSE wants fixed 32-byte coordinates.
        $x = str_pad($ec['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
        $cose = wa_cbor([1 => 2, 3 => -7, -1 => 1, -2 => ['__bytes' => $x], -3 => ['__bytes' => $y]]);
        $authData = hash('sha256', WA_RP_ID, true) . chr(0x41) . pack('N', 0)
            . str_repeat("\0", 16) . pack('n', strlen($this->credId)) . $this->credId . $cose;
        $cd = json_encode(['type' => 'webauthn.create', 'challenge' => $challenge, 'origin' => $origin]);
        return [
            'clientDataJSON'    => wa_b64($cd),
            'attestationObject' => wa_b64(wa_cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => ['__bytes' => $authData]])),
        ];
    }

    /** @return array<string,string> body for finishLogin() */
    public function assert(string $challenge, int $counter, string $origin = WA_ORIGIN): array
    {
        $cd = json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => $origin]);
        $ad = hash('sha256', WA_RP_ID, true) . chr(0x01) . pack('N', $counter);
        openssl_sign($ad . hash('sha256', $cd, true), $sig, $this->key, OPENSSL_ALGO_SHA256);
        return [
            'id'                => wa_b64($this->credId),
            'clientDataJSON'    => wa_b64($cd),
            'authenticatorData' => wa_b64($ad),
            'signature'         => wa_b64($sig),
        ];
    }
}

beforeEach(function (): void {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_start(['use_cookies' => false, 'cache_limiter' => '']);
    $_SESSION = [];
    $this->prevAppUrl = $_ENV['APP_URL'] ?? null;
    $_ENV['APP_URL'] = WA_ORIGIN;

    $pdo = new PDO(
        $_ENV['DB_DSN'] ?? 'pgsql:host=db;port=5432;dbname=slimtds',
        $_ENV['DB_USER'] ?? 'slimtds',
        $_ENV['DB_PASSWORD'] ?? 'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec('DELETE FROM core.admins');
    $pdo->exec('DELETE FROM core.auth_events');
    $this->db = new Connection($pdo);

    $hasher = new PasswordHasher();
    $this->db->execute(
        'INSERT INTO core.admins (login, password_hash) VALUES (:l, :h)',
        ['l' => 'alice', 'h' => $hasher->hash('correct-horse')],
    );
    $this->admins = new AdminRepository($this->db);
    $this->admin  = $this->admins->findByLogin('alice');
    $this->keys   = new WebAuthnCredentialRepository($this->db);
    $this->svc    = new WebAuthnService($this->keys);
    $this->req    = (new ServerRequestFactory())->createServerRequest('POST', WA_ORIGIN . '/admin/x');
    $this->soft   = new SoftKey();

    $reader = new class implements UpdateStateReader {
        public function read(): ?UpdateState { return null; }
    };
    $this->controller = new LoginController(
        $this->admins,
        $hasher,
        new AuthEventLogger($this->db),
        new UpdateStatus(new BuildInfo(''), $reader, true, 'izzipizzy/slimtds'),
        $this->svc,
        $this->keys,
    );
    $root = dirname(__DIR__, 3);
    $this->view = new View(
        $root . '/resources/views',
        new Manifest($root . '/public/assets/manifest.json'),
        new I18n((new TranslatorFactory($root . '/resources/translations'))->create()),
    );

    $this->enrol = function (): void {
        $opt = $this->svc->registrationOptions($this->admin, $this->req)['publicKey'];
        $this->svc->finishRegistration($this->admin, ['name' => 'Key A'] + $this->soft->attest($opt['challenge']), $this->req);
    };
    $this->loginChallenge = fn (): string => $this->svc->loginOptions($this->admin, $this->req)['publicKey']['challenge'];
    $this->passwordLogin = fn (string $pass) => $this->controller->postLogin(
        (new ServerRequestFactory())->createServerRequest('POST', '/admin/login')
            ->withParsedBody(['login' => 'alice', 'password' => $pass]),
        new Response(),
        $this->view,
    );
});

afterEach(function (): void {
    if ($this->prevAppUrl === null) unset($_ENV['APP_URL']); else $_ENV['APP_URL'] = $this->prevAppUrl;
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
});

test('registration options target the APP_URL host with no attestation', function (): void {
    $opt = $this->svc->registrationOptions($this->admin, $this->req)['publicKey'];
    expect($opt['rp']['id'])->toBe(WA_RP_ID)
        ->and($opt['attestation'])->toBe('none')
        ->and($opt['challenge'])->toMatch('/^[A-Za-z0-9_-]{20,}$/');
});

test('a registered key signs in', function (): void {
    ($this->enrol)();
    expect($this->keys->countForAdmin($this->admin->id))->toBe(1);

    $id = $this->svc->finishLogin($this->soft->assert(($this->loginChallenge)(), 1), $this->req);
    expect($id)->toBe($this->admin->id);
});

test('a registration response cannot be replayed', function (): void {
    $opt = $this->svc->registrationOptions($this->admin, $this->req)['publicKey'];
    $body = $this->soft->attest($opt['challenge']);
    $this->svc->finishRegistration($this->admin, $body, $this->req);
    $this->svc->finishRegistration($this->admin, $body, $this->req);
})->throws(InvalidArgumentException::class);

test('a login assertion cannot be replayed', function (): void {
    ($this->enrol)();
    $body = $this->soft->assert(($this->loginChallenge)(), 1);
    $this->svc->finishLogin($body, $this->req);
    $this->svc->finishLogin($body, $this->req);
})->throws(InvalidArgumentException::class);

test('a forged signature is rejected', function (): void {
    ($this->enrol)();
    $body = $this->soft->assert(($this->loginChallenge)(), 1);
    $body['signature'] = wa_b64(random_bytes(70));
    $this->svc->finishLogin($body, $this->req);
})->throws(lbuchs\WebAuthn\WebAuthnException::class);

test('an assertion made for another origin is rejected', function (): void {
    ($this->enrol)();
    $this->svc->finishLogin($this->soft->assert(($this->loginChallenge)(), 1, 'https://evil.example'), $this->req);
})->throws(lbuchs\WebAuthn\WebAuthnException::class);

test('a signature counter going backwards (cloned key) is rejected', function (): void {
    ($this->enrol)();
    $this->svc->finishLogin($this->soft->assert(($this->loginChallenge)(), 5), $this->req);
    $this->svc->finishLogin($this->soft->assert(($this->loginChallenge)(), 3), $this->req);
})->throws(lbuchs\WebAuthn\WebAuthnException::class);

test('a key of another admin is rejected', function (): void {
    ($this->enrol)();
    $this->db->execute("INSERT INTO core.admins (login, password_hash) VALUES ('bob', 'x')");
    $bob = $this->admins->findByLogin('bob');
    $other = new SoftKey();
    $opt = $this->svc->registrationOptions($bob, $this->req)['publicKey'];
    $this->svc->finishRegistration($bob, $other->attest($opt['challenge']), $this->req);

    // challenge issued for alice, answered with bob's key
    $this->svc->finishLogin($other->assert(($this->loginChallenge)(), 1), $this->req);
})->throws(InvalidArgumentException::class, 'unknown key');

test('login options are refused for an admin without keys', function (): void {
    expect($this->svc->loginOptions($this->admin, $this->req))->toBeNull();
});

test('the correct password is refused while a key is enrolled', function (): void {
    ($this->enrol)();
    $resp = ($this->passwordLogin)('correct-horse');

    expect($resp->getHeaderLine('Location'))->toBe('/admin/login')
        ->and($_SESSION['admin_id'] ?? null)->toBeNull();
    $reason = $this->db->fetchScalar("SELECT details->>'reason' FROM core.auth_events WHERE event_type = 'login_fail'");
    expect($reason)->toBe('auth.webauthn_required');
});

test('removing the last key re-enables the password', function (): void {
    ($this->enrol)();
    $this->keys->deleteAllForAdmin($this->admin->id);
    $resp = ($this->passwordLogin)('correct-horse');

    expect($resp->getHeaderLine('Location'))->toBe('/admin')
        ->and($_SESSION['admin_id'] ?? null)->toBe($this->admin->id);
});

test('webauthn login endpoint starts a session and audits the method', function (): void {
    ($this->enrol)();
    $opt = $this->controller->webauthnOptions(
        (new ServerRequestFactory())->createServerRequest('POST', WA_ORIGIN . '/admin/login/webauthn/options')->withParsedBody(['login' => 'alice']),
        new Response(),
        $this->view,
    );
    $challenge = json_decode((string)$opt->getBody(), true)['publicKey']['challenge'];

    $resp = $this->controller->webauthnLogin(
        (new ServerRequestFactory())->createServerRequest('POST', WA_ORIGIN . '/admin/login/webauthn')
            ->withParsedBody($this->soft->assert($challenge, 1)),
        new Response(),
        $this->view,
    );

    expect($resp->getStatusCode())->toBe(200)
        ->and(json_decode((string)$resp->getBody(), true)['redirect'])->toBe('/admin')
        ->and($_SESSION['admin_id'] ?? null)->toBe($this->admin->id);
    $method = $this->db->fetchScalar("SELECT details->>'method' FROM core.auth_events WHERE event_type = 'login_success'");
    expect($method)->toBe('webauthn');
});
