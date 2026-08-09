<?php $rows = $rows ?? []; ?>
<div class="flex justify-end mb-4"><a href="/admin/import" class="btn-secondary">← Импорт</a></div>
<div class="card overflow-hidden">
  <table class="table">
    <thead><tr><th>Дата</th><th>Файл</th><th>Тип</th><th>Успешно</th><th>Ошибок</th><th>Детали</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?php echo e($r['created_at']); ?></td>
        <td><?php echo e($r['filename']); ?></td>
        <td><?php echo e($r['kind']); ?></td>
        <td class="text-emerald-700"><?php echo (int)$r['rows_ok']; ?></td>
        <td class="<?php echo (int)$r['rows_fail'] ? 'text-red-600' : ''; ?>"><?php echo (int)$r['rows_fail']; ?></td>
        <td class="text-xs max-w-md"><?php if ($r['detail']): $lines=explode("\n",$r['detail']); echo implode('<br>', array_map('e', $lines)); endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="text-slate-400 text-center py-6">Импортов не было</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>