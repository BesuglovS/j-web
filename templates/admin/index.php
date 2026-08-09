<?php $stats = $stats ?? []; ?>
<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
  <a href="/admin/classes" class="card p-4 hover:shadow-md">
    <div class="text-3xl font-bold text-slate-800"><?php echo (int)$stats['classes']; ?></div>
    <div class="text-slate-500">Классы</div>
  </a>
  <a href="/admin/subjects" class="card p-4 hover:shadow-md">
    <div class="text-3xl font-bold text-slate-800"><?php echo (int)$stats['subjects']; ?></div>
    <div class="text-slate-500">Предметы</div>
  </a>
  <a href="/admin/students" class="card p-4 hover:shadow-md">
    <div class="text-3xl font-bold text-slate-800"><?php echo (int)$stats['students']; ?></div>
    <div class="text-slate-500">Студенты</div>
  </a>
  <a href="/admin/parents" class="card p-4 hover:shadow-md">
    <div class="text-3xl font-bold text-slate-800"><?php echo (int)$stats['parents']; ?></div>
    <div class="text-slate-500">Родители</div>
  </a>
  <a href="/admin/lessons" class="card p-4 hover:shadow-md">
    <div class="text-3xl font-bold text-slate-800"><?php echo (int)$stats['lessons']; ?></div>
    <div class="text-slate-500">Занятия</div>
  </a>
</div>

<div class="mt-6 grid md:grid-cols-2 gap-6">
  <div class="card p-4">
    <h3 class="font-semibold mb-3">Быстрые действия</h3>
    <div class="flex flex-wrap gap-2">
      <a href="/admin/lessons/new" class="btn-primary">Новое занятие</a>
      <a href="/admin/parents/new" class="btn-secondary">Добавить родителя</a>
      <a href="/admin/import" class="btn-secondary">CSV-импорт</a>
    </div>
  </div>
  <div class="card p-4">
    <h3 class="font-semibold mb-3">Подсказка</h3>
    <p class="text-slate-600 text-sm">Журнал ведётся через раздел «Журнал»: создавайте занятия, выставляйте оценки, привязывайте домашние задания и замечания. Оценки автоматически усредняются в разделе «Оценки».</p>
  </div>
</div>