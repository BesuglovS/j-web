<?php $rows = $rows ?? []; ?>
<?php $classes = $classes ?? []; ?>
<?php $edit = $edit ?? null; ?>
<div class="card overflow-hidden">
  <table class="table">
    <thead><tr><th>Класс</th><th>Название</th><th>Сокращение</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="font-medium"><?php echo e((string)($r['class_name'] ?? '')); ?></td>
        <td><?php echo e($r['name']); ?></td>
        <td><?php echo e((string)($r['short_name'] ?? '')); ?></td>
        <td class="text-right whitespace-nowrap">
          <a href="/admin/subjects?edit=<?php echo (int)$r['id']; ?>" class="btn-secondary">Изменить</a>
          <form method="post" action="/admin/subjects/delete/<?php echo (int)$r['id']; ?>" class="inline" onsubmit="return confirm('Удалить предмет?');">
            <?php echo csrf_field(); ?>
            <button class="btn-danger">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="text-slate-400 text-center py-6">Список пуст</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card mt-6 p-4">
  <h3 class="font-semibold mb-3"><?php echo $edit ? 'Редактировать предмет' : 'Добавить предмет'; ?></h3>
  <form method="post" action="/admin/subjects/save" class="grid sm:grid-cols-4 gap-3 items-end">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
    <div><label class="label">Класс</label>
      <select name="class_id" class="input" required>
        <option value="">—</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php echo (int)($edit['class_id'] ?? 0) === (int)$c['id'] ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label class="label">Название</label><input name="name" class="input" required value="<?php echo e($edit['name'] ?? ''); ?>"></div>
    <div><label class="label">Сокращение</label><input name="short_name" class="input" value="<?php echo e($edit['short_name'] ?? ''); ?>"></div>
    <div class="flex items-center gap-2">
      <button class="btn-primary">Сохранить</button>
      <?php if ($edit): ?><a href="/admin/subjects" class="btn-secondary">Отмена</a><?php endif; ?>
    </div>
  </form>
</div>