<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Notifications\Account\PasswordChanged;
use App\Notifications\QueuedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * Every notification is queued, and waits for the transaction that raised it.
 *
 * A notification sent inside a request would put a mail server's latency, and
 * its failures, inside a checkout (ADR 0013, ADR 0035). Extending
 * QueuedNotification is what prevents that, so a notification that does not
 * extend it is a failure here rather than a slow checkout in production.
 */
final class NotificationsAreQueuedTest extends TestCase
{
    public function test_every_notification_extends_the_queued_base(): void
    {
        $classes = collect(File::allFiles(app_path('Notifications')))
            ->map(static fn (SplFileInfo $file): string => 'App\\Notifications\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                $file->getRelativePathname(),
            ))
            ->reject(static fn (string $class): bool => $class === QueuedNotification::class)
            ->values();

        $this->assertNotEmpty($classes);

        foreach ($classes as $class) {
            $this->assertTrue(
                is_subclass_of($class, QueuedNotification::class),
                "{$class} does not extend QueuedNotification, so it would be sent inside the request.",
            );
        }
    }

    public function test_the_base_is_queued_and_waits_for_the_commit(): void
    {
        $this->assertTrue((new ReflectionClass(QueuedNotification::class))->implementsInterface(ShouldQueue::class));
        $this->assertTrue((new PasswordChanged)->afterCommit);
    }
}
