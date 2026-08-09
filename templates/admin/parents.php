<?php
$rows = $rows ?? [];
$classes = $classes ?? [];
$classId = (int)($classId ?? 0);
?>
<div class="flex justify-end mb-4"><a href="/admin/parents/new" class="btn-primary">Добавить родителя</a></div>
<div class="card mb-4 p-2">
  <form method="get" action="/admin/parents" class="inline">
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
    <thead><tr><th>ФИО</th><th>Логин</th><th>Дети (класс)</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="font-medium"><?php echo e(trim($r['last_name'].' '.$r['first_name'].' '.$r['middle_name'])); ?></td>
        <td><?php echo e((string)($r['login'] ?? '')); ?></td>
        <td class="text-xs"><?php echo e((string)($r['children'] ?? '')); ?></td>
        <td class="text-right">
          <a href="/admin/parents/edit?id=<?php echo (int)$r['id']; ?>" class="btn-secondary">Изменить</a>
          <form method="post" action="/admin/parents/delete/<?php echo (int)$r['id']; ?>" class="inline" onsubmit="return confirm('Удалить родителя?');">
            <?php echo csrf_field(); ?>
            <button class="btn-danger">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="text-slate-400 text-center py-6">В классе нет родителей.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<div class="card p-6 text-slate-500">Выберите класс для просмотра списка родителей.</div>
<?php endif; ?>