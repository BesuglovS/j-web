<?php
$date = (string)($date ?? '');
$subjects = $subjects ?? [];
$existing = $existing ?? [];
$lessonTimes = AdminController::LESSON_TIMES;
?>
<div class="card max-w-3xl p-6">
  <h3 class="font-semibold mb-4">Расписание на день — быстрый ввод</h3>

  <form method="get" action="/admin/lessons/quick" class="flex flex-wrap items-end gap-3 mb-5">
    <div>
      <label class="label">Дата</label>
      <input type="date" name="date" class="input" value="<?php echo e($date); ?>" onchange="this.form.submit()">
    </div>
  </form>

  <form method="post" action="/admin/lessons/quick/save">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="date" value="<?php echo e($date); ?>">
    <table class="table">
      <thead>
        <tr><th class="w-20">Урок</th><th class="w-64">Класс — Предмет</th><th>Тема</th></tr>
      </thead>
      <tbody>
      <?php foreach ($lessonTimes as $n => $time): ?>
        <?php $row = $existing[$time] ?? null; ?>
        <tr>
          <td class="whitespace-nowrap">
            <span class="font-medium"><?php echo $n; ?> урок</span>
            <span class="block text-xs text-slate-400"><?php echo e($time); ?></span>
            <input type="hidden" name="lesson_<?php echo $n; ?>" value="<?php echo (int)($row['id'] ?? 0); ?>">
          </td>
          <td>
            <select name="subject_<?php echo $n; ?>" class="input">
              <option value="">— нет —</option>
              <?php foreach ($subjects as $s): ?>
                <option value="<?php echo (int)$s['id']; ?>" <?php echo $row && (int)$row['subject_id'] === (int)$s['id'] ? 'selected' : ''; ?>><?php echo e($s['class_name'] . ' — ' . $s['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <input name="topic_<?php echo $n; ?>" class="input" value="<?php echo e((string)($row['topic'] ?? '')); ?>">
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="mt-4 flex items-center gap-2">
      <button class="btn-primary">Сохранить расписание</button>
      <a href="/admin/lessons" class="btn-secondary">К журналу</a>
    </div>
    <p class="mt-2 text-xs text-slate-500">Пустые слоты без оценок и ДЗ удаляются при сохранении.</p>
  </form>
</div>