<?php
$classes = $classes ?? [];
$quarters = $quarters ?? [];
$subjects = $subjects ?? [];
$classId = (int)($classId ?? 0);
$quarterId = (int)($quarterId ?? 0);
$subjectId = (int)($subjectId ?? 0);
$grades = $grades ?? [];
$students = $grades['students'] ?? [];
$markSets = $grades['markSets'] ?? [];
$subjectList = ($subjectId ? array_filter($subjects, fn($s)=>(int)$s['id']===$subjectId) : $subjects);
?>
<div class="card mb-4 p-3">
  <form method="get" action="/admin/grades" class="grid sm:grid-cols-3 gap-3 items-end">
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
<div class="card overflow-hidden">
  <table class="table">
    <thead><tr><th>Студент</th><?php foreach ($subjectList as $s): ?><th class="text-center"><?php echo e($s['short_name'] ?: $s['name']); ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($students as $st): ?>
      <tr>
        <td class="whitespace-nowrap"><?php echo e(trim($st['last_name'].' '.$st['first_name'])); ?></td>
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
<?php else: ?>
<div class="card p-6 text-slate-500">Выберите класс для просмотра сводки средних оценок.</div>
<?php endif; ?>