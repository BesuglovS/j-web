<?php
/**
 * Прогресс ученика на начало каждого урока класса.
 * Слоты: $data['timeline'] (результат StudentProgressService::lessonTimeline)
 * и $data['refreshUrl'] (ссылка «Обновить»).
 * Используется в карточке ученика (админ) и в карточке класса (тьютор).
 */
$timeline = $data['timeline'] ?? null;
$refreshUrl = (string)($data['refreshUrl'] ?? '#');
$className = is_array($timeline) ? (string)($timeline['className'] ?? '') : '';
?>
<div class="card mt-6 p-4">
  <div class="flex items-start justify-between gap-3 mb-3">
    <div>
      <h4 class="font-semibold">
        Прогресс на начало уроков<?php if ($className !== ''): ?> — <?php echo e($className); ?><?php endif; ?>
      </h4>
      <p class="text-xs text-slate-500 mt-0.5">
        По состоянию на время начала каждого урока класса.
        «Квизы» — сдано на 100% / с результатом, «Задачи» — решено / всего,
        «Баллы» — квизы на 100% + решённые задачи.
      </p>
    </div>
    <a href="<?php echo e($refreshUrl); ?>" class="text-sm text-blue-600 hover:underline whitespace-nowrap">Обновить</a>
  </div>

  <?php if ($timeline === null): ?>
    <p class="text-slate-400 text-sm">История прогресса недоступна: у ученика нет привязки к порталу или не выбран класс.</p>
  <?php else: ?>
    <?php if (!empty($timeline['errors'])): ?>
      <div class="mb-3 p-2 rounded bg-amber-50 border border-amber-200 text-amber-800 text-xs">
        <?php foreach ($timeline['errors'] as $err): ?>
          <div>⚠ <?php echo e($err); ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (empty($timeline['lessons'])): ?>
      <p class="text-slate-400 text-sm">В расписании класса пока нет уроков.</p>
    <?php else: ?>
      <div class="overflow-x-auto max-h-[32rem] overflow-y-auto rounded border border-slate-200">
        <table class="table text-sm">
          <thead class="sticky top-0 z-10 bg-slate-50">
            <tr>
              <th class="whitespace-nowrap">Урок</th>
              <th>Домашнее задание</th>
              <th class="text-center whitespace-nowrap" title="Квизов сдано на 100% / квизов с результатом">Квизы</th>
              <th class="text-center whitespace-nowrap" title="Решено задач / всего задач">Задачи</th>
              <th class="text-center whitespace-nowrap" title="Квизы на 100% + решённые задачи">Баллы</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($timeline['lessons'] as $l): ?>
              <?php
                $isFuture = (bool)($l['future'] ?? false);
                $hasResult = !$isFuture && (
                    (int)($l['quiz_attempted'] ?? 0) > 0
                    || (int)($l['tasks_solved'] ?? 0) > 0
                );
                $dateLabel = (string)($l['date'] ?? '');
                $ts = $dateLabel !== '' ? strtotime($dateLabel) : false;
                if ($ts !== false) {
                    $dateLabel = date('d.m.Y', $ts);
                }
              ?>
              <tr class="<?php echo $isFuture ? 'text-slate-400 bg-slate-50/60' : ''; ?>">
                <td class="whitespace-nowrap align-top">
                  <div class="font-medium"><?php echo e($dateLabel); ?><?php if ((string)($l['time'] ?? '') !== ''): ?> · <?php echo e((string)$l['time']); ?><?php endif; ?></div>
                  <div class="text-xs text-slate-500"><?php echo e((string)($l['subject'] ?? '')); ?></div>
                </td>
                <td class="align-top text-xs text-slate-600 max-w-md">
                  <?php if (trim((string)($l['homework'] ?? '')) !== ''): ?>
                    <?php echo e((string)$l['homework']); ?>
                  <?php else: ?>
                    <span class="text-slate-300">—</span>
                  <?php endif; ?>
                </td>
                <td class="text-center align-top whitespace-nowrap">
                  <?php if ($isFuture): ?>
                    <span class="text-slate-300" title="Урок ещё не начался">—</span>
                  <?php else: ?>
                    <span class="<?php echo $hasResult ? 'text-slate-800' : 'text-slate-400'; ?>">
                      <?php echo (int)($l['quiz_passed'] ?? 0); ?> / <?php echo (int)($l['quiz_attempted'] ?? 0); ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td class="text-center align-top whitespace-nowrap">
                  <?php if ($isFuture): ?>
                    <span class="text-slate-300" title="Урок ещё не начался">—</span>
                  <?php else: ?>
                    <span class="<?php echo $hasResult ? 'text-slate-800' : 'text-slate-400'; ?>">
                      <?php echo (int)($l['tasks_solved'] ?? 0); ?> / <?php echo (int)($l['tasks_total'] ?? 0); ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td class="text-center align-top whitespace-nowrap font-medium">
                  <?php if ($isFuture): ?>
                    <span class="text-slate-300" title="Урок ещё не начался">—</span>
                  <?php else: ?>
                    <?php echo (int)($l['points'] ?? 0); ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="text-slate-500 text-xs mt-2">Время уроков — местное, как в расписании журнала. Для будущих уроков прогресс не рассчитывается.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>
