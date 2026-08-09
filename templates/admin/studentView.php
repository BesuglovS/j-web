<?php
$student = $student ?? [];
$parents = $parents ?? [];
$grades = $grades ?? [];
$remarks = $remarks ?? [];
$allParents = $allParents ?? [];
$allGrades = $allGrades ?? [];
$name = trim($student['last_name'].' '.$student['first_name'].' '.$student['middle_name']);
?>
<div class="flex justify-end mb-4"><a href="/admin/students" class="btn-secondary">← К списку</a></div>
<div class="card p-6">
  <h3 class="text-lg font-semibold"><?php echo e($name); ?></h3>
  <div class="flex flex-wrap gap-6 mt-2 text-sm text-slate-600">
    <span>Класс: <b><?php echo e($student['class_name'] ?? ''); ?></b></span>
    <span>Логин: <b><?php echo e((string)($student['login'] ?? '—')); ?></b></span>
  </div>
</div>

<div class="grid lg:grid-cols-2 gap-6 mt-6">
  <div class="card p-6">
    <h4 class="font-semibold mb-3">Родители</h4>
    <form method="post" action="/admin/links/save" class="flex gap-2 items-center mb-3">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="student_id" value="<?php echo (int)$student['id']; ?>">
      <select name="parent_id" class="input" required>
        <option value="">— выберите родителя —</option>
        <?php foreach ($allParents as $p): ?>
          <option value="<?php echo (int)$p['id']; ?>"><?php echo e(trim($p['last_name'].' '.$p['first_name'])); ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn-primary">Связать</button>
    </form>
    <?php foreach ($parents as $p): ?>
      <div class="flex items-center justify-between py-1 border-b">
        <span><?php echo e(trim($p['last_name'].' '.$p['first_name'].' '.$p['middle_name'])); ?></span>
        <form method="post" action="/admin/links/save" class="inline">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="student_id" value="<?php echo (int)$student['id']; ?>">
          <input type="hidden" name="detach" value="<?php echo (int)$p['id']; ?>">
          <button class="text-red-600 text-sm">Убрать</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card p-6">
    <h4 class="font-semibold mb-3">Средние оценки по предметам</h4>
    <?php foreach ($allGrades as $g): ?>
      <div class="flex justify-between py-1 border-b">
        <span><?php echo e($g['subject']); ?></span>
        <span><b><?php echo e((string)$g['avg']); ?></b> (<?php echo (int)$g['cnt']; ?>)</span>
      </div>
    <?php endforeach; ?>
    <?php if (!$allGrades): ?><p class="text-slate-400">Оценок пока нет.</p><?php endif; ?>
  </div>
</div>

<div class="grid lg:grid-cols-2 gap-6 mt-6">
  <div class="card p-6">
    <h4 class="font-semibold mb-3">Оценки</h4>
    <div class="overflow-auto">
      <table class="table">
        <thead><tr><th>Дата</th><th>Предмет</th><th>Вид</th><th>Оценка</th></tr></thead>
        <tbody>
        <?php foreach ($grades as $g): ?>
          <tr>
            <td><?php echo e($g['date']); ?></td>
            <td><?php echo e($g['subject']); ?></td>
            <td><?php echo e($g['work_type']); ?></td>
            <td class="font-semibold"><?php echo (int)$g['value']; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$grades): ?><tr><td colspan="4" class="text-slate-400 text-center py-4">Нет оценок</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card p-6">
    <h4 class="font-semibold mb-3">Замечания</h4>
    <?php foreach ($remarks as $r): ?>
      <div class="py-2 border-b">
        <div class="text-sm text-slate-500"><?php echo e($r['date']); ?> · <?php echo e($r['subject']); ?></div>
        <div><?php echo e($r['text']); ?></div>
      </div>
    <?php endforeach; ?>
    <?php if (!$remarks): ?><p class="text-slate-400">Замечаний нет.</p><?php endif; ?>
  </div>
</div>