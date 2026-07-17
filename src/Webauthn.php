<?php
declare(strict_types=1);

namespace Panic;

/**
 * WebAuthn / passkey server-side implementation.
 *
 * Supports ES256 (P-256 ECDSA) and RS256 (RSA PKCS1-SHA256) credentials.
 * No external dependencies — uses PHP's built-in openssl extension.
 *
 * Registration flow:
 *   1. generateChallenge()       → random challenge (base64url)
 *   2. verifyRegistration(...)   → credential data to persist
 *
 * Authentication flow:
 *   1. generateChallenge()       → random challenge (base64url)
 *   2. verifyAssertion(...)      → new sign count (caller must update DB)
 */
final class Webauthn
{
    private string $rpId;
    private string $rpName;
    private string $origin;

    public function __construct()
    {
        $url           = rtrim((string) (getenv('APP_URL') ?: 'http://localhost'), '/');
        $parsed        = parse_url($url);
        $this->rpId    = (string) ($parsed['host'] ?? 'localhost');
        $this->rpName  = (string) (getenv('WEBAUTHN_RP_NAME') ?: 'Mabuhay Backstage');
        // The WebAuthn origin the browser reports in clientDataJSON is scheme +
        // host + optional port — never the path. APP_URL may carry a base path
        // (e.g. https://panicbooking.com/backstage) for routing, so rebuild the
        // origin from its components rather than reusing the full URL.
        $scheme        = (string) ($parsed['scheme'] ?? 'https');
        $port          = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $this->origin  = $scheme . '://' . $this->rpId . $port;
    }

    public function getRpId(): string   { return $this->rpId; }
    public function getRpName(): string { return $this->rpName; }

    /** Generate a cryptographically random challenge (32 bytes, base64url, no padding). */
    public function generateChallenge(): string
    {
        return $this->b64u(random_bytes(32));
    }

    // ─── Registration ─────────────────────────────────────────────────────────

    /**
     * Verify a WebAuthn registration response (navigator.credentials.create result).
     *
     * @param  string $challenge  The challenge sent to the client (base64url, no padding)
     * @param  array  $response   {clientDataJSON, attestationObject, transports?} — base64url strings
     * @return array  {credential_id, public_key_pem, sign_count, transports}
     * @throws \RuntimeException on any verification failure
     */
    public function verifyRegistration(string $challenge, array $response): array
    {
        // Decode and validate clientDataJSON
        $clientDataJson = (string) base64_decode($this->fromB64u($response['clientDataJSON'] ?? ''));
        $clientData     = json_decode($clientDataJson, true);

        if (!is_array($clientData)) {
            throw new \RuntimeException('Invalid clientDataJSON');
        }
        if (($clientData['type'] ?? '') !== 'webauthn.create') {
            throw new \RuntimeException('clientDataJSON.type must be webauthn.create');
        }
        if (!$this->challengeMatches($challenge, (string) ($clientData['challenge'] ?? ''))) {
            throw new \RuntimeException('Challenge mismatch');
        }
        if (($clientData['origin'] ?? '') !== $this->origin) {
            throw new \RuntimeException(
                'Origin mismatch — got "' . ($clientData['origin'] ?? '') . '", expected "' . $this->origin . '"'
            );
        }

        // Decode attestationObject (CBOR-encoded)
        $atObjBytes = (string) base64_decode($this->fromB64u($response['attestationObject'] ?? ''));
        $atObj      = $this->cborDecode($atObjBytes);

        $authDataBytes = $atObj['authData'] ?? '';
        if (!is_string($authDataBytes) || strlen($authDataBytes) < 37) {
            throw new \RuntimeException('authenticatorData is missing or too short');
        }

        // Parse and validate authenticatorData
        $authData = $this->parseAuthData($authDataBytes);

        if (!hash_equals(hash('sha256', $this->rpId, true), $authData['rpIdHash'])) {
            throw new \RuntimeException('RP ID hash mismatch');
        }
        if (!$authData['flags']['UP']) {
            throw new \RuntimeException('User Presence flag not set');
        }
        if (!$authData['flags']['AT']) {
            throw new \RuntimeException('Attested credential data not present');
        }

        return [
            'credential_id'  => $this->b64u($authData['credentialId']),
            'public_key_pem' => $this->coseToPem($authData['coseKey']),
            'sign_count'     => $authData['signCount'],
            'transports'     => is_array($response['transports'] ?? null) ? $response['transports'] : [],
        ];
    }

