<?php
$student = $student ?? [];
$lastMarks = $lastMarks ?? [];
$pendingHw = $pendingHw ?? [];
$remarks = $remarks ?? [];
$name = trim($student['last_name'].' '.$student['first_name'].' '.$student['middle_name']);
?>
<div class="card mb-4 p-4">
  <h3 class="font-semibold"><?php echo e($name); ?></h3>
  <span class="text-sm text-slate-500">Класс: <?php echo e($student['class_name'] ?? ''); ?></span>
</div>

<div class="grid lg:grid-cols-3 gap-6">
  <div class="card p-4">
    <h4 class="font-semibold mb-3">Последние оценки</h4>
    <?php foreach ($lastMarks as $m): ?>
      <div class="flex justify-between py-1 border-b text-sm">
        <span><?php echo e($m['date']); ?> · <?php echo e($m['subject']); ?></span>
        <b><?php echo (int)$m['value']; ?></b>
      </div>
    <?php endforeach; ?>
    <?php if (!$lastMarks): ?><p class="text-slate-400 text-sm">Оценок пока нет.</p><?php endif; ?>
  </div>

  <div class="card p-4">
    <h4 class="font-semibold mb-3">Ближайшие задания</h4>
    <?php foreach ($pendingHw as $h): ?>
      <div class="py-1 border-b text-sm">
        <b><?php echo e($h['title'] ?: 'Задание'); ?></b> — <?php echo e($h['subject']); ?>
        <div class="text-xs text-slate-500"><?php echo e(($h['lesson_date'] ?? '') ? 'урок '.$h['lesson_date'] : ''); ?></div>
      </div>
    <?php endforeach; ?>
    <?php if (!$pendingHw): ?><p class="text-slate-400 text-sm">Заданий нет.</p><?php endif; ?>
  </div>

  <div class="card p-4">
    <h4 class="font-semibold mb-3">Замечания</h4>
    <?php foreach ($remarks as $r): ?>
      <div class="py-1 border-b text-sm">
        <div class="text-slate-500 text-xs"><?php echo e($r['date']); ?> · <?php echo e($r['subject']); ?></div>
        <?php echo e($r['text']); ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$remarks): ?><p class="text-slate-400 text-sm">Замечаний нет.</p><?php endif; ?>
  </div>
</div>