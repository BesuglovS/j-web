<?php

/**
 * Рендер шаблонов: layouts + страницы.
 */
class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'app'): string
    {
        $tp = config()['templates_path'];
        $data['__content'] = self::partial($template, $data);

        $file = $tp . '/layouts/' . $layout . '.php';
        if (!is_file($file)) {
            $file = $tp . '/layouts/app.php';
        }
        return self::compile($file, $data);
    }

    public static function partial(string $template, array $data = []): string
    {
        $file = config()['templates_path'] . '/' . $template . '.php';
        if (!is_file($file)) {
            return 'Шаблон не найден: ' . e($template);
        }
        return self::compile($file, $data);
    }

    private static function compile(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return ob_get_clean();
    }
}