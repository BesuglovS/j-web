<?php $rows = $rows ?? []; ?>
<div class="mb-4 p-3 rounded bg-sky-50 border border-sky-200 text-sky-800 text-sm">
  Классы создаются и редактируются в едином портале —
  <a class="underline" href="<?php echo e(config()['auth_url']); ?>/index.php?page=admin-groups" target="_blank">auth.nayanovaacademy.ru → Группы</a>.
  Здесь они отображаются только для чтения и обновляются по кнопке ниже.
</div>
<div class="mb-4">
  <form method="post" action="/admin/classes/sync" class="inline">
    <?php echo csrf_field(); ?>
    <button class="btn-primary">Синхронизировать с порталом</button>
  </form>
</div>
<div class="card overflow-hidden">
  <table class="table">
    <thead><tr><th>Название</th><th>Класс</th><th>Учебный год</th><th>Студентов</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="font-medium"><?php echo e($r['name']); ?></td>
        <td><?php echo e((string)($r['grade'] ?? '')); ?></td>
        <td><?php echo e((string)($r['school_year'] ?? '')); ?></td>
        <td><?php echo (int)$r['cnt']; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="text-slate-400 text-center py-6">Список пуст — создайте группы на портале авторизации и нажмите «Синхронизировать».</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>