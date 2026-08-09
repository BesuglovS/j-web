<?php
$rows = $rows ?? [];
$classes = $classes ?? [];
$classId = (int)($classId ?? 0);
?>
<div class="mb-4 p-3 rounded bg-sky-50 border border-sky-200 text-sky-800 text-sm">
  Студенты создаются и редактируются в едином портале —
  <a class="underline" href="<?php echo e(config()['auth_url']); ?>/index.php?page=admin-users" target="_blank">auth.nayanovaacademy.ru → Пользователи</a>
  (состав класса — в разделе
  <a class="underline" href="<?php echo e(config()['auth_url']); ?>/index.php?page=admin-groups" target="_blank">Группы</a>).
  Здесь они отображаются только для чтения.
</div>
<div class="mb-4">
  <form method="get" action="/admin/students" class="inline">
    <select name="class_id" class="input" onchange="this.form.submit()">
      <option value="0">— выберите класс —</option>
      <?php foreach ($classes as $c): ?>
        <option value="<?php echo (int)$c['id']; ?>" <?php echo $classId === (int)$c['id'] ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>
<?php if ($classId): ?>
<div class="card overflow-hidden">
  <table class="table">
    <thead><tr><th>ФИО</th><th>Логин</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="/admin/students/<?php echo (int)$r['id']; ?>" class="text-blue-600 hover:underline"><?php echo e(trim($r['last_name'].' '.$r['first_name'].' '.$r['middle_name'])); ?></a></td>
        <td><?php echo e((string)($r['login'] ?? '—')); ?></td>
        <td class="text-right"><a href="/admin/students/<?php echo (int)$r['id']; ?>" class="btn-secondary">Просмотр</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="3" class="text-slate-400 text-center py-6">В классе нет учеников.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<div class="card p-6 text-slate-500">Выберите класс для просмотра списка учеников.</div>
<?php endif; ?>
<div class="card mb-4 mt-6 p-4">
  <div class="flex items-center justify-between gap-3 flex-wrap">
    <div class="text-sm text-slate-600">
      <div class="font-semibold text-slate-800">Синхронизация с порталом</div>
      Обновляет список учеников из auth.nayanovaacademy.ru по составу каждого класса.
      Изменения, внесённые на портале, будут перенесены в журнал.
    </div>
    <form method="post" action="/admin/students/sync" class="inline">
      <?php echo csrf_field(); ?>
      <button class="btn-danger-solid">Синхронизировать с порталом</button>
    </form>
  </div>
</div>