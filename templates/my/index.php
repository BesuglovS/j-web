<?php
$student = $data['student'] ?? [];
$lastMarks = $data['lastMarks'] ?? [];
$pendingHw = $data['pendingHw'] ?? [];
$remarks = $data['remarks'] ?? [];
$progress = $data['progress'] ?? null;
$name = trim($student['last_name'].' '.$student['first_name'].' '.$student['middle_name']);
?>
<div class="card mb-4 p-4">
  <h3 class="font-semibold"><?php echo e($name); ?></h3>
  <span class="text-sm text-slate-500">Группы: <?php echo e($student['all_class_names'] ?? ($student['class_name'] ?? '')); ?></span>
</div>

<div class="grid lg:grid-cols-3 gap-6">
  <div class="card p-4">
    <h4 class="font-semibold mb-3">Оценки</h4>
    <?php foreach ($lastMarks as $m): ?>
      <div class="flex justify-between py-1 border-b text-sm">
        <span><?php echo e($m['date']); ?> · <?php echo e($m['subject']); ?><?php if (!empty($m['class_name'])): ?> <span class="text-xs text-slate-400">(<?php echo e($m['class_name']); ?>)</span><?php endif; ?> — <span class="text-xs text-slate-500"><?php echo e(work_type_label($m['work_type'] ?? null)).(!empty($m['comment']) ? ' · '.e(trim((string)$m['comment'])) : ''); ?></span></span>
        <b><?php echo (int)$m['value']; ?></b>
      </div>
    <?php endforeach; ?>
    <?php if (!$lastMarks): ?><p class="text-slate-400 text-sm">Оценок пока нет.</p><?php endif; ?>
  </div>

  <div class="card p-4">
    <h4 class="font-semibold mb-3">Домашние задания</h4>
    <?php foreach ($pendingHw as $h): ?>
      <div class="py-1 border-b text-sm">
        <b><?php echo e($h['title'] ?: 'Задание'); ?></b> — <?php echo e($h['subject']); ?>
        <div class="text-xs text-slate-500">
          <?php echo e(($h['lesson_date'] ?? '') ? 'урок '.$h['lesson_date'] : ''); ?>
          <?php if (!empty($h['class_name'])): ?> · <?php echo e($h['class_name']); ?><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$pendingHw): ?><p class="text-slate-400 text-sm">Заданий нет.</p><?php endif; ?>
  </div>

  <div class="card p-4">
    <h4 class="font-semibold mb-3">Замечания</h4>
    <?php foreach ($remarks as $r): ?>
      <div class="py-1 border-b text-sm">
        <div class="text-slate-500 text-xs"><?php echo e($r['date']); ?> · <?php echo e($r['subject']); ?><?php if (!empty($r['class_name'])): ?> · <?php echo e($r['class_name']); ?><?php endif; ?></div>
        <?php echo e($r['text']); ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$remarks): ?><p class="text-slate-400 text-sm">Замечаний нет.</p><?php endif; ?>
  </div>
</div>

<?php echo View::partial('partials/python_student_progress', ['progress' => $progress, 'refreshUrl' => '/my?refresh=1']); ?>
