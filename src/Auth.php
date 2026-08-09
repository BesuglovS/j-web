<?php

/**
 * Аутентификация и проверка ролей.
 *
 * Два вида учётных записей:
 *  - администраторы и ученики авторизуются через единый портал
 *    auth.nayanovaacademy.ru (общая кука auth_session);
 *  - родители входят локально по логину/паролю из таблицы users.
 */
class Auth
{
    private const SSO_CACHE_TTL = 300; // 5 минут кэш проверки SSO-сессии

    // -----------------------------------------------------------
    // Единый портал (SSO): администраторы и ученики
    // -----------------------------------------------------------

    /**
     * Проверяет сессию через auth-web API и возвращает данные пользователя
     * ({ id, login, display_name, is_admin }) либо null.
     * Результат кэшируется в локальной PHP-сессии.
     */
    private static function ssoUser(): ?array
    {
        $cached = self::getSsoCache();
        if ($cached !== null) {
            return $cached;
        }

        $authUrl = config()['auth_url'];
        $cookieHeader = '';
        if (!empty($_COOKIE['auth_session'])) {
            $cookieHeader = 'auth_session=' . $_COOKIE['auth_session'];
        }

        $ch = curl_init($authUrl . '/api/check.php');
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($cookieHeader !== '') {
            $opts[CURLOPT_COOKIE] = $cookieHeader;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return null;
        }
        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['authenticated'])) {
            self::clearSsoCache();
            return null;
        }

        $user = $data['user'];
        self::setSsoCache($user);
        return $user;
    }

    private static function getSsoCache(): ?array
    {
        if (empty($_SESSION['sso_user'])) {
            return null;
        }
        $cachedAt = $_SESSION['sso_cached_at'] ?? 0;
        if (time() - $cachedAt > self::SSO_CACHE_TTL) {
            return null;
        }
        if (($_SESSION['sso_cookie_hash'] ?? '') !== self::ssoCookieHash()) {
            return null;
        }
        return $_SESSION['sso_user'];
    }

    private static function setSsoCache(array $user): void
    {
        $_SESSION['sso_user'] = $user;
        $_SESSION['sso_cached_at'] = time();
        $_SESSION['sso_cookie_hash'] = self::ssoCookieHash();
    }

    private static function clearSsoCache(): void
    {
        unset($_SESSION['sso_user'], $_SESSION['sso_cached_at'], $_SESSION['sso_cookie_hash']);
    }

    private static function ssoCookieHash(): string
    {
        return hash('sha256', $_COOKIE['auth_session'] ?? '');
    }

    // -----------------------------------------------------------
    // Текущий пользователь
    // -----------------------------------------------------------

    public static function user(): ?array
    {
        // Локальная сессия (родители) — приоритет.
        // Устаревшие локальные сессии администраторов/учеников больше не
        // принимаются: эти роли авторизуются только через единый портал.
        if (isset($_SESSION['user_id'])) {
            $st = Database::pdo()->prepare('SELECT * FROM users WHERE id = ?');
            $st->execute([(int)$_SESSION['user_id']]);
            $user = $st->fetch();
            if (!$user) {
                unset($_SESSION['user_id']);
                return null;
            }
            if (!in_array($user['role'], ['admin', 'student'], true)) {
                $user['is_sso'] = false;
                return $user;
            }
            unset($_SESSION['user_id']);
        }

        // SSO через auth-web
        $sso = self::ssoUser();
        if ($sso === null) {
            return null;
        }

        if (!empty($sso['is_admin'])) {
            return [
                'id'       => null,
                'role'     => 'admin',
                'login'    => (string)($sso['login'] ?? ''),
                'full_name' => (string)($sso['display_name'] ?? ''),
                'is_sso'   => true,
            ];
        }

        // Ученик: локальная привязка по логину (совпадает с логином auth-web)
        $st = Database::pdo()->prepare("SELECT * FROM users WHERE role='student' AND LOWER(login)=LOWER(?) LIMIT 1");
        $st->execute([(string)($sso['login'] ?? '')]);
        $user = $st->fetch();
        if ($user) {
            $user['is_sso'] = true;
            return $user;
        }

        // Авторизован через портал, но локальной записи журнала нет
        return [
            'id'       => null,
            'role'     => null,
            'login'    => (string)($sso['login'] ?? ''),
            'full_name' => (string)($sso['display_name'] ?? ''),
            'is_sso'   => true,
        ];
    }

    /** Пользователь вошёл через единый портал? */
    public static function isSso(): bool
    {
        $u = self::user();
        return $u !== null && !empty($u['is_sso']);
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u && $u['id'] !== null ? (int)$u['id'] : null;
    }

    public static function role(): ?string
    {
        $u = self::user();
        return $u ? $u['role'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    // -----------------------------------------------------------
    // Локальный вход (только родители)
    // -----------------------------------------------------------

    public static function attempt(string $login, string $password): bool
    {
        $st = Database::pdo()->prepare("SELECT * FROM users WHERE login = ? AND role='parent' LIMIT 1");
        $st->execute([trim($login)]);
        $user = $st->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            return true;
        }
        return false;
    }

    public static function logout(): void
    {
        self::clearSsoCache();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function changePassword(int $userId, string $newPassword): void
    {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $st = Database::pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $st->execute([$hash, $userId]);
    }

    // -----------------------------------------------------------
    // Гварды
    // -----------------------------------------------------------

    /**
     * Требует авторизации; для роли — роль должна совпадать (опционально).
     */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect('/login');
        }
    }

    public static function requireRole(string $role): void
    {
        self::requireLogin();
        $userRole = self::role();
        if ($userRole !== $role) {
            http_response_code(403);
            exit('Доступ запрещён.');
        }
    }

    /** Редирект в зависимости от роли */
    public static function home(): void
    {
        $role = self::role();
        if ($role === 'admin') {
            redirect('/admin');
        } elseif ($role === 'student') {
            redirect('/my');
        } elseif ($role === 'parent') {
            redirect('/parent');
        }
        redirect('/login');
    }
}