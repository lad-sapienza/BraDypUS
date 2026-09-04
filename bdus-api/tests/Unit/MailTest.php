<?php

declare(strict_types=1);

namespace Tests\Unit;

use Mail\Mailer;
use Mail\Templates;
use PHPUnit\Framework\TestCase;

/**
 * Pure, network-free tests for the Mail namespace: payload construction,
 * isConfigured() gating, and the en/it email templates.
 *
 * Mailer::send()'s actual cURL call to the Resend API is intentionally not
 * exercised here (same reasoning as Zotero\Client's real HTTP calls, which
 * are only exercised in the hurl demo seed, not PHPUnit) — this app has no
 * real Resend account to test against.
 */
class MailTest extends TestCase
{
    // ── isConfigured() ───────────────────────────────────────────────────────

    public function testNotConfiguredWithoutEnvVars(): void
    {
        putenv('RESEND_API_KEY');
        putenv('MAIL_FROM_ADDRESS');
        $this->assertFalse(Mailer::isConfigured());
    }

    public function testNotConfiguredWithOnlyApiKey(): void
    {
        putenv('RESEND_API_KEY=re_test_123');
        putenv('MAIL_FROM_ADDRESS');
        $this->assertFalse(Mailer::isConfigured());
        putenv('RESEND_API_KEY');
    }

    public function testConfiguredWithBothVarsSet(): void
    {
        putenv('RESEND_API_KEY=re_test_123');
        putenv('MAIL_FROM_ADDRESS=noreply@example.com');
        $this->assertTrue(Mailer::isConfigured());
        putenv('RESEND_API_KEY');
        putenv('MAIL_FROM_ADDRESS');
    }

    // ── buildPayload() ───────────────────────────────────────────────────────

    public function testBuildPayloadUsesDefaultFromName(): void
    {
        putenv('MAIL_FROM_ADDRESS=noreply@example.com');
        putenv('MAIL_FROM_NAME');

        $payload = Mailer::buildPayload('user@example.com', 'Subject', '<p>Body</p>');

        $this->assertSame('BraDypUS <noreply@example.com>', $payload['from']);
        $this->assertSame(['user@example.com'], $payload['to']);
        $this->assertSame('Subject', $payload['subject']);
        $this->assertSame('<p>Body</p>', $payload['html']);

        putenv('MAIL_FROM_ADDRESS');
    }

    public function testBuildPayloadUsesCustomFromName(): void
    {
        putenv('MAIL_FROM_ADDRESS=noreply@example.com');
        putenv('MAIL_FROM_NAME=My Institute');

        $payload = Mailer::buildPayload('user@example.com', 'Subject', 'Body');

        $this->assertSame('My Institute <noreply@example.com>', $payload['from']);

        putenv('MAIL_FROM_ADDRESS');
        putenv('MAIL_FROM_NAME');
    }

    // ── Templates::langFromHeader() ──────────────────────────────────────────

    public function testLangFromHeaderDetectsItalian(): void
    {
        $this->assertSame('it', Templates::langFromHeader('it-IT,it;q=0.9'));
        $this->assertSame('it', Templates::langFromHeader('IT'));
    }

    public function testLangFromHeaderDefaultsToEnglish(): void
    {
        $this->assertSame('en', Templates::langFromHeader('en-US,en;q=0.9'));
        $this->assertSame('en', Templates::langFromHeader(null));
        $this->assertSame('en', Templates::langFromHeader(''));
        $this->assertSame('en', Templates::langFromHeader('fr-FR'));
    }

    // ── Templates content ─────────────────────────────────────────────────────

    public function testPasswordResetTemplateContainsUrlAndEscapesAppName(): void
    {
        $mail = Templates::passwordReset('en', 'My <App>', 'https://example.com/reset?token=abc&email=x');

        $this->assertStringContainsString('My &lt;App&gt;', $mail['html']);
        $this->assertStringContainsString('https://example.com/reset?token=abc&amp;email=x', $mail['html']);
        $this->assertStringContainsString('reset your password', strtolower($mail['subject']));
    }

    public function testPasswordResetTemplateItalian(): void
    {
        $mail = Templates::passwordReset('it', 'App Demo', 'https://example.com/reset');

        $this->assertStringContainsString('reimposta', strtolower($mail['subject']));
        $this->assertStringContainsString('App Demo', $mail['html']);
    }

    public function testRegistrationConfirmationTemplate(): void
    {
        $mail = Templates::registrationConfirmation('en', 'App Demo');
        $this->assertStringContainsString('App Demo', $mail['html']);
        $this->assertStringContainsString('created', $mail['html']);
    }

    public function testRegistrationAdminNoticeEscapesUserData(): void
    {
        $mail = Templates::registrationAdminNotice('en', 'App Demo', '<script>x</script>', 'user@example.com');
        $this->assertStringNotContainsString('<script>', $mail['html']);
        $this->assertStringContainsString('user@example.com', $mail['html']);
    }
}
