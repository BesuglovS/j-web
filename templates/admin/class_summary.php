<?php
/**
 * Сводка оценок класса по предметам.
 * Слоты: classes, quarters, subjects, classId, quarterId, subjectId,
 *        className, groups (см. GradeService::classSummary()).
 * Объединённые предметы (SubjectMerge) выводятся одним блоком; оценки
 * разложены по столбцам дат уроков, подсказка — тип работы и комментарий.
 */
$classes = $data['classes'] ?? [];
$quarters = $data['quarters'] ?? [];
$subjects = $data['subjects'] ?? [];
$classId = (int)($data['classId'] ?? 0);
$quarterId = (int)($data['quarterId'] ?? 0);
$subjectId = (int)($data['subjectId'] ?? 0);
$className = (string)($data['className'] ?? '');
$groups = $data['groups'] ?? [];
?>
<div class="card mb-4 p-3">
  <form method="get" action="/admin/class-summary" class="grid sm:grid-cols-3 gap-3 items-end">
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

<?php if (!$classId): ?>
  <div class="card p-6 text-slate-500">Выберите класс для просмотра оценок по предметам.</div>
<?php elseif (!$groups): ?>
  <div class="card p-6 text-slate-500">У класса нет предметов с оценками за выбранный период.</div>
<?php else: ?>
  <?php foreach ($groups as $g): ?>
    <div class="card mb-6 overflow-hidden">
      <div class="px-4 py-3 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-2">
        <h3 class="font-semibold text-slate-800">
          <?php echo e($g['label']); ?>
          <?php if (!empty($g['merged'])): ?>
            <span class="ml-1 inline-block px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800 align-middle">объединённый</span>
          <?php endif; ?>
        </h3>
        <div class="text-sm text-slate-600">
          Уроков: <b><?php echo (int)$g['lesson_count']; ?></b> ·
          Оценок: <b><?php echo (int)$g['marks_count']; ?></b> ·
          Средний по классу: <b><?php echo $g['avg'] !== null ? e((string)$g['avg']) : '—'; ?></b>
        </div>
      </div>
      <div class="overflow-x-auto">
      <table class="table">
        <thead>
          <tr>
            <th class="sticky left-0 bg-slate-50 z-10">Ученик</th>
            <?php foreach (($g['dates'] ?? []) as $d): ?>
              <th class="text-center whitespace-nowrap" title="<?php echo e(date('d.m.Y', strtotime((string)$d))); ?>">
                <?php echo e(date('d.m', strtotime((string)$d))); ?>
              </th>
            <?php endforeach; ?>
            <th class="text-center w-24">Средний</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($g['students'] as $row): ?>
          <tr>
            <td class="sticky left-0 bg-white z-10 whitespace-nowrap">
              <?php echo e(trim((string)$row['student']['last_name'] . ' ' . (string)$row['student']['first_name'])); ?>
            </td>
            <?php foreach (($g['dates'] ?? []) as $d): ?>
              <?php $cellMarks = $row['by_date'][$d] ?? []; ?>
              <td class="text-center align-middle">
                <?php if (!$cellMarks): ?>
                  <span class="text-slate-200">·</span>
                <?php else: ?>
                  <span class="inline-flex flex-col items-center gap-0.5">
                  <?php foreach ($cellMarks as $m): ?>
                    <?php
                      $stale = !(int)($m['is_current'] ?? 1);
                      $cls = $stale
                        ? 'bg-slate-100 text-slate-300 line-through'
                        : ((int)$m['value'] >= 4 ? 'bg-emerald-100 text-emerald-800'
                          : ((int)$m['value'] === 3 ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800'));
                      $tip = date('d.m.Y', strtotime((string)$m['date'])) . ' — ' . work_type_label($m['work_type'] ?? null);
                      if (!empty($g['merged']) && !empty($m['subject_name'])) {
                        $tip .= ' · ' . (string)$m['subject_name'];
                      }
                      if (!empty($m['is_retake'])) {
                        $tip .= ' · переписывание';
                      }
                      if (!empty($m['comment'])) {
                        $tip .= "\n" . trim((string)$m['comment']);
                      }
                    ?>
                    <span class="inline-flex items-center justify-center min-w-[1.25rem] px-1 py-0.5 rounded text-xs font-semibold <?php echo $cls; ?>" title="<?php echo e($tip); ?>">
                      <?php echo (int)$m['value']; ?>
                    </span>
                  <?php endforeach; ?>
                  </span>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
            <td class="text-center font-semibold"><?php echo $row['avg'] !== null ? e((string)$row['avg']) : '—'; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$g['students']): ?>
          <tr><td colspan="<?php echo count($g['dates'] ?? []) + 2; ?>" class="text-slate-400 text-center py-6">В классе нет активных учеников</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
