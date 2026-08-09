<?php $templates = $templates ?? []; ?>
<div class="card p-6">
  <h3 class="font-semibold mb-4">Импорт из CSV</h3>
  <p class="text-sm text-slate-600 mb-4">Выберите тип импорта и загрузите CSV-файл (разделитель <code>;</code> или <code>,</code>). Первая строка — заголовок, пропускается. Родители могут сопровождаться колонками логин и пароль для создания учётной записи. Студенты импорту не подлежат: список ведётся в едином портале (auth.nayanovaacademy.ru) и синхронизируется в разделе «Студенты».</p>

  <form method="post" action="/admin/import" enctype="multipart/form-data" class="grid sm:grid-cols-3 gap-3 items-end">
    <?php echo csrf_field(); ?>
    <div>
      <label class="label">Тип импорта</label>
      <select name="kind" class="input" required>
        <option value="parents">Родители</option>
        <option value="links">Связи студент—родитель</option>
      </select>
    </div>
    <div><label class="label">CSV-файл</label><input type="file" name="csv_file" accept=".csv" class="input" required></div>
    <button class="btn-primary">Загрузить</button>
  </form>

  <a href="/admin/import/logs" class="btn-secondary mt-4">Журнал импорта</a>
</div>

<div class="card mt-6 p-6">
  <h3 class="font-semibold mb-3">Шаблоны столбцов</h3>
  <?php foreach ($templates as $fname => $content): ?>
    <details class="mb-3">
      <summary class="cursor-pointer text-slate-700 font-medium"><?php echo e($fname); ?></summary>
      <pre class="bg-slate-50 border rounded p-3 text-xs overflow-auto mt-1"><?php echo e($content); ?></pre>
    </details>
  <?php endforeach; ?>
</div>