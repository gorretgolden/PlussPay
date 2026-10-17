<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * One thing that happened, handed to somebody who can read about it.
 *
 * The database channel only: the hosting runs no queue worker, so the row is written in the
 * request that made it and waits here until the reader marks it seen. Everything the inbox
 * prints travels inside the payload, so a notice still reads correctly after the audit row
 * it came from has been pruned.
 */
class ActivityRecorded extends Notification
{
    use Queueable;

    /**
     * @param  array{section: string, headline: string, body: string, actor: string,
     *               actor_role: string, path: string, link_label: string, log_id: string}  $news
     */
    public function __construct(private readonly array $news)
    {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return $this->news;
    }
}
