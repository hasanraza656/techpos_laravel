<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeadReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public array $lead) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Tech POS: new '.$this->lead['type'].' request')->line('From: '.$this->lead['email'])->line($this->lead['message'] ?? 'Newsletter opt-in')->line('Review this lead in platform administration.');
    }
}
