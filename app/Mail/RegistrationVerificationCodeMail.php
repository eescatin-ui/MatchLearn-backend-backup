<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationVerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $code)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your MatchLearn verification code');
    }

    public function content(): Content
    {
        return new Content(
            html: '<p>Your MatchLearn verification code is:</p>'
                .'<p style="font-size:24px;font-weight:bold;letter-spacing:6px">'.e($this->code).'</p>'
                .'<p>This code expires in 10 minutes. If you did not request it, you can ignore this email.</p>'
        );
    }
}
