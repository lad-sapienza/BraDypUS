<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace Mail;

/**
 * Thin wrapper around the Resend transactional email API (https://resend.com).
 *
 * BraDypUS is a multi-tenant install: one Resend account/API key covers every
 * app hosted on the instance (set once via env vars, not per-app config) — the
 * same reasoning as one PHP/DB backend serving many apps. Deployers who don't
 * want to run or configure a mail server just don't set the key; email-backed
 * features (password reset, self-registration) then report themselves as
 * unavailable instead of silently failing — see isConfigured().
 *
 * Usage:
 *   if (Mailer::isConfigured()) {
 *       Mailer::send('user@example.com', 'Subject', '<p>HTML body</p>');
 *   }
 */
class Mailer
{
    private const API_URL = 'https://api.resend.com/emails';

    public static function isConfigured(): bool
    {
        return self::apiKey() !== '' && self::fromAddress() !== '';
    }

    /**
     * Sends one HTML email via the Resend API.
     *
     * @throws MailException if the service isn't configured, or on any
     *                        HTTP/network error (message is safe to log but
     *                        not to show to the end user verbatim).
     */
    public static function send(string $to, string $subject, string $html): void
    {
        if (!self::isConfigured()) {
            throw new MailException('Mailer::send() called without RESEND_API_KEY/MAIL_FROM_ADDRESS configured');
        }

        self::post(self::buildPayload($to, $subject, $html));
    }

    /**
     * Pure payload builder, split out from send() so it can be unit-tested
     * without making a real network call.
     */
    public static function buildPayload(string $to, string $subject, string $html): array
    {
        $fromName = self::fromName();
        $from     = $fromName !== '' ? "{$fromName} <" . self::fromAddress() . '>' : self::fromAddress();

        return [
            'from'    => $from,
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
        ];
    }

    // ── Config ────────────────────────────────────────────────────────────────

    private static function apiKey(): string
    {
        $v = getenv('RESEND_API_KEY');
        return $v === false ? '' : trim($v);
    }

    private static function fromAddress(): string
    {
        $v = getenv('MAIL_FROM_ADDRESS');
        return $v === false ? '' : trim($v);
    }

    private static function fromName(): string
    {
        $v = getenv('MAIL_FROM_NAME');
        return $v === false || trim($v) === '' ? 'BraDypUS' : trim($v);
    }

    // ── Transport ────────────────────────────────────────────────────────────

    /** @throws MailException */
    private static function post(array $payload): void
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::API_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . self::apiKey(),
                'Content-Type: application/json',
            ],
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new MailException("Resend API network error: {$error}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new MailException("Resend API error {$httpCode}: {$body}");
        }
    }
}
