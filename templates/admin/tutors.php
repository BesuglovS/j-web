<?php
$rows = $rows ?? [];
$classes = $classes ?? [];
$edit = $edit ?? null;
$editClassIds = $editClassIds ?? [];
?>
<div class="card overflow-hidden">
  <table class="table">
    <thead><tr><th>Тьютор</th><th>Логин (портал)</th><th>Классы</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="font-medium"><?php echo e($r['full_name']); ?></td>
        <td><?php echo e($r['login']); ?></td>
        <td><?php echo e((string)($r['class_names'] ?? '')); ?></td>
        <td class="text-right whitespace-nowrap">
          <a href="/admin/tutors?edit=<?php echo (int)$r['id']; ?>" class="btn-secondary">Изменить</a>
          <form method="post" action="/admin/tutors/delete/<?php echo (int)$r['id']; ?>" class="inline" onsubmit="return confirm('Удалить тьютора?');">
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
  <h3 class="font-semibold mb-3"><?php echo $edit ? 'Редактировать тьютора' : 'Добавить тьютора'; ?></h3>
  <p class="text-sm text-slate-500 mb-3">Учётная запись с таким логином должна существовать на портале
    auth.nayanovaacademy.ru — пароли журнал не хранит, вход только через портал.</p>
  <form method="post" action="/admin/tutors/save" class="grid sm:grid-cols-2 gap-3 items-start">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
    <div><label class="label">ФИО</label><input name="full_name" class="input" required value="<?php echo e($edit['full_name'] ?? ''); ?>"></div>
    <div><label class="label">Логин</label><input name="login" class="input" required value="<?php echo e($edit['login'] ?? ''); ?>"></div>
    <div class="sm:col-span-2"><label class="label">Классы (удерживайте Ctrl/Cmd для выбора нескольких)</label>
      <select name="classes[]" class="input" multiple size="8">
        <?php foreach ($classes as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php echo in_array((int)$c['id'], $editClassIds, true) ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="sm:col-span-2 flex items-center gap-2">
      <button class="btn-primary">Сохранить</button>
      <?php if ($edit): ?><a href="/admin/tutors" class="btn-secondary">Отмена</a><?php endif; ?>
    </div>
  </form>
</div>
