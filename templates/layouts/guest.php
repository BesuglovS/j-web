<?php
/**
 * Шаблон авторизации (гость).
 * Доступны: $__content, $error, $success, $title
 */
$title = $title ?? 'Вход в систему';
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($title); ?> — <?php echo e(config()['app_name']); ?></title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center p-4">
<div class="w-full max-w-md">
    <div class="text-center mb-6">
        <h1 class="text-2xl font-bold text-slate-800"><?php echo e(config()['app_name']); ?></h1>
        <p class="text-slate-500">Электронный журнал</p>
    </div>
    <?php echo $__content; ?>
</div>
<script src="/assets/tracking-client.js"></script>
</body>
</html>