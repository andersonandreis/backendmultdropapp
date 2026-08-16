<?php
// MUL-222 item 3: TOTP RFC 6238 SHA1 6-digits window 30s (compat Google Authenticator)
namespace App\Services;

class TotpService
{
    public static function generateSecret(int $length = 20): string
    {
        // Base32 alphabet
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }
        return $secret;
    }

    public static function otpauthUri(string $issuer, string $account, string $secret): string
    {
        return sprintf('otpauth://totp/%s:%s?secret=%s&issuer=%s',
            rawurlencode($issuer), rawurlencode($account), $secret, rawurlencode($issuer));
    }

    public static function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        $secret = strtoupper(str_replace(' ', '', $secret));
        $code = str_pad((string) $code, 6, '0', STR_PAD_LEFT);
        $time = floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (self::generateCode($secret, $time + $i) === $code) return true;
        }
        return false;
    }

    private static function generateCode(string $secret, int $counter): string
    {
        $binSecret = self::base32Decode($secret);
        $binCounter = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $binCounter, $binSecret, true);
        $offset = ord($hash[19]) & 0x0F;
        $otp = ((ord($hash[$offset]) & 0x7F) << 24)
             | ((ord($hash[$offset+1]) & 0xFF) << 16)
             | ((ord($hash[$offset+2]) & 0xFF) << 8)
             | (ord($hash[$offset+3]) & 0xFF);
        return str_pad((string) ($otp % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';
        $buffer = 0;
        $bits = 0;
        foreach (str_split($b32) as $ch) {
            $val = strpos($alphabet, $ch);
            if ($val === false) continue;
            $buffer = ($buffer << 5) | $val;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 0xFF);
            }
        }
        return $out;
    }

    public static function generateBackupCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars
        }
        return $codes;
    }
}
