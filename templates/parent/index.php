<?php $parent = $parent ?? []; $children = $children ?? []; ?>
<div class="card p-6">
  <h3 class="font-semibold mb-4">Мои дети</h3>
  <?php if (!$children): ?>
    <p class="text-slate-500">К вашей учётной записи не привязаны дети. Обратитесь к администратору.</p>
  <?php endif; ?>
  <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($children as $c): ?>
      <a href="/parent/child/<?php echo (int)$c['id']; ?>" class="card p-4 hover:shadow-md block">
        <div class="font-medium"><?php echo e(trim($c['last_name'].' '.$c['first_name'].' '.$c['middle_name'])); ?></div>
        <div class="text-sm text-slate-500">Класс: <?php echo e($c['class_name']); ?></div>
        <div class="mt-2 text-blue-600 text-sm">Просмотр →</div>
      </a>
    <?php endforeach; ?>
  </div>
</div>