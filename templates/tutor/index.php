<?php $classes = $data['classes'] ?? []; ?>
<div class="card p-6">
  <h3 class="font-semibold mb-4">Мои классы</h3>
  <?php if (!$classes): ?>
    <p class="text-slate-500">К вашей учётной записи не привязаны классы. Обратитесь к администратору.</p>
  <?php endif; ?>
  <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($classes as $c): ?>
      <div class="card p-4 hover:shadow-md">
        <div class="font-medium text-lg"><?php echo e($c['name']); ?></div>
        <div class="text-sm text-slate-500">Студентов: <?php echo (int)$c['cnt']; ?></div>
        <div class="mt-2 flex flex-wrap gap-3 text-sm">
          <a href="/tutor/grades?class_id=<?php echo (int)$c['id']; ?>" class="text-blue-600 hover:underline">Успеваемость →</a>
          <a href="/tutor/python?class_id=<?php echo (int)$c['id']; ?>" class="text-indigo-600 hover:underline">Python-курс →</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
