<?php
$row = $row ?? null;
$classes = $classes ?? [];
$subjects = $subjects ?? [];
?>
<div class="card max-w-2xl p-6">
  <h3 class="font-semibold mb-4"><?php echo $row ? 'Редактировать занятие' : 'Новое занятие'; ?></h3>
  <form method="post" action="/admin/lessons/save" class="space-y-4">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="id" value="<?php echo (int)($row['id'] ?? 0); ?>">
    <div class="grid sm:grid-cols-3 gap-3">
      <div>
        <label class="label">Класс *</label>
        <select name="class_id" class="input" required>
          <option value="">—</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php echo (int)($row['class_id'] ?? 0) === (int)$c['id'] ? 'selected' : ''; ?>><?php echo e($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="label">Предмет *</label>
        <select name="subject_id" id="subjectSelect" class="input" required>
          <option value="">—</option>
          <?php foreach ($subjects as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>" data-class="<?php echo (int)$s['class_id']; ?>" <?php echo (int)($row['subject_id'] ?? 0) === (int)$s['id'] ? 'selected' : ''; ?>><?php echo e($s['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label class="label">Дата *</label><input name="date" type="date" class="input" required value="<?php echo e($row['date'] ?? today()); ?>"></div>
    </div>
    <div class="grid sm:grid-cols-2 gap-3">
      <div><label class="label">Время</label><input name="start_time" type="time" class="input" value="<?php echo e($row['start_time'] ?? ''); ?>"></div>
      <div><label class="label">Тип занятия</label><input name="lesson_type" class="input" placeholder="лекция/семинар/контрольная" value="<?php echo e($row['lesson_type'] ?? ''); ?>"></div>
    </div>
    <div><label class="label">Тема</label><input name="topic" class="input" value="<?php echo e($row['topic'] ?? ''); ?>"></div>
    <div><label class="label">Примечание</label><textarea name="note" rows="2" class="input"><?php echo e($row['note'] ?? ''); ?></textarea></div>
    <button class="btn-primary">Сохранить</button>
    <a href="/admin/lessons" class="btn-secondary">Отмена</a>
  </form>
</div>
<script>
(function () {
  var classSel = document.querySelector('select[name="class_id"]');
  var subjSel = document.getElementById('subjectSelect');
  if (!classSel || !subjSel) return;
  function filterSubjects() {
    var cid = parseInt(classSel.value, 10) || 0;
    var any = false;
    Array.prototype.forEach.call(subjSel.options, function (opt) {
      var matches = cid === 0 || parseInt(opt.getAttribute('data-class'), 10) === cid;
      opt.hidden = !matches;
      if (opt.value !== '' && matches && !opt.selected) any = true;
    });
    if (subjSel.selectedOptions.length && subjSel.selectedOptions[0] !== subjSel.options[0] && !subjSel.selectedOptions[0].hidden) return;
    subjSel.value = '';
    if (cid && subjSel.value === '' && any) Array.prototype.forEach.call(subjSel.options, function (o){ if (!o.hidden) { subjSel.value = o.value; return false; } });
  }
  classSel.addEventListener('change', filterSubjects);
  filterSubjects();
})();
</script>