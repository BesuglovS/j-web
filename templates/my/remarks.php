<?php $student = $student ?? []; $rows = $rows ?? []; ?>
<div class="card overflow-hidden">
  <table class="table">
    <thead><tr><th>Дата</th><th>Предмет</th><th>Замечание</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?php echo e($r['date']); ?></td>
        <td><?php echo e($r['subject']); ?></td>
        <td><?php echo e($r['text']); ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="3" class="text-slate-400 text-center py-6">У вас нет замечаний.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>