<?php
declare(strict_types=1);

namespace Panic;

final class AuthEndpoint extends BaseEndpoint
{
    public function handle(Request $request): Response
    {
        return match ($this->params['action'] ?? '') {
            'login' => $this->login($request),
            'logout' => $this->logout(),
            default => $this->notFound()
        };
    }

    private function login(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            return Response::methodNotAllowed();
        }
        $email = trim((string) $request->body('email', ''));
        $password = (string) $request->body('password', '');
        $user = $this->db->one('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return Response::json(['error' => 'Invalid email or password'], 401);
        }
        $this->auth->login($user);
        return $this->ok(['user' => $this->auth->user(), 'csrf' => $this->auth->csrf()]);
    }

    private function logout(): Response
    {
        $this->auth->logout();
        return $this->ok(['ok' => true]);
    }
}
