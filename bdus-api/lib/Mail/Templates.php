<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace Mail;

/**
 * Plain, self-contained en/it templates for the transactional emails
 * BraDypUS sends (password reset, self-registration). Deliberately not routed
 * through the frontend's vue-i18n system — these render server-side, in a
 * request that may have no authenticated user yet.
 *
 * Language is picked from the request's Accept-Language header (best-effort,
 * not stored per-user) — see Templates::langFromHeader().
 *
 * Markup is kept deliberately simple (inline styles, no external images/fonts,
 * no tracking pixels) for both broad email-client compatibility and spam-filter
 * friendliness — external assets and heavy markup are common spam signals.
 */
class Templates
{
    public static function langFromHeader(?string $acceptLanguage): string
    {
        return str_starts_with(strtolower($acceptLanguage ?? ''), 'it') ? 'it' : 'en';
    }

    /** @return array{subject: string, html: string} */
    public static function passwordReset(string $lang, string $appName, string $resetUrl, ?string $userName = null): array
    {
        $safeAppName = htmlspecialchars($appName, ENT_QUOTES);
        $safeUrl     = htmlspecialchars($resetUrl, ENT_QUOTES);
        $greeting    = self::greeting($lang, $userName);

        if ($lang === 'it') {
            $body = "<p>{$greeting}</p>"
                . "<p>Abbiamo ricevuto una richiesta di reimpostazione della password per il tuo account su <strong>{$safeAppName}</strong>.</p>"
                . self::button($safeUrl, 'Scegli una nuova password', 'Se il pulsante non funziona, copia questo indirizzo nel browser:')
                . '<p style="font-size:13px;color:#6b7280;">Il link è valido per un\'ora. Se non hai richiesto tu questa operazione, ignora pure questa email: la tua password resterà invariata.</p>';

            return [
                'subject' => "Reimposta la password — {$safeAppName}",
                'html'    => self::wrapper($body, $safeAppName),
            ];
        }

        $body = "<p>{$greeting}</p>"
            . "<p>We received a request to reset the password for your account on <strong>{$safeAppName}</strong>.</p>"
            . self::button($safeUrl, 'Choose a new password', "If the button doesn't work, copy this address into your browser:")
            . '<p style="font-size:13px;color:#6b7280;">This link is valid for one hour. If you didn\'t request this, you can safely ignore this email — your password won\'t change.</p>';

        return [
            'subject' => "Reset your password — {$safeAppName}",
            'html'    => self::wrapper($body, $safeAppName),
        ];
    }

    /** @return array{subject: string, html: string} */
    public static function registrationConfirmation(string $lang, string $appName, ?string $userName = null): array
    {
        $safeAppName = htmlspecialchars($appName, ENT_QUOTES);
        $greeting    = self::greeting($lang, $userName);

        if ($lang === 'it') {
            $body = "<p>{$greeting}</p>"
                . "<p>Il tuo account per <strong>{$safeAppName}</strong> è stato creato: grazie per esserti registrato.</p>"
                . '<p style="font-size:13px;color:#6b7280;">Un amministratore deve ancora abilitare il tuo accesso — riceverai una notifica separata (o potrai semplicemente riprovare ad accedere) non appena sarà attivo.</p>';

            return [
                'subject' => "Registrazione ricevuta — {$safeAppName}",
                'html'    => self::wrapper($body, $safeAppName),
            ];
        }

        $body = "<p>{$greeting}</p>"
            . "<p>Your account for <strong>{$safeAppName}</strong> has been created — thank you for registering.</p>"
            . '<p style="font-size:13px;color:#6b7280;">An administrator still needs to enable your access — you\'ll be able to sign in as soon as that happens.</p>';

        return [
            'subject' => "Registration received — {$safeAppName}",
            'html'    => self::wrapper($body, $safeAppName),
        ];
    }

    /** @return array{subject: string, html: string} */
    public static function registrationAdminNotice(string $lang, string $appName, string $userName, string $userEmail): array
    {
        $safeAppName   = htmlspecialchars($appName, ENT_QUOTES);
        $safeUserName  = htmlspecialchars($userName, ENT_QUOTES);
        $safeUserEmail = htmlspecialchars($userEmail, ENT_QUOTES);

        if ($lang === 'it') {
            $body = "<p>Un nuovo utente si è registrato su <strong>{$safeAppName}</strong>:</p>"
                . "<p><strong>{$safeUserName}</strong> — {$safeUserEmail}</p>"
                . '<p style="font-size:13px;color:#6b7280;">L\'account resta senza privilegi finché non lo abiliti da Gestione utenti.</p>';

            return [
                'subject' => "Nuovo utente registrato — {$safeAppName}",
                'html'    => self::wrapper($body, $safeAppName),
            ];
        }

        $body = "<p>A new user has registered on <strong>{$safeAppName}</strong>:</p>"
            . "<p><strong>{$safeUserName}</strong> — {$safeUserEmail}</p>"
            . '<p style="font-size:13px;color:#6b7280;">The account has no privileges until you enable it from Users management.</p>';

        return [
            'subject' => "New user registered — {$safeAppName}",
            'html'    => self::wrapper($body, $safeAppName),
        ];
    }

    // ── Shared layout ─────────────────────────────────────────────────────────

    private static function greeting(string $lang, ?string $userName): string
    {
        $name = $userName !== null ? htmlspecialchars($userName, ENT_QUOTES) : null;
        if ($lang === 'it') {
            return $name !== null ? "Ciao {$name}," : 'Ciao,';
        }
        return $name !== null ? "Hi {$name}," : 'Hi,';
    }

    private static function button(string $safeUrl, string $label, string $fallbackText): string
    {
        $safeLabel = htmlspecialchars($label, ENT_QUOTES);
        return '<p style="margin:28px 0 16px;">'
            . "<a href=\"{$safeUrl}\" style=\"display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:6px;font-weight:600;font-size:15px;\">{$safeLabel}</a>"
            . '</p>'
            . '<p style="font-size:12px;color:#9ca3af;margin:0 0 24px;">'
            . htmlspecialchars($fallbackText, ENT_QUOTES) . '<br>'
            . "<span style=\"word-break:break-all;\">{$safeUrl}</span></p>";
    }

    /** Wraps a message body in the shared header/footer shell. */
    private static function wrapper(string $bodyHtml, string $safeAppName): string
    {
        return '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;max-width:480px;margin:0 auto;color:#1f2937;line-height:1.5;">'
            . '<div style="padding:32px 28px 8px;font-size:20px;font-weight:700;color:#4f46e5;">BraDypUS</div>'
            . "<div style=\"padding:8px 28px 32px;font-size:15px;\">{$bodyHtml}</div>"
            . "<div style=\"padding:16px 28px;border-top:1px solid #e5e7eb;font-size:12px;color:#9ca3af;\">{$safeAppName}</div>"
            . '</div>';
    }
}
