<?php
$rows = $rows ?? [];
$classes = $classes ?? [];
$classId = (int)($classId ?? 0);
?>
<div class="flex justify-end mb-4"><a href="/admin/lessons/new" class="btn-primary">Новое занятие</a></div>
<div class="card mb-4 p-2">
  <form method="get" action="/admin/lessons" class="flex items-center gap-2">
    <select name="class_id" class="input" onchange="this.form.submit()">
      <option value="0">Все классы</option>
      <?php foreach ($classes as $c): ?>
        <option value="<?php echo (int)$c['id']; ?>" <?php echo $classId === (int)$c['id'] ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>
<div class="card overflow-hidden">
  <table class="table">
    <thead><tr><th>Дата</th><th>Класс</th><th>Предмет</th><th>Тема</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): if ($classId && (int)$r['class_id'] !== $classId) continue; ?>
      <tr>
        <td><?php echo e($r['date']); ?> <?php echo $r['start_time'] ? e('· '.$r['start_time']) : ''; ?></td>
        <td><?php echo e($r['class_name']); ?></td>
        <td><?php echo e($r['short_name'] ?: $r['subject_name']); ?></td>
        <td><?php echo e((string)($r['topic'] ?? '')); ?></td>
        <td class="text-right">
          <a href="/admin/lessons/<?php echo (int)$r['id']; ?>" class="btn-secondary">Открыть</a>
          <a href="/admin/lessons/edit?id=<?php echo (int)$r['id']; ?>" class="btn-secondary">Изменить</a>
          <form method="post" action="/admin/lessons/delete/<?php echo (int)$r['id']; ?>" class="inline" onsubmit="return confirm('Удалить занятие?');">
            <?php echo csrf_field(); ?>
            <button class="btn-danger">Удалить</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="text-slate-400 text-center py-6">Занятий пока нет</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>