<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Liveness for callers outside the container.
 *
 * Docker's own health check uses /up, which Laravel registers and which boots
 * the framework. This endpoint exists for the Next.js server and for anything
 * in front of it, and answers the same question through the versioned API so
 * that a caller needs to know one base path rather than two.
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
