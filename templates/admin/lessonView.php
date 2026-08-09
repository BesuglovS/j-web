<?php
$lesson = $lesson ?? [];
$students = $students ?? [];
$marksMap = $marksMap ?? [];
$remarksMap = $remarksMap ?? [];
$homeworks = $homeworks ?? [];
$submissionMap = $submissionMap ?? [];
$workTypes = ['lesson' => 'Урок', 'control' => 'Контроль', 'homework' => 'Домашнее', 'answer' => 'Ответ'];
?>
<div class="flex justify-between mb-4">
  <a href="/admin/lessons" class="btn-secondary">← Журнал</a>
  <a href="/admin/lessons/edit?id=<?php echo (int)$lesson['id']; ?>" class="btn-secondary">Изменить занятие</a>
</div>

<div class="card mb-6">
  <h3 class="text-lg font-semibold"><?php echo e($lesson['subject_name']); ?> — <?php echo e($lesson['class_name']); ?></h3>
  <div class="text-sm text-slate-600 mt-1">
    Дата: <b><?php echo e($lesson['date']); ?></b><?php echo $lesson['start_time'] ? e(' · '.$lesson['start_time']) : ''; ?>
    <?php if ($lesson['lesson_type']): ?> · Тип: <?php echo e($lesson['lesson_type']); ?><?php endif; ?>
  </div>
  <?php if ($lesson['topic']): ?><div class="mt-1 text-slate-700">Тема: <?php echo e($lesson['topic']); ?></div><?php endif; ?>
  <?php if ($lesson['note']): ?><div class="mt-1 text-slate-500 text-sm"><?php echo e($lesson['note']); ?></div><?php endif; ?>
</div>

<?php if (!$students): ?>
  <div class="card p-6 text-slate-500">В классе нет студентов — добавьте их, чтобы вести журнал.</div>
<?php else: ?>

<!-- ======= Оценки ======= -->
<div class="card mb-6 overflow-hidden">
  <h4 class="font-semibold p-4 border-b bg-slate-50">Оценки за занятие</h4>
  <form method="post" action="/admin/lessons/<?php echo (int)$lesson['id']; ?>/marks">
    <?php echo csrf_field(); ?>
    <div class="overflow-auto">
      <table class="table">
        <thead>
          <tr>
            <th class="sticky left-0 bg-white">Студент</th>
            <?php foreach ($workTypes as $wt => $label): ?>
              <th class="text-center"><?php echo e($label); ?><br><span class="text-[10px] font-normal text-slate-400">комментарий</span></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($students as $s): ?>
          <?php $name = trim($s['last_name'].' '.$s['first_name']); ?>
          <tr>
            <td class="sticky left-0 bg-white font-medium whitespace-nowrap"><?php echo e($name); ?></td>
            <?php foreach ($workTypes as $wt => $label): ?>
              <?php $m = $marksMap[$s['id'].'|'.$wt] ?? null; ?>
              <td class="text-center">
                <input type="number" min="1" max="5" name="marks[<?php echo (int)$s['id']; ?>][<?php echo e($wt); ?>]"
                       value="<?php echo $m ? e((string)$m['value']) : ''; ?>" placeholder="—"
                       class="w-12 text-center border rounded py-1">
                <input name="comments[<?php echo (int)$s['id']; ?>][<?php echo e($wt); ?>]"
                       value="<?php echo $m ? e((string)($m['comment'] ?? '')) : ''; ?>"
                       placeholder="..." class="w-full text-xs border rounded px-1 py-0.5">
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="p-3 border-t bg-slate-50 text-right">
      <button class="btn-primary">Сохранить оценки</button>
      <span class="text-xs text-slate-400 ml-2">Пустое поле — оценка не ставится/снимается.</span>
    </div>
  </form>
</div>

<!-- ======= Замечания ======= -->
<div class="card mb-6">
  <h4 class="font-semibold p-4 border-b bg-slate-50">Замечания к ученикам</h4>
  <form method="post" action="/admin/lessons/<?php echo (int)$lesson['id']; ?>/remarks">
    <?php echo csrf_field(); ?>
    <div class="divide-y">
      <?php foreach ($students as $s): ?>
        <?php $name = trim($s['last_name'].' '.$s['first_name']); ?>
        <div class="p-3 flex flex-wrap items-start gap-3">
          <div class="w-40 font-medium pt-1"><?php echo e($name); ?></div>
          <div class="flex-1 space-y-1">
            <?php foreach ($remarksMap[$s['id']] ?? [] as $r): ?>
              <div class="flex items-center gap-2 bg-amber-50 border border-amber-200 rounded px-2 py-1 text-sm">
                <span class="flex-1"><?php echo e($r['text']); ?></span>
                <label class="text-xs text-red-600 flex items-center gap-1">
                  <input type="checkbox" name="remove_remark[<?php echo (int)$s['id']; ?>]" value="<?php echo (int)$r['id']; ?>">
                  удалить
                </label>
              </div>
            <?php endforeach; ?>
            <input name="remarks[<?php echo (int)$s['id']; ?>][]" placeholder="Добавить замечание..." class="input">
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="p-3 border-t bg-slate-50 text-right"><button class="btn-primary">Сохранить замечания</button></div>
  </form>
</div>

<!-- ======= Домашние задания ======= -->
<div class="card mb-6">
  <h4 class="font-semibold p-4 border-b bg-slate-50">Домашние задания</h4>

  <div class="p-4">
    <form method="post" action="/admin/lessons/<?php echo (int)$lesson['id']; ?>/homework" class="grid sm:grid-cols-3 gap-3 items-end">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="homework_id" value="0">
      <div><label class="label">Название</label><input name="title" class="input" required></div>
      <div><label class="label">Срок</label><input name="due_date" type="date" class="input"></div>
      <div><label class="label">Описание</label><input name="description" class="input"></div>
      <div class="sm:col-span-3"><button class="btn-primary">Добавить задание</button></div>
    </form>
  </div>

  <?php foreach ($homeworks as $hw): ?>
    <div class="border-t p-4">
      <div class="flex justify-between items-start">
        <div>
          <b><?php echo e((string)($hw['title'] ?? 'Без названия')); ?></b>
          <?php if ($hw['due_date']): ?><span class="text-xs text-slate-500 ml-2">до <?php echo e($hw['due_date']); ?></span><?php endif; ?>
          <div class="text-sm text-slate-600 mt-1"><?php echo e((string)($hw['description'] ?? '')); ?></div>
        </div>
        <form method="post" action="/admin/homework/delete/<?php echo (int)$hw['id']; ?>" onsubmit="return confirm('Удалить задание?');">
          <?php echo csrf_field(); ?>
          <button class="btn-danger text-xs">Удалить</button>
        </form>
      </div>

      <form method="post" action="/admin/lessons/<?php echo (int)$lesson['id']; ?>/submissions" class="mt-3">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="homework_id" value="<?php echo (int)$hw['id']; ?>">
        <div class="overflow-auto">
          <table class="table">
            <thead><tr><th>Студент</th><th>Статус</th><th>Результат</th><th>Комментарий</th></tr></thead>
            <tbody>
            <?php foreach ($students as $s): ?>
              <?php $sub = $submissionMap[$hw['id']][$s['id']] ?? null; ?>
              <tr>
                <td class="whitespace-nowrap"><?php echo e(trim($s['last_name'].' '.$s['first_name'])); ?></td>
                <td>
                  <select name="submissions[<?php echo (int)$s['id']; ?>][status]" class="input">
                    <?php $cur = $sub['status'] ?? 'not_done'; ?>
                    <option value="done" <?php echo $cur==='done'?'selected':''; ?>>Выполнено</option>
                    <option value="partial" <?php echo $cur==='partial'?'selected':''; ?>>Частично</option>
                    <option value="not_done" <?php echo $cur==='not_done'?'selected':''; ?>>Не выполнено</option>
                  </select>
                </td>
                <td><input type="number" min="1" max="5" name="submissions[<?php echo (int)$s['id']; ?>][result_mark]"
                           value="<?php echo $sub ? e((string)$sub['result_mark']) : ''; ?>" class="w-14 text-center border rounded py-1"></td>
                <td><input name="submissions[<?php echo (int)$s['id']; ?>][comment]"
                           value="<?php echo $sub ? e((string)($sub['comment'] ?? '')) : ''; ?>" class="input"></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="text-right mt-2"><button class="btn-primary">Сохранить результаты</button></div>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (!$homeworks): ?><div class="p-4 text-slate-400 text-sm">Заданий пока нет.</div><?php endif; ?>
</div>

<?php endif; ?>