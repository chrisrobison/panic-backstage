<?php
declare(strict_types=1);

namespace Panic;

final class Me extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        return $this->ok([
            'user' => $this->auth->user(),
            'csrf' => $this->auth->csrf(),
            'capabilities' => $this->globalCapabilities(),
        ]);
    }
}
