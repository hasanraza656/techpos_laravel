<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BusinessInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $business, public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('You’re invited to '.$this->business)->line('You have been invited to join '.$this->business.' on Tech POS.')->action('Review invitation', config('app.frontend_url').'/invitations/'.$this->token)->line('This invitation expires in 72 hours. You must sign in with the invited email address. Ignore it if you were not expecting an invitation.');
    }
}
