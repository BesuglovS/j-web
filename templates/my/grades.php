<?php $student = $student ?? []; $bySubject = $bySubject ?? []; ?>
<?php if (!$bySubject): ?>
  <div class="card p-6 text-slate-500">Оценок пока нет.</div>
<?php endif; ?>
<?php foreach ($bySubject as $subject => $g): ?>
  <div class="card mb-4 p-4">
    <div class="flex justify-between items-center">
      <h4 class="font-semibold"><?php echo e($subject); ?></h4>
      <span class="text-sm text-slate-600">Среднее: <b><?php echo e((string)$g['avg']); ?></b></span>
    </div>
    <div class="mt-2 flex flex-wrap gap-2">
      <?php foreach ($g['items'] as $m): ?>
        <?php $stale = !(int)($m['is_current'] ?? 1); ?>
        <span class="inline-flex items-center px-2.5 py-1 rounded text-sm font-semibold
             <?php echo $stale ? 'bg-slate-100 text-slate-300 line-through' : ((int)$m['value'] >= 4 ? 'bg-emerald-100 text-emerald-800' : ((int)$m['value'] === 3 ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800')); ?>"
              title="<?php echo e($m['date'].' — '.$m['work_type'].($stale ? ' (переписано: '.trim((string)$m['comment']).')' : '')); ?>">
          <?php echo (int)$m['value']; ?>
        </span>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>