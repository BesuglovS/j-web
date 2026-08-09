<?php

class AuthController
{
    public function loginForm(): string
    {
        $user = Auth::user();

        // Уже авторизован с ролью — на главную по роли
        if ($user && !empty($user['role'])) {
            Auth::home();
        }

        // SSO-пользователь без локальной записи журнала — нет доступа
        if ($user && !empty($user['is_sso'])) {
            return View::render('my/empty', [
                'note' => 'Ваша учётная запись авторизована через единый портал, но не привязана к журналу. Обратитесь к администратору.',
            ]);
        }

        $authUrl = config()['auth_url'];
        $ssoLoginUrl = $authUrl . '/index.php?page=login&redirect=' . urlencode(base_url() . '/login');

        return View::render('auth/login', [
            'sso_login_url' => $ssoLoginUrl,
            'error'         => flash_get('login_error'),
            'success'       => flash_get('login_success'),
        ], 'guest');
    }

    public function login(): void
    {
        if (!verify_csrf()) {
            flash_set('login_error', 'Ошибка безопасности. Попробуйте ещё раз.');
            redirect('/login');
        }
        $login = trim((string)($_POST['login'] ?? ''));
        $pass  = (string)($_POST['password'] ?? '');

        if ($login === '' || $pass === '') {
            flash_set('login_error', 'Заполните логин и пароль.');
            redirect('/login');
        }
        if (Auth::attempt($login, $pass)) {
            Auth::home();
        }
        flash_set('login_error', 'Неверный логин или пароль.');
        redirect('/login');
    }

    public function logout(): void
    {
        $isSso = Auth::isSso();
        Auth::logout();

        if ($isSso) {
            // Очищаем общую сессию единого портала
            $authUrl = config()['auth_url'];
            redirect($authUrl . '/api/logout.php?redirect=' . urlencode(base_url() . '/login'));
        }
        redirect('/login');
    }

    public function changePassword(): void
    {
        Auth::requireLogin();
        if (Auth::isSso()) {
            flash_set('pw_error', 'Пароль управляется на портале авторизации.');
            redirect(self::homePath());
        }
        if (!verify_csrf()) {
            flash_set('pw_error', 'Ошибка безопасности.');
        } else {
            $current = (string)($_POST['current_password'] ?? '');
            $new     = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');

            $user = Auth::user();
            if (!password_verify($current, $user['password_hash'])) {
                flash_set('pw_error', 'Текущий пароль указан неверно.');
            } elseif (strlen($new) < 6) {
                flash_set('pw_error', 'Новый пароль должен содержать минимум 6 символов.');
            } elseif ($new !== $confirm) {
                flash_set('pw_error', 'Пароли не совпадают.');
            } else {
                Auth::changePassword((int)$user['id'], $new);
                flash_set('pw_ok', 'Пароль успешно изменён.');
            }
        }
        redirect($_POST['back'] ?? self::homePath());
    }

    private static function homePath(): string
    {
        return match (Auth::role()) {
            'admin'   => '/admin',
            'student' => '/my',
            'parent'  => '/parent',
            default   => '/login',
        };
    }
}