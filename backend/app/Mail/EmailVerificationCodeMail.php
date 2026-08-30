<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued: the SMTP round trip must never block the HTTP request that
 * triggers it (registration, "resend code"). Both the local `php artisan
 * dev` setup and the production container run a queue worker; with
 * QUEUE_CONNECTION=sync (bare `artisan serve`) it still sends inline.
 */
class EmailVerificationCodeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your NeoGames verification code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verification-code',
        );
    }
}
