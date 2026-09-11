<?php
$student = $student ?? [];
$rows = $rows ?? [];
$statusLabels = ['done'=>'Выполнено','partial'=>'Частично','not_done'=>'Не выполнено'];
?>
<div class="card overflow-hidden">
  <table class="table">
    <thead><tr><th>Предмет</th><th>Задание</th><th>Срок</th><th>Мой статус</th><th>Результат</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $h): ?>
      <tr>
        <td><?php echo e($h['subject']); ?><div class="text-xs text-slate-400">урок <?php echo e($h['lesson_date']); ?></div></td>
        <td>
          <b><?php echo e($h['title'] ?: 'Задание'); ?></b>
          <?php if ($h['description']): ?><div class="text-sm text-slate-600"><?php echo e($h['description']); ?></div><?php endif; ?>
        </td>
        <td><?php echo e((string)($h['due_date'] ?? '')); ?></td>
        <td>
          <?php $cur = $h['my_status'] ?? null; ?>
          <span class="<?php echo $cur==='done' ? 'text-emerald-700' : ($cur==='partial' ? 'text-amber-700' : 'text-slate-400'); ?>">
            <?php echo $cur ? e($statusLabels[$cur]) : '—'; ?>
          </span>
          <?php if ($h['my_comment']): ?><div class="text-xs text-slate-500"><?php echo e($h['my_comment']); ?></div><?php endif; ?>
        </td>
        <td><?php echo $h['my_mark'] ? '<b>'.(int)$h['my_mark'].'</b>' : '—'; ?></td>
        <td>
          <details>
            <summary class="text-sm text-blue-600 cursor-pointer">Отметить</summary>
            <form method="post" action="/my/homeworks/<?php echo (int)$h['id']; ?>/submit" class="mt-2 flex flex-col gap-2">
              <?php echo csrf_field(); ?>
              <select name="status" class="input">
                <option value="done" <?php echo $cur==='done'?'selected':''; ?>>Выполнено</option>
                <option value="partial" <?php echo $cur==='partial'?'selected':''; ?>>Частично</option>
                <option value="not_done" <?php echo $cur==='not_done'?'selected':''; ?>>Не выполнено</option>
              </select>
              <input name="comment" class="input" placeholder="Комментарий (необязательно)" value="<?php echo e($h['my_comment'] ?? ''); ?>">
              <button class="btn-secondary">Сохранить</button>
              <?php if ($cur): ?>
                <button type="submit" name="status" value="reset"
                        class="text-sm text-red-600 hover:underline mt-1" <?php echo $cur ? '' : 'disabled'; ?>>
                  Убрать статус
                </button>
              <?php endif; ?>
            </form>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="text-slate-400 text-center py-6">Заданий пока нет</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>