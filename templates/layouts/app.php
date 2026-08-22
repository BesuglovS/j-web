<?php
/** Приложение. Доступны: $__content */
$user = Auth::user();
$role = $user['role'] ?? '';
$title = $title ?? config()['app_name'];
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($title); ?> — <?php echo e(config()['app_name']); ?></title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body class="bg-slate-100 min-h-screen">
<nav class="bg-slate-900 text-white">
  <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between flex-wrap gap-2">
    <a href="/" class="font-bold text-lg"><?php echo e(config()['app_name']); ?></a>
    <div class="flex items-center gap-1 flex-wrap text-sm">
      <?php if ($role === 'admin'): ?>
        <a href="/admin" class="px-3 py-1.5 rounded hover:bg-slate-700">Обзор</a>
        <a href="/admin/lessons" class="px-3 py-1.5 rounded hover:bg-slate-700">Журнал</a>
        <a href="/admin/classes" class="px-3 py-1.5 rounded hover:bg-slate-700">Классы</a>
        <a href="/admin/subjects" class="px-3 py-1.5 rounded hover:bg-slate-700">Предметы</a>
        <a href="/admin/students" class="px-3 py-1.5 rounded hover:bg-slate-700">Студенты</a>
        <a href="/admin/parents" class="px-3 py-1.5 rounded hover:bg-slate-700">Родители</a>
        <a href="/admin/grades" class="px-3 py-1.5 rounded hover:bg-slate-700">Оценки</a>
        <a href="/admin/quarters" class="px-3 py-1.5 rounded hover:bg-slate-700">Периоды</a>
        <a href="/admin/import" class="px-3 py-1.5 rounded hover:bg-slate-700">Импорт</a>
        <a href="/admin/users" class="px-3 py-1.5 rounded hover:bg-slate-700">Учётные</a>
      <?php elseif ($role === 'student'): ?>
        <a href="/my" class="px-3 py-1.5 rounded hover:bg-slate-700">Главная</a>
        <a href="/my/grades" class="px-3 py-1.5 rounded hover:bg-slate-700">Оценки</a>
        <a href="/my/homeworks" class="px-3 py-1.5 rounded hover:bg-slate-700">Домашние задания</a>
        <a href="/my/remarks" class="px-3 py-1.5 rounded hover:bg-slate-700">Замечания</a>
      <?php elseif ($role === 'parent'): ?>
        <a href="/parent" class="px-3 py-1.5 rounded hover:bg-slate-700">Мои дети</a>
      <?php endif; ?>
    </div>
    <div class="flex items-center gap-3">
      <span class="text-slate-300 text-sm"><?php echo e($user['full_name'] ?: $user['login']); ?></span>
      <a href="/logout" class="px-3 py-1.5 rounded bg-slate-700 hover:bg-slate-600 text-sm">Выйти</a>
    </div>
  </div>
</nav>

<main class="max-w-6xl mx-auto px-4 py-6">
  <?php
    $ok  = flash_get('admin_ok');
    $err = flash_get('admin_error');
    $iok = flash_get('admin_import');
    $myok = flash_get('my_ok');
    $myerr = flash_get('my_error');
    $pwok = flash_get('pw_ok');
    $pwerr = flash_get('pw_error');
  ?>
  <?php if ($ok): ?><div class="mb-4 p-3 rounded bg-emerald-50 border border-emerald-200 text-emerald-800"><?php echo e($ok); ?></div><?php endif; ?>
  <?php if ($iok): ?><div class="mb-4 p-3 rounded bg-emerald-50 border border-emerald-200 text-emerald-800"><?php echo e($iok); ?></div><?php endif; ?>
  <?php if ($myok): ?><div class="mb-4 p-3 rounded bg-emerald-50 border border-emerald-200 text-emerald-800"><?php echo e($myok); ?></div><?php endif; ?>
  <?php if ($pwok): ?><div class="mb-4 p-3 rounded bg-emerald-50 border border-emerald-200 text-emerald-800"><?php echo e($pwok); ?></div><?php endif; ?>
  <?php if ($err): ?><div class="mb-4 p-3 rounded bg-red-50 border border-red-200 text-red-800"><?php echo e($err); ?></div><?php endif; ?>
  <?php if ($myerr): ?><div class="mb-4 p-3 rounded bg-red-50 border border-red-200 text-red-800"><?php echo e($myerr); ?></div><?php endif; ?>
  <?php if ($pwerr): ?><div class="mb-4 p-3 rounded bg-red-50 border border-red-200 text-red-800"><?php echo e($pwerr); ?></div><?php endif; ?>

  <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <h2 class="text-xl font-semibold text-slate-800"><?php echo e($title ?? ''); ?></h2>
    <?php if ($role === 'parent'): ?>
    <form method="post" action="/password" class="flex items-center gap-2 bg-white border border-slate-200 rounded-lg p-1.5">
      <?php echo csrf_field(); ?>
      <input type="password" name="current_password" placeholder="Текущий пароль" class="px-2 py-1 text-sm border rounded">
      <input type="password" name="new_password" placeholder="Новый пароль" class="px-2 py-1 text-sm border rounded" minlength="6">
      <input type="password" name="confirm_password" placeholder="Повтор" class="px-2 py-1 text-sm border rounded" minlength="6">
      <button class="px-3 py-1 text-sm rounded bg-slate-700 hover:bg-slate-600 text-white">Сменить пароль</button>
    </form>
    <?php elseif ($role === 'admin'): ?>
    <a href="<?php echo e(config()['auth_url']); ?>/index.php?page=admin-change-password" target="_blank" class="text-sm text-slate-500 hover:text-slate-700">Сменить пароль на портале</a>
    <?php endif; ?>
  </div>

  <?php echo $__content; ?>
</main>
<script src="/assets/tracking-client.js"></script>
</body>
</html>