    // ─── Authentication ───────────────────────────────────────────────────────

    /**
     * Verify a WebAuthn authentication assertion (navigator.credentials.get result).
     *
     * @param  string $challenge   The challenge sent to the client (base64url)
     * @param  array  $response    {clientDataJSON, authenticatorData, signature} — base64url strings
     * @param  array  $credential  Row from passkeys table: {public_key_pem, sign_count}
     * @return int    New sign count — caller MUST persist this to prevent replay attacks
     * @throws \RuntimeException on any verification failure
     */
    public function verifyAssertion(string $challenge, array $response, array $credential): int
    {
        // Decode and validate clientDataJSON
        $clientDataJson = (string) base64_decode($this->fromB64u($response['clientDataJSON'] ?? ''));
        $clientData     = json_decode($clientDataJson, true);

        if (!is_array($clientData)) {
            throw new \RuntimeException('Invalid clientDataJSON');
        }
        if (($clientData['type'] ?? '') !== 'webauthn.get') {
            throw new \RuntimeException('clientDataJSON.type must be webauthn.get');
        }
        if (!$this->challengeMatches($challenge, (string) ($clientData['challenge'] ?? ''))) {
            throw new \RuntimeException('Challenge mismatch');
        }
        if (($clientData['origin'] ?? '') !== $this->origin) {
            throw new \RuntimeException('Origin mismatch');
        }

        // Parse authenticatorData
        $authDataBytes = (string) base64_decode($this->fromB64u($response['authenticatorData'] ?? ''));
        $authData      = $this->parseAuthData($authDataBytes);

        if (!hash_equals(hash('sha256', $this->rpId, true), $authData['rpIdHash'])) {
            throw new \RuntimeException('RP ID hash mismatch');
        }
        if (!$authData['flags']['UP']) {
            throw new \RuntimeException('User Presence flag not set');
        }

        // Check sign count (both non-zero means we can detect cloned authenticators)
        $newCount    = $authData['signCount'];
        $storedCount = (int) $credential['sign_count'];
        if ($newCount !== 0 && $storedCount !== 0 && $newCount <= $storedCount) {
            throw new \RuntimeException('Sign count regression — possible cloned authenticator');
        }

        // Verify signature over authData || hash(clientDataJSON)
        $clientDataHash = hash('sha256', $clientDataJson, true);
        $signedData     = $authDataBytes . $clientDataHash;
        $signature      = (string) base64_decode($this->fromB64u($response['signature'] ?? ''));

        if (!$this->verifySignature($signedData, $signature, (string) $credential['public_key_pem'])) {
            throw new \RuntimeException('Signature verification failed');
        }

        return $newCount;
    }

    // ─── authenticatorData parser ─────────────────────────────────────────────

    /** @return array{rpIdHash:string, flags:array, signCount:int, credentialId:string, coseKey:array} */
    private function parseAuthData(string $bytes): array
    {
        $pos = 0;

        $rpIdHash  = substr($bytes, $pos, 32);
        $pos      += 32;

        $flagsByte = ord($bytes[$pos]);
        $pos      += 1;
        $flags     = [
            'UP' => (bool) ($flagsByte & 0x01),  // User Present
            'UV' => (bool) ($flagsByte & 0x04),  // User Verified
            'AT' => (bool) ($flagsByte & 0x40),  // Attested credential data included
            'ED' => (bool) ($flagsByte & 0x80),  // Extension data included
        ];

        $signCount = (int) (unpack('N', substr($bytes, $pos, 4))[1]);
        $pos      += 4;

        $credentialId = '';
        $coseKey      = [];

        if ($flags['AT'] && strlen($bytes) > $pos) {
            $pos += 16;  // skip AAGUID (16 bytes)

            $credIdLen = (int) (unpack('n', substr($bytes, $pos, 2))[1]);
            $pos      += 2;

            $credentialId = substr($bytes, $pos, $credIdLen);
            $pos         += $credIdLen;

            // The rest is the CBOR-encoded COSE public key
            $coseKey = $this->cborDecode(substr($bytes, $pos));
        }

        return compact('rpIdHash', 'flags', 'signCount', 'credentialId', 'coseKey');
    }

    // ─── COSE key → PEM ──────────────────────────────────────────────────────

    /** Convert a COSE key (decoded from CBOR) to PEM-encoded SubjectPublicKeyInfo. */
    private function coseToPem(array $key): string
    {
        $kty = $key[1] ?? null;  // COSE key type: 2=EC2, 3=RSA

        if ($kty === 2) {
            // EC2 key — P-256 (crv = 1)
            $x = $key[-2] ?? null;
            $y = $key[-3] ?? null;
            if (!is_string($x) || !is_string($y)) {
                throw new \RuntimeException('EC public key is missing x or y coordinate');
            }
            $der = $this->ecP256SpkiDer("\x04" . $x . $y);
        } elseif ($kty === 3) {
            // RSA key
            $n = $key[-1] ?? null;
            $e = $key[-2] ?? null;
            if (!is_string($n) || !is_string($e)) {
                throw new \RuntimeException('RSA public key is missing modulus or exponent');
            }
            $der = $this->rsaSpkiDer($n, $e);
        } else {
            throw new \RuntimeException("Unsupported COSE key type: $kty");
        }

        return "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END PUBLIC KEY-----\n";
    }

    /** DER SubjectPublicKeyInfo for EC P-256. */
    private function ecP256SpkiDer(string $point): string
    {
        $algId = $this->derSeq(
            $this->derOid("\x2a\x86\x48\xce\x3d\x02\x01")      // id-ecPublicKey
          . $this->derOid("\x2a\x86\x48\xce\x3d\x03\x01\x07")  // prime256v1
        );
        return $this->derSeq($algId . $this->derBit($point));
    }

    /** DER SubjectPublicKeyInfo for RSA. */
    private function rsaSpkiDer(string $n, string $e): string
    {
        $algId  = $this->derSeq($this->derOid("\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00"); // rsaEncryption + NULL
        $rsaKey = $this->derSeq($this->derInt($n) . $this->derInt($e));
        return $this->derSeq($algId . $this->derBit($rsaKey));
    }

    // DER encoding primitives
    private function derLen(int $len): string
    {
        if ($len < 128) return chr($len);
        $enc = '';
        while ($len > 0) { $enc = chr($len & 0xff) . $enc; $len >>= 8; }
        return chr(0x80 | strlen($enc)) . $enc;
    }
    private function derSeq(string $c): string { return "\x30" . $this->derLen(strlen($c)) . $c; }
    private function derOid(string $c): string { return "\x06" . $this->derLen(strlen($c)) . $c; }
    private function derBit(string $c): string { return "\x03" . $this->derLen(1 + strlen($c)) . "\x00" . $c; }
    private function derInt(string $c): string
    {
        $c = ltrim($c, "\x00") ?: "\x00";
        if (ord($c[0]) & 0x80) $c = "\x00" . $c;  // ensure positive
        return "\x02" . $this->derLen(strlen($c)) . $c;
    }

    // ─── Signature verification ───────────────────────────────────────────────

    private function verifySignature(string $data, string $sig, string $pem): bool
    {
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new \RuntimeException('Could not load public key: ' . openssl_error_string());
        }
        $result = openssl_verify($data, $sig, $key, OPENSSL_ALGO_SHA256);
        if ($result === -1) {
            throw new \RuntimeException('OpenSSL error during verification: ' . openssl_error_string());
        }
        return $result === 1;
    }

    // ─── Minimal CBOR decoder ─────────────────────────────────────────────────
    // Handles the subset used by WebAuthn: unsigned/negative ints, byte strings,
    // text strings, arrays, maps, and tagged values.

    private function cborDecode(string $bytes): mixed
    {
        $pos = 0;
        return $this->cborItem($bytes, $pos);
    }

    private function cborItem(string $b, int &$i): mixed
    {
        if ($i >= strlen($b)) {
            throw new \RuntimeException('Unexpected end of CBOR data');
        }
        $init = ord($b[$i++]);
        $mt   = $init >> 5;         // major type
        $ai   = $init & 0x1f;       // additional info
        $len  = $this->cborArgLen($ai, $b, $i);

        return match ($mt) {
            0 => $len,                              // unsigned integer
            1 => -1 - $len,                        // negative integer
            2 => $this->cborSlice($len, $b, $i),   // byte string
            3 => $this->cborSlice($len, $b, $i),   // text string (UTF-8)
            4 => $this->cborArr($len, $b, $i),     // array
            5 => $this->cborMap($len, $b, $i),     // map
            6 => $this->cborItem($b, $i),           // tagged value (skip tag, decode item)
            default => throw new \RuntimeException("Unsupported CBOR major type: $mt"),
        };
    }

    /** Decode the argument length/value from additional info bytes. */
    private function cborArgLen(int $ai, string $b, int &$i): int
    {
        if ($ai <= 23) return $ai;
        return match ($ai) {
            24 => ord($b[$i++]),
            25 => (int) (unpack('n', substr($b, ($i += 2) - 2, 2))[1]),
            26 => (int) (unpack('N', substr($b, ($i += 4) - 4, 4))[1]),
            27 => (int) (unpack('J', substr($b, ($i += 8) - 8, 8))[1]),
            31 => -1,  // indefinite length
            default => throw new \RuntimeException("Unsupported CBOR additional info: $ai"),
        };
    }

    /** Read $len bytes (or indefinite-length chunks). */
    private function cborSlice(int $len, string $b, int &$i): string
    {
        if ($len === -1) {
            $r = '';
            while ($i < strlen($b) && ord($b[$i]) !== 0xff) {
                $r .= $this->cborItem($b, $i);
            }
            $i++;  // consume break byte
            return $r;
        }
        $r = substr($b, $i, $len);
        $i += $len;
        return $r;
    }

    /** Decode a CBOR array of $len items. */
    private function cborArr(int $len, string $b, int &$i): array
    {
        $r = [];
        if ($len === -1) {
            while ($i < strlen($b) && ord($b[$i]) !== 0xff) {
                $r[] = $this->cborItem($b, $i);
            }
            $i++;
        } else {
            for ($k = 0; $k < $len; $k++) $r[] = $this->cborItem($b, $i);
        }
        return $r;
    }

    /** Decode a CBOR map of $len key-value pairs. */
    private function cborMap(int $len, string $b, int &$i): array
    {
        $r = [];
        if ($len === -1) {
            while ($i < strlen($b) && ord($b[$i]) !== 0xff) {
                $key = $this->cborItem($b, $i);
                $r[$key] = $this->cborItem($b, $i);
            }
            $i++;
        } else {
            for ($k = 0; $k < $len; $k++) {
                $key = $this->cborItem($b, $i);
                $r[$key] = $this->cborItem($b, $i);
            }
        }
        return $r;
    }

    // ─── Base64url helpers ────────────────────────────────────────────────────

    /** Encode binary data as base64url (no padding). */
    public function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Convert a base64url string (with or without padding) to a standard base64 string for decoding. */
    private function fromB64u(string $b64u): string
    {
        $pad = strlen($b64u) % 4;
        if ($pad) $b64u .= str_repeat('=', 4 - $pad);
        return strtr($b64u, '-_', '+/');
    }

    /** Compare two challenges, ignoring base64url/base64 padding and +/ vs -_ differences. */
    private function challengeMatches(string $a, string $b): bool
    {
        $norm = static fn (string $s) => rtrim(strtr($s, '+/', '-_'), '=');
        return hash_equals($norm($a), $norm($b));
    }
}
