<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * Google Authenticator (TOTP, RFC 6238) enrolment and verification.
 */
class TwoFactorService
{
    public function __construct(private Google2FA $google2fa)
    {
        $this->google2fa->setWindow(1); // tolerate one 30s step of drift
    }

    /**
     * Generate a brand new base32 shared secret.
     */
    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /**
     * otpauth:// URI to feed into Google Authenticator.
     */
    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(
            config('app.name', 'AninfPush'),
            $user->email,
            $secret,
        );
    }

    /**
     * Inline SVG of the otpauth URI, ready to drop into an <img src="data:...">.
     */
    public function qrCodeSvg(string $otpauthUrl, int $size = 220): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 0), new SvgImageBackEnd()));

        return $writer->writeString($otpauthUrl);
    }

    public function qrCodeDataUri(string $otpauthUrl, int $size = 220): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($this->qrCodeSvg($otpauthUrl, $size));
    }

    /**
     * Check a 6 digit code against the secret.
     */
    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        try {
            return (bool) $this->google2fa->verifyKey($secret, $code);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Consume one of the user's single use recovery codes.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $code = strtoupper(trim($code));

        $index = array_search($code, array_map('strtoupper', $codes), true);

        if ($index === false) {
            return false;
        }

        unset($codes[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

        return true;
    }

    /**
     * Full enrolment payload handed to the frontend setup wizard.
     *
     * @return array{secret: string, otpauth_url: string, qr_code: string, manual_entry_key: string}
     */
    public function enrolmentPayload(User $user, string $secret): array
    {
        $url = $this->otpauthUrl($user, $secret);

        return [
            'secret' => $secret,
            'otpauth_url' => $url,
            'qr_code' => $this->qrCodeDataUri($url),
            // Google Authenticator shows the key in groups of 4 when typed manually.
            'manual_entry_key' => trim(chunk_split($secret, 4, ' ')),
        ];
    }
}
