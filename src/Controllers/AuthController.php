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

        // Гость: форма входа живёт на едином портале, /login лишь
        // перебрасывает туда (возврат из логаута и старых ссылок).
        if ($user === null) {
            redirect(config()['auth_url'] . '/index.php?page=login&redirect='
                . urlencode(base_url() . '/login'));
        }

        // SSO-пользователь без зеркала учётной записи журнала — поясняем, куда идти
        return View::render('my/empty', [
            'note' => 'Ваша учётная запись авторизована через единый портал, но не привязана к журналу. Обратитесь к администратору.',
        ]);
    }

    public function logout(): void
    {
        Auth::logout();
        // Очищаем общую сессию единого портала
        $authUrl = config()['auth_url'];
        redirect($authUrl . '/api/logout.php?redirect=' . urlencode(base_url() . '/login'));
    }
}
