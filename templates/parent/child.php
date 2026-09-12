<?php
$parent = $parent ?? [];
$child = $child ?? [];
$bySubject = $bySubject ?? [];
$homeworks = $homeworks ?? [];
$remarks = $remarks ?? [];
$name = trim($child['last_name'].' '.$child['first_name'].' '.$child['middle_name']);
$statusLabels = ['done'=>'Выполнено','partial'=>'Частично','not_done'=>'Не выполнено'];
?>
<div class="flex justify-between items-center mb-4">
  <a href="/parent" class="btn-secondary">← Мои дети</a>
  <h3 class="font-semibold text-lg"><?php echo e($name); ?> <span class="text-slate-500 text-sm">(<?php echo e($child['class_name'] ?? ''); ?>)</span></h3>
</div>

<div class="card mb-6 p-4">
  <h4 class="font-semibold mb-3">Оценки по предметам</h4>
  <?php if (!$bySubject): ?><p class="text-slate-400 text-sm">Оценок пока нет.</p><?php endif; ?>
  <?php foreach ($bySubject as $subject => $g): ?>
    <div class="flex flex-wrap items-center justify-between py-1.5 border-b">
      <span class="w-48"><?php echo e($subject); ?></span>
      <span class="text-sm">Среднее: <b><?php echo e((string)$g['avg']); ?></b></span>
      <span class="flex flex-wrap gap-1">
        <?php foreach ($g['items'] as $m): ?>
          <?php $stale = !(int)($m['is_current'] ?? 1); ?>
          <span class="inline-flex px-1.5 py-0.5 rounded text-xs font-semibold <?php echo $stale ? 'bg-slate-100 text-slate-300 line-through' : ((int)$m['value']>=4?'bg-emerald-100 text-emerald-800':((int)$m['value']===3?'bg-amber-100 text-amber-800':'bg-red-100 text-red-800')); ?>"
                title="<?php echo e(trim((string)($m['comment'] ?? '')) ?: 'оценка'); ?>"><?php echo (int)$m['value']; ?></span>
        <?php endforeach; ?>
      </span>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid lg:grid-cols-2 gap-6">
  <div class="card p-4">
    <h4 class="font-semibold mb-3">Домашние задания</h4>
    <?php foreach ($homeworks as $h): ?>
      <div class="py-1.5 border-b text-sm">
        <b><?php echo e($h['title'] ?: 'Задание'); ?></b> — <?php echo e($h['subject']); ?>
        <div class="text-xs">
          Статус: <span class="<?php echo $h['my_status']==='done'?'text-emerald-700':($h['my_status']==='partial'?'text-amber-700':'text-slate-400'); ?>">
            <?php echo $h['my_status'] ? e($statusLabels[$h['my_status']]) : '—'; ?>
          </span>
          <?php if ($h['my_mark']): ?> · Результат: <b><?php echo (int)$h['my_mark']; ?></b><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$homeworks): ?><p class="text-slate-400 text-sm">Заданий нет.</p><?php endif; ?>
  </div>

  <div class="card p-4">
    <h4 class="font-semibold mb-3">Замечания</h4>
    <?php foreach ($remarks as $r): ?>
      <div class="py-1.5 border-b text-sm">
        <div class="text-xs text-slate-500"><?php echo e($r['date']); ?> · <?php echo e($r['subject']); ?></div>
        <?php echo e($r['text']); ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$remarks): ?><p class="text-slate-400 text-sm">Замечаний нет.</p><?php endif; ?>
  </div>
</div>