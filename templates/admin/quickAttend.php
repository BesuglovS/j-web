<?php
$dates = $dates ?? [];
$date = (string)($date ?? '');
$dayLessons = $dayLessons ?? [];
$lessonId = (int)($lessonId ?? 0);
$lesson = $lesson ?? null;
$students = $students ?? [];
$attendanceMap = $attendanceMap ?? [];
$marksMap = $marksMap ?? [];
$remarksMap = $remarksMap ?? [];
$workTypes = ['lesson' => 'Урок', 'control' => 'Контроль', 'homework' => 'Д/З', 'answer' => 'Ответ'];
?>
<div class="flex justify-between mb-4">
  <a href="/admin/lessons" class="btn-secondary">← Журнал</a>
  <a href="/admin/lessons/quick?date=<?php echo e($date); ?>" class="btn-secondary">Расписание на день</a>
</div>

<div class="card max-w-4xl p-6 mb-6">
  <h3 class="font-semibold mb-4">Быстрый ввод за занятие</h3>
  <form method="get" action="/admin/lessons/attend" class="flex flex-wrap items-end gap-4">
    <div>
      <label class="label">Дата</label>
      <select name="date" class="input" onchange="this.form.submit()">
        <?php foreach ($dates as $d): ?>
          <option value="<?php echo e($d); ?>" <?php echo $d === $date ? 'selected' : ''; ?>><?php echo e($d); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="min-w-72">
      <label class="label">Занятие</label>
      <select name="lesson_id" class="input" onchange="this.form.submit()">
        <?php if (!$dayLessons): ?>
          <option value="0">— нет занятий на эту дату —</option>
        <?php endif; ?>
        <?php foreach ($dayLessons as $l): ?>
          <option value="<?php echo (int)$l['id']; ?>" <?php echo (int)$l['id'] === $lessonId ? 'selected' : ''; ?>>
            <?php echo e(($l['start_time'] ?: '—') . ' · ' . $l['class_name'] . ' — ' . $l['subject_name'] . (($l['topic']) ? ' · ' . $l['topic'] : '')); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<?php if (!$lesson): ?>
  <div class="card max-w-4xl p-6 text-slate-500">Занятие не выбрано. Выберите дату и занятие из списков.</div>
<?php else: ?>
<div class="card max-w-4xl mb-6 overflow-hidden">
  <h4 class="font-semibold p-4 border-b bg-slate-50">
    <?php echo e($lesson['subject_name'] . ' — ' . $lesson['class_name']); ?>
    <span class="text-sm font-normal text-slate-500 ml-2"><?php echo e($lesson['date'] . ($lesson['start_time'] ? ' · ' . $lesson['start_time'] : '')); ?></span>
  </h4>

  <form method="post" action="/admin/lessons/attend/save">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="lesson_id" value="<?php echo (int)$lessonId; ?>">
    <div class="overflow-auto">
      <table class="table">
        <thead>
          <tr>
            <th class="sticky left-0 bg-white">Студент</th>
            <th class="w-36">Посещаемость</th>
            <?php foreach ($workTypes as $label): ?>
              <th class="text-center"><?php echo e($label); ?></th>
            <?php endforeach; ?>
            <th class="w-64">Замечание</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($students as $s): ?>
          <?php $sid = (int)$s['id']; $att = $attendanceMap[$sid] ?? null; ?>
          <tr>
            <td class="sticky left-0 bg-white font-medium whitespace-nowrap"><?php echo e(trim($s['last_name'].' '.$s['first_name'])); ?></td>
            <td>
              <select name="attendance[<?php echo $sid; ?>][status]" class="input py-1">
                <option value="" <?php echo !$att ? 'selected' : ''; ?>>— не отмечен —</option>
                <option value="present" <?php echo $att && $att['status']==='present' ? 'selected' : ''; ?>>Был</option>
                <option value="absent" <?php echo $att && $att['status']==='absent' ? 'selected' : ''; ?>>Отсутствовал</option>
                <option value="late" <?php echo $att && $att['status']==='late' ? 'selected' : ''; ?>>Опоздал</option>
              </select>
              <input name="attendance[<?php echo $sid; ?>][comment]" value="<?php echo $att ? e((string)($att['comment'] ?? '')) : ''; ?>" placeholder="коммент." class="mt-1 w-full text-xs border rounded px-1 py-0.5">
            </td>
            <?php foreach ($workTypes as $wt => $label): ?>
              <?php $m = $marksMap[$sid.'|'.$wt] ?? null; ?>
              <td class="text-center">
                <input type="number" min="1" max="5" name="marks[<?php echo $sid; ?>][<?php echo e($wt); ?>]"
                       value="<?php echo $m ? e((string)$m['value']) : ''; ?>" placeholder="—"
                       class="w-12 text-center border rounded py-1">
                <input name="comments[<?php echo $sid; ?>][<?php echo e($wt); ?>]"
                       value="<?php echo $m ? e((string)($m['comment'] ?? '')) : ''; ?>"
                       placeholder="..." class="w-full text-xs border rounded px-1 py-0.5">
              </td>
            <?php endforeach; ?>
            <td>
              <?php foreach ($remarksMap[$sid] ?? [] as $r): ?>
                <div class="flex items-center gap-2 bg-amber-50 border border-amber-200 rounded px-2 py-0.5 text-xs mb-1">
                  <span class="flex-1"><?php echo e($r['text']); ?></span>
                  <label class="text-red-600 flex items-center gap-1">
                    <input type="checkbox" name="remove_remark[<?php echo $sid; ?>][]" value="<?php echo (int)$r['id']; ?>">✕
                  </label>
                </div>
              <?php endforeach; ?>
              <input name="remarks[<?php echo $sid; ?>][]" placeholder="новое замечание..." class="w-full text-xs border rounded px-1 py-0.5">
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="p-3 border-t bg-slate-50 flex items-center justify-between">
      <span class="text-xs text-slate-400">Пустые поля — данные не меняются/снимаются (пустая посещаемость удаляет отметку).</span>
      <button class="btn-primary">Сохранить</button>
    </div>
  </form>
</div>
<?php endif; ?>
