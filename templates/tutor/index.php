<?php $classes = $classes ?? []; ?>
<div class="card p-6">
  <h3 class="font-semibold mb-4">Мои классы</h3>
  <?php if (!$classes): ?>
    <p class="text-slate-500">К вашей учётной записи не привязаны классы. Обратитесь к администратору.</p>
  <?php endif; ?>
  <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($classes as $c): ?>
      <a href="/tutor/grades?class_id=<?php echo (int)$c['id']; ?>" class="card p-4 hover:shadow-md block">
        <div class="font-medium text-lg"><?php echo e($c['name']); ?></div>
        <div class="text-sm text-slate-500">Студентов: <?php echo (int)$c['cnt']; ?></div>
        <div class="mt-2 text-blue-600 text-sm">Успеваемость →</div>
      </a>
    <?php endforeach; ?>
  </div>
</div>
