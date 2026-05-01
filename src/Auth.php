<?php
declare(strict_types=1);

namespace Panic;

final class Auth
{
    public function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
        $this->csrf();
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public function validCsrf(Request $request): bool
    {
        $token = $request->header('X-CSRF-Token') ?: (string) $request->body('_csrf', '');
        return $token !== '' && hash_equals($_SESSION['csrf'] ?? '', $token);
    }
}
