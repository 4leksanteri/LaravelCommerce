<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| This file is deliberately empty, and should stay that way.
|
| This application has no HTML surface: it renders no Blade, serves no assets
| and has no session-backed pages. The Next.js application in apps/web is the
| only thing a person ever looks at, and it reaches this one over /api/v1
| through its own server (ADR 0003).
|
| The file remains because registering it is what defines the `web` middleware
| group, and Sanctum publishes its CSRF cookie route into that group. Removing
| the file would take the group with it.
|
| A route added here would be reachable only from inside the Docker network,
| because nothing proxies to it. If you find yourself wanting one, the question
| to answer first is which client is supposed to call it.
|
*/
