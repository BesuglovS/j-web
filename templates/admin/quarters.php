<?php $rows = $rows ?? []; ?>
<div class="card overflow-hidden">
  <table class="table">
    <thead><tr><th>Название</th><th>Начало</th><th>Конец</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="font-medium"><?php echo e($r['name']); ?></td>
        <td><?php echo e((string)($r['start_date'] ?? '')); ?></td>
        <td><?php echo e((string)($r['end_date'] ?? '')); ?></td>
        <td class="text-right">
          <form method="post" action="/admin/quarters/delete/<?php echo (int)$r['id']; ?>" class="inline" onsubmit="return confirm('Удалить период?');">
            <?php echo csrf_field(); ?>
            <button class="btn-danger">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card mt-6 p-4">
  <h3 class="font-semibold mb-3">Добавить период (четверть/семестр)</h3>
  <form method="post" action="/admin/quarters/save" class="grid sm:grid-cols-4 gap-3 items-end">
    <?php echo csrf_field(); ?>
    <div><label class="label">Название</label><input name="name" class="input" required></div>
    <div><label class="label">Начало</label><input name="start_date" type="date" class="input"></div>
    <div><label class="label">Конец</label><input name="end_date" type="date" class="input"></div>
    <button class="btn-primary">Сохранить</button>
  </form>
</div>