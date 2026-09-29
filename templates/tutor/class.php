<?php
$classId = (int)($data['classId'] ?? 0);
$className = (string)($data['className'] ?? '');
$students = $data['students'] ?? [];
$student = $data['student'] ?? null;
$bySubject = $data['bySubject'] ?? [];
$homeworks = $data['homeworks'] ?? [];
$remarks = $data['remarks'] ?? [];
$statusLabels = ['done'=>'Выполнено','partial'=>'Частично','not_done'=>'Не выполнено'];
?>
<div class="flex justify-between items-center mb-4">
  <a href="/tutor" class="btn-secondary">← Мои классы</a>
  <h3 class="font-semibold text-lg">Класс <?php echo e($className); ?></h3>
</div>

<?php if (!$students): ?>
  <div class="card p-6 text-slate-500">В классе пока нет активных студентов.</div>
<?php else: ?>
<div class="card mb-4 p-3">
  <form method="get" action="/tutor/class/<?php echo (int)$classId; ?>" class="grid sm:grid-cols-2 gap-3 items-end">
    <div>
      <label class="label">Ученик</label>
      <select name="student_id" class="input" onchange="this.form.submit()">
        <?php foreach ($students as $s): ?>
          <option value="<?php echo (int)$s['id']; ?>" <?php echo $student && (int)$student['id'] === (int)$s['id'] ? 'selected' : ''; ?>>
            <?php echo e(trim($s['last_name'].' '.$s['first_name'].' '.$s['middle_name'])); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="flex items-end gap-3 flex-wrap">
      <a href="/tutor/grades?class_id=<?php echo (int)$classId; ?>" class="btn-secondary">Сводка средних →</a>
    </div>
  </form>
</div>

<?php if ($student): ?>
<?php $name = trim($student['last_name'].' '.$student['first_name'].' '.$student['middle_name']); ?>
<div class="mb-4">
  <h4 class="font-semibold text-lg text-slate-800"><?php echo e($name); ?>
    <?php if (!empty($student['all_class_names'])): ?><span class="text-sm text-slate-500">(<?php echo e($student['all_class_names']); ?>)</span><?php endif; ?>
  </h4>
</div>

<div class="card mb-4 p-4">
  <h4 class="font-semibold mb-3">Оценки по предметам</h4>
  <?php if (!$bySubject): ?><p class="text-slate-400 text-sm">Оценок пока нет.</p><?php endif; ?>
  <?php foreach ($bySubject as $subject => $g): ?>
    <div class="flex flex-wrap items-center justify-between py-1.5 border-b">
      <span class="w-48"><?php echo e($subject); ?></span>
      <span class="text-sm">Среднее: <b><?php echo e((string)$g['avg']); ?></b></span>
      <span class="flex flex-wrap gap-1">
        <?php foreach ($g['items'] as $m): ?>
          <?php $stale = !(int)($m['is_current'] ?? 1); ?>
          <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-semibold <?php echo $stale ? 'bg-slate-100 text-slate-300 line-through' : ((int)$m['value']>=4?'bg-emerald-100 text-emerald-800':((int)$m['value']===3?'bg-amber-100 text-amber-800':'bg-red-100 text-red-800')); ?>"
                title="<?php echo e($m['date']).' — '.e(work_type_label($m['work_type'] ?? null)) . (!empty($m['comment']) ? ' · '.e(trim((string)$m['comment'])) : ''); ?>">
            <span class="text-[9px] font-normal opacity-75"><?php echo e(work_type_label($m['work_type'] ?? null)); ?></span>
            <?php echo (int)$m['value']; ?>
          </span>
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
        <div class="text-xs text-slate-500">
          <?php echo e(!empty($h['due_date']) ? 'срок '.$h['due_date'] : ($h['lesson_date'] ? 'урок '.$h['lesson_date'] : '')); ?>
          <?php if (!empty($h['class_name'])): ?> · <?php echo e($h['class_name']); ?><?php endif; ?>
        </div>
        <?php if ($h['description']): ?><div class="text-xs text-slate-600"><?php echo e($h['description']); ?></div><?php endif; ?>
        <div class="text-xs">
          Статус: <span class="<?php echo $h['my_status']==='done'?'text-emerald-700':($h['my_status']==='partial'?'text-amber-700':'text-slate-400'); ?>">
            <?php echo $h['my_status'] ? e($statusLabels[$h['my_status']]) : '—'; ?>
          </span>
          <?php if ($h['my_mark']): ?> · Результат: <b><?php echo (int)$h['my_mark']; ?></b><?php endif; ?>
          <?php if ($h['my_comment']): ?> · <span class="text-slate-500"><?php echo e($h['my_comment']); ?></span><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$homeworks): ?><p class="text-slate-400 text-sm">Заданий нет.</p><?php endif; ?>
  </div>

  <div class="card p-4">
    <h4 class="font-semibold mb-3">Замечания</h4>
    <?php foreach ($remarks as $r): ?>
      <div class="py-1.5 border-b text-sm">
        <div class="text-xs text-slate-500"><?php echo e($r['date']); ?> · <?php echo e($r['subject']); ?><?php if (!empty($r['class_name'])): ?> · <?php echo e($r['class_name']); ?><?php endif; ?></div>
        <?php echo e($r['text']); ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$remarks): ?><p class="text-slate-400 text-sm">Замечаний нет.</p><?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>