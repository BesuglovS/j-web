<?php
$row = $row ?? null;
?>
<div class="card max-w-2xl p-6">
  <h3 class="font-semibold mb-4"><?php echo $row ? 'Редактировать родителя' : 'Новый родитель'; ?></h3>
  <form method="post" action="/admin/parents/save" class="space-y-4">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="id" value="<?php echo (int)($row['id'] ?? 0); ?>">
    <div class="grid sm:grid-cols-3 gap-3">
      <div><label class="label">Фамилия</label><input name="last_name" class="input" value="<?php echo e($row['last_name'] ?? ''); ?>"></div>
      <div><label class="label">Имя</label><input name="first_name" class="input" value="<?php echo e($row['first_name'] ?? ''); ?>"></div>
      <div><label class="label">Отчество</label><input name="middle_name" class="input" value="<?php echo e($row['middle_name'] ?? ''); ?>"></div>
    </div>
    <hr>
    <div class="grid sm:grid-cols-2 gap-3">
      <div><label class="label">Логин (создать)</label><input name="new_login" class="input" value="<?php echo e($row['login'] ?? ''); ?>"></div>
      <div><label class="label">Пароль (создать)</label><input name="new_password" class="input" minlength="6"></div>
    </div>
    <button class="btn-primary">Сохранить</button>
    <a href="/admin/parents" class="btn-secondary">Отмена</a>
  </form>
</div>