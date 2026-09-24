<?php
$classes = $classes ?? [];
$quarters = $quarters ?? [];
$subjects = $subjects ?? [];
$classId = (int)($classId ?? 0);
$quarterId = (int)($quarterId ?? 0);
$subjectId = (int)($subjectId ?? 0);
$grades = $grades ?? [];
$homeworks = $homeworks ?? [];
$className = (string)($className ?? '');
$students = $grades['students'] ?? [];
$markSets = $grades['markSets'] ?? [];
$subjectList = ($subjectId ? array_filter($subjects, fn($s)=>(int)$s['id']===$subjectId) : $subjects);
?>
<div class="card mb-4 p-3">
  <form method="get" action="/tutor/grades" class="grid sm:grid-cols-3 gap-3 items-end">
    <div><label class="label">Класс</label>
      <select name="class_id" class="input" onchange="this.form.submit()">
        <option value="0">— выберите класс —</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php echo $classId === (int)$c['id'] ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label class="label">Период</label>
      <select name="quarter_id" class="input">
        <option value="0">Весь период</option>
        <?php foreach ($quarters as $q): ?>
          <option value="<?php echo (int)$q['id']; ?>" <?php echo $quarterId === (int)$q['id'] ? 'selected' : ''; ?>><?php echo e($q['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label class="label">Предмет</label>
      <select name="subject_id" class="input">
        <option value="0">Все предметы</option>
        <?php foreach ($subjects as $s): ?>
          <option value="<?php echo (int)$s['id']; ?>" <?php echo $subjectId === (int)$s['id'] ? 'selected' : ''; ?>><?php echo e($s['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="sm:col-span-3"><button class="btn-primary">Показать</button></div>
  </form>
</div>

<?php if ($classId): ?>
<?php if ($students): ?>
<div class="card mb-4 p-3">
  <form method="get" action="/tutor/class/<?php echo (int)$classId; ?>" class="grid sm:grid-cols-2 gap-3 items-end">
    <div>
      <label class="label">Ученик</label>
      <select name="student_id" class="input" onchange="this.form.submit()">
        <option value="0">— выберите ученика —</option>
        <?php foreach ($students as $st): ?>
          <option value="<?php echo (int)$st['id']; ?>"><?php echo e(trim($st['last_name'].' '.$st['first_name'])); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="flex items-end">
      <button class="btn-primary">Подробно</button>
    </div>
  </form>
  <div class="mt-2 text-xs text-slate-500">Или нажмите на фамилию ученика в таблице — откроются его оценки, домашние задания и замечания.</div>
</div>
<?php endif; ?>
<div class="mb-3 text-slate-600 text-sm">Класс: <b><?php echo e($className); ?></b></div>
<div class="card overflow-hidden">
  <table class="table">
    <thead><tr><th>Студент</th><?php foreach ($subjectList as $s): ?><th class="text-center"><?php echo e($s['short_name'] ?: $s['name']); ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($students as $st): ?>
      <tr>
        <td class="whitespace-nowrap"><a href="/tutor/class/<?php echo (int)$classId; ?>?student_id=<?php echo (int)$st['id']; ?>" class="text-blue-600 hover:underline"><?php echo e(trim($st['last_name'].' '.$st['first_name'])); ?></a></td>
        <?php foreach ($subjectList as $s): ?>
          <?php
            $vals = [];
            foreach ($markSets[(int)$s['id']] ?? [] as $m) { if ((int)$m['student_id']===(int)$st['id']) $vals[] = (int)$m['value']; }
            $avg = $vals ? round(array_sum($vals)/count($vals), 2) : null;
          ?>
          <td class="text-center font-semibold"><?php echo $avg !== null ? e((string)$avg) : '—'; ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    <?php if (!$students): ?><tr><td colspan="<?php echo count($subjectList)+1; ?>" class="text-slate-400 text-center py-6">Нет данных</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card mt-4 p-4">
  <h4 class="font-semibold mb-3">Домашние задания</h4>
  <?php if (!$homeworks): ?><p class="text-slate-400 text-sm">Домашних заданий нет.</p><?php endif; ?>
  <?php foreach ($homeworks as $h): ?>
    <div class="py-1.5 border-b text-sm">
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <span><b><?php echo e($h['title'] ?: 'Задание'); ?></b> — <?php echo e($h['subject']); ?></span>
        <span class="text-xs text-slate-500">урок <?php echo e($h['lesson_date']); ?><?php if ($h['due_date']): ?> · срок <?php echo e($h['due_date']); ?><?php endif; ?></span>
      </div>
      <?php if ($h['description']): ?><div class="text-xs text-slate-600"><?php echo e($h['description']); ?></div><?php endif; ?>
      <?php
        $done = (int)$h['cnt_done'];
        $partial = (int)$h['cnt_partial'];
        $not = (int)$h['cnt_not'];
        $unmarked = max((int)$h['students_cnt'] - $done - $partial - $not, 0);
      ?>
      <div class="text-xs mt-1">
        <span class="text-emerald-700">Выполнено: <?php echo $done; ?></span>
        <span class="text-amber-700"> · Частично: <?php echo $partial; ?></span>
        <span class="text-red-700"> · Не выполнено: <?php echo $not; ?></span>
        <span class="text-slate-500"> · Без отметки: <?php echo $unmarked; ?></span>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card p-6 text-slate-500">Выберите класс для просмотра сводки средних оценок.</div>
<?php endif; ?>
