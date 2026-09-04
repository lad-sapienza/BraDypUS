<?php

/**
 * @copyright 2007-2026 Julian Bogdani
 * @license AGPL-3.0; see LICENSE
 */

declare(strict_types=1);

namespace Mail;

/**
 * Plain, self-contained en/it templates for the two transactional emails
 * BraDypUS sends (password reset, self-registration). Deliberately not routed
 * through the frontend's vue-i18n system — these render server-side, in a
 * request that may have no authenticated user yet.
 *
 * Language is picked from the request's Accept-Language header (best-effort,
 * not stored per-user) — see Templates::langFromHeader().
 */
class Templates
{
    public static function langFromHeader(?string $acceptLanguage): string
    {
        return str_starts_with(strtolower($acceptLanguage ?? ''), 'it') ? 'it' : 'en';
    }

    /** @return array{subject: string, html: string} */
    public static function passwordReset(string $lang, string $appName, string $resetUrl): array
    {
        $appName  = htmlspecialchars($appName, ENT_QUOTES);
        $safeUrl  = htmlspecialchars($resetUrl, ENT_QUOTES);

        if ($lang === 'it') {
            return [
                'subject' => "Reimposta la password — {$appName}",
                'html'    => "<p>Hai richiesto di reimpostare la password per <strong>{$appName}</strong>.</p>"
                    . "<p><a href=\"{$safeUrl}\">Clicca qui per scegliere una nuova password</a></p>"
                    . "<p>Il link scade tra un'ora. Se non hai richiesto tu questa operazione, ignora pure questa email.</p>",
            ];
        }
        return [
            'subject' => "Reset your password — {$appName}",
            'html'    => "<p>You requested a password reset for <strong>{$appName}</strong>.</p>"
                . "<p><a href=\"{$safeUrl}\">Click here to choose a new password</a></p>"
                . "<p>This link expires in one hour. If you didn't request this, you can safely ignore this email.</p>",
        ];
    }

    /** @return array{subject: string, html: string} */
    public static function registrationConfirmation(string $lang, string $appName): array
    {
        $appName = htmlspecialchars($appName, ENT_QUOTES);

        if ($lang === 'it') {
            return [
                'subject' => "Registrazione ricevuta — {$appName}",
                'html'    => "<p>Il tuo account per <strong>{$appName}</strong> è stato creato.</p>"
                    . '<p>Grazie per esserti registrato.</p>',
            ];
        }
        return [
            'subject' => "Registration received — {$appName}",
            'html'    => "<p>Your account for <strong>{$appName}</strong> has been created.</p>"
                . '<p>Thank you for registering.</p>',
        ];
    }

    /** @return array{subject: string, html: string} */
    public static function registrationAdminNotice(string $lang, string $appName, string $userName, string $userEmail): array
    {
        $appName  = htmlspecialchars($appName, ENT_QUOTES);
        $userName = htmlspecialchars($userName, ENT_QUOTES);
        $userEmail = htmlspecialchars($userEmail, ENT_QUOTES);

        if ($lang === 'it') {
            return [
                'subject' => "Nuovo utente registrato — {$appName}",
                'html'    => "<p>Un nuovo utente si è registrato su <strong>{$appName}</strong>:</p>"
                    . "<p>{$userName} ({$userEmail})</p>",
            ];
        }
        return [
            'subject' => "New user registered — {$appName}",
            'html'    => "<p>A new user has registered on <strong>{$appName}</strong>:</p>"
                . "<p>{$userName} ({$userEmail})</p>",
        ];
    }
}
