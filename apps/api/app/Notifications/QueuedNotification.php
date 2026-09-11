<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Every notification this application sends, and what is true of all of them
 * (ADR 0035). NotificationsAreQueuedTest fails for one that does not extend it.
 *
 * **Queued.** A notification is mail, and mail is a network call to a server
 * nobody here controls. Sent inside the request, a slow mail server would sit
 * inside a checkout, and a failing one would answer 500 for an order already
 * placed (ADR 0013). The `queue` service sends them.
 *
 * **Only after the commit.** Raised inside a transaction that then rolled back,
 * a notification would tell somebody about something that did not happen.
 * `afterCommit()` holds it until the transaction it was raised in commits.
 *
 * **Linking to the web application**, never to this one. The API is not
 * reachable from a browser, so a link to it in somebody's inbox is a dead link
 * (ADR 0003).
 *
 * **Quoting what people typed.** The mail template renders Markdown, so a shop
 * name or a reason containing `[text](address)` would arrive as a link in
 * somebody else's inbox. `quoted()` escapes the brackets, and every piece of
 * text a user wrote goes through it.
 *
 * Subclasses take their models as plain promoted properties, not `readonly`
 * ones: a queued notification is serialised and rebuilt, and PHP refuses to
 * set a readonly property from the parent's scope, which is where Laravel's
 * SerializesModels does it.
 */
abstract class QueuedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    /**
     * Mail, and only mail. A notification centre inside the site would be the
     * database channel, and nothing on the site reads one yet.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** An address on the web application, which is where every link goes. */
    protected function frontend(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').$path;
    }

    /** Text somebody typed, made safe to put in a Markdown mail. */
    protected function quoted(string $text): string
    {
        return str_replace(['[', ']'], ['\\[', '\\]'], $text);
    }
}
