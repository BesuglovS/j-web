<?php
$error = $error ?? null;
$success = $success ?? null;
$sso_login_url = $sso_login_url ?? '';
?>
<?php if ($success): ?><div class="alert-success mb-4"><?php echo e($success); ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert-error mb-4"><?php echo e($error); ?></div><?php endif; ?>

<div class="card p-6 sm:p-8 mb-4">
  <div class="text-center mb-4">
    <h2 class="text-lg font-semibold text-slate-800">Ученики и сотрудники</h2>
    <p class="text-sm text-slate-500 mt-1">Вход через единый портал</p>
  </div>
  <a href="<?php echo e($sso_login_url); ?>" class="w-full btn-primary inline-flex items-center justify-center">Войти через единый портал</a>
</div>

<div class="card">
  <form method="post" action="/login" class="space-y-5 p-6 sm:p-8">
    <?php echo csrf_field(); ?>
    <div class="text-center">
      <h2 class="text-lg font-semibold text-slate-800">Родители</h2>
      <p class="text-sm text-slate-500 mt-1">Введите логин и пароль</p>
    </div>
    <div>
      <label class="label">Логин</label>
      <input name="login" required autofocus class="input" value="<?php echo e($_POST['login'] ?? ''); ?>">
    </div>
    <div>
      <div class="flex items-center justify-between">
        <label class="label mb-1">Пароль</label>
      </div>
      <input name="password" type="password" required class="input">
    </div>
    <button class="w-full btn-primary">Войти</button>
  </form>
</div>