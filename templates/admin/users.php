<?php $rows = $rows ?? []; $roles = ['admin'=>'Администратор','student'=>'Ученик','parent'=>'Родитель']; ?>
<div class="mb-4 p-3 rounded bg-sky-50 border border-sky-200 text-sky-800 text-sm">
  Учётные записи учеников и администраторов паролей не хранят: вход через единый портал
  (пароль меняется в <a class="underline" href="<?php echo e(config()['auth_url']); ?>/index.php?page=admin-users" target="_blank">админ-панели авторизации</a>).
  Родители хранятся локально.
</div>
<div class="card overflow-hidden">
  <table class="table">
    <thead><tr><th>Логин</th><th>Роль</th><th>ФИО</th><th>Создан</th><th>Пароль</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="font-medium"><?php echo e($r['login']); ?></td>
        <td><?php echo e($roles[$r['role']] ?? $r['role']); ?></td>
        <td><?php echo e((string)($r['full_name'] ?? '')); ?></td>
        <td class="text-sm text-slate-500"><?php echo e($r['created_at']); ?></td>
        <td>
          <?php if ($r['role'] === 'parent'): ?>
          <form method="post" action="/admin/users/reset" class="flex gap-2 items-center">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
            <input name="new_password" placeholder="Новый пароль" class="input text-sm" minlength="6" required>
            <button class="btn-secondary">Сбросить</button>
          </form>
          <?php else: ?>
            <a href="<?php echo e(config()['auth_url']); ?>/index.php?page=admin-users" target="_blank" class="btn-secondary text-sm">На портале</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>