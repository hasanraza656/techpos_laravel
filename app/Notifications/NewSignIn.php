<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewSignIn extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $ip, public string $when) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('A new sign-in to your Tech POS account')->line('We noticed a sign-in with a new network or browser combination.')->line('Time (UTC): '.$this->when)->line('IP address: '.$this->ip)->action('Review account security', config('app.frontend_url').'/account')->line('If this was not you, reset your password and revoke unfamiliar sessions.');
    }
}
