<?php
declare(strict_types=1);

namespace Panic;

interface Endpoint
{
    public function handle(Request $request): Response;
}
