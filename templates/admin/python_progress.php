<?php $classes = $classes ?? []; ?>
<?php $selected = $selected ?? null; ?>
<?php $summary = $summary ?? null; ?>
<?php $maxLessons = (int)($maxLessons ?? 50); ?>
<?php $lessonFrom = (int)($lessonFrom ?? 1); ?>
<?php $lessonTo = (int)($lessonTo ?? 0); ?>
<div class="mb-4">
  <form method="get" action="/admin/python-progress" class="flex flex-wrap items-end gap-3 bg-white border border-slate-200 rounded-lg p-3">
    <div>
      <label class="block text-xs text-slate-500 mb-1">Класс</label>
      <select name="class_id" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
        <option value="0">— выберите класс —</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php echo $selected && (int)$selected['id'] === (int)$c['id'] ? 'selected' : ''; ?>>
            <?php echo e($c['name']); ?><?php echo isset($c['grade']) && $c['grade'] ? ' (' . e((string)$c['grade']) . ' класс)' : ''; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-xs text-slate-500 mb-1">С урока</label>
      <select name="lesson_from" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
        <?php for ($n = 1; $n <= $maxLessons; $n++): ?>
          <option value="<?php echo $n; ?>" <?php echo $n === $lessonFrom ? 'selected' : ''; ?>>Урок <?php echo $n; ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div>
      <label class="block text-xs text-slate-500 mb-1">По урок</label>
      <select name="lesson_to" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
        <?php for ($n = 1; $n <= $maxLessons; $n++): ?>
          <option value="<?php echo $n; ?>" <?php echo $n === $lessonTo ? 'selected' : ''; ?>>Урок <?php echo $n; ?><?php echo $n === (int)($summary['max_lesson'] ?? 0) ? ' (последний с результатами)' : ''; ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <button class="btn-primary">Показать</button>
    <?php if ($selected): ?>
      <button class="btn-secondary" name="refresh" value="1" title="Заново запросить данные у python-web и contest-web, минуя кэш">Обновить</button>
    <?php endif; ?>
  </form>
  <p class="mt-2 text-xs text-slate-500">
    Данные берутся с python.nayanovaacademy.ru (квизы уроков) и contest.nayanovaacademy.ru (задачи урока).
    Ответы кэшируются на 5 минут; кнопка «Обновить» запрашивает свежие данные, минуя кэш.
  </p>
</div>

<?php if ($summary !== null && $selected): ?>
  <?php
    $students = $summary['students'];
    $maxLesson = (int)$summary['max_lesson'];
    $lessonContests = config()['python_lesson_contests'];
    $showFinal = (bool)$summary['has_final'];
    $pyUrl = config()['python_site_url'];
  ?>
  <?php if ($summary['errors']): ?>
    <div class="mb-4 p-3 rounded bg-amber-50 border border-amber-200 text-amber-800 text-sm">
      <?php foreach ($summary['errors'] as $err): ?>
        <div>⚠ <?php echo e($err); ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$students): ?>
    <div class="card p-6 text-center text-slate-400">В классе нет участников.</div>
  <?php elseif ($maxLesson === 0 && !$showFinal && $lessonTo < 1): ?>
    <div class="card p-6 text-center text-slate-400">Ученики класса ещё не приступали к python-курсу.</div>
  <?php else: ?>
    <?php
      // Диапазон: если пользователь не задал lesson_to явно, показываем до последнего с данными
      $renderFrom = max(1, $lessonFrom);
      $renderTo = max($renderFrom, $lessonTo > 0 ? $lessonTo : $maxLesson);
      // Подготовка: агрегаты по урокам (в выбранном диапазоне)
      $lessonStats = [];
      for ($n = $renderFrom; $n <= $renderTo; $n++) {
          $lessonStats[$n] = ['quiz_sum' => 0, 'quiz_cnt' => 0, 'solved' => 0, 'total' => 0, 'blocks' => 0];
      }
      foreach ($students as $s) {
          foreach ($s['lessons'] as $num => $l) {
              if ($num >= $renderFrom && $num <= $renderTo && isset($lessonStats[$num])) {
                  if ($l['quiz'] !== null) {
                      $lessonStats[$num]['quiz_sum'] += (int)$l['quiz'];
                      $lessonStats[$num]['quiz_cnt']++;
                  }
                  if ($l['total'] !== null && $l['total'] > 0) {
                      $lessonStats[$num]['total'] = max($lessonStats[$num]['total'], (int)$l['total']);
                      $lessonStats[$num]['solved'] += (int)$l['solved'];
                  }
                  if ($l['quiz'] !== null || $l['solved'] > 0) {
                      $lessonStats[$num]['blocks']++;
                  }
              }
          }
      }
      // занятые уроки: последовательность от 1 до maxLesson (пропуски показываем серым)
      $finalScores = [];
      foreach ($students as $s) {
          if (isset($s['lessons'][-1]) && $s['lessons'][-1]['quiz'] !== null) {
              $finalScores[$s['id']] = (int)$s['lessons'][-1]['quiz'];
          }
      }
    ?>
    <div class="card overflow-x-auto max-w-full overscroll-x-contain">
      <table class="table text-sm min-w-max">
        <thead>
          <tr>
            <th class="sticky left-0 top-0 z-30 bg-slate-50 border-r border-slate-200 shadow-[1px_0_0_rgba(0,0,0,0.03)]">Ученик</th>
            <th class="sticky top-0 z-20 bg-slate-50 text-center border-l border-slate-200" title="Итоговый тест">Итог</th>
            <?php for ($n = $renderFrom; $n <= $renderTo; $n++): ?>
              <th class="sticky top-0 z-20 bg-slate-50 text-center whitespace-nowrap border-l border-slate-200" title="Урок <?php echo $n; ?>">
                <?php if (isset($lessonContests[$n])): ?>
                  <span class="text-indigo-600" title="Урок <?php echo $n; ?> + решённые задачи (контест <?php echo (int)$lessonContests[$n]; ?>)"><?php echo $n; ?>*</span>
                <?php else: ?>
                  <?php echo $n; ?>
                <?php endif; ?>
              </th>
            <?php endfor; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $s): ?>
            <tr class="hover:bg-slate-50 group">
              <td class="sticky left-0 z-10 bg-white border-r border-slate-200 group-hover:bg-slate-50 font-medium whitespace-nowrap"><?php echo e($s['name']); ?></td>
              <td class="text-center border-l border-slate-200">
                <?php if (isset($finalScores[$s['id']])): ?>
                  <?php $fs = $finalScores[$s['id']]; ?>
                  <span class="inline-block px-1.5 rounded <?php echo $fs >= 90 ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'; ?>"><?php echo $fs; ?>%</span>
                <?php else: ?>
                  <span class="text-slate-300">—</span>
                <?php endif; ?>
              </td>
              <?php for ($n = $renderFrom; $n <= $renderTo; $n++): ?>
                <?php
                  $l = $s['lessons'][$n] ?? null;
                  if ($l === null):
                ?>
                  <td class="text-center text-slate-300 border-l border-slate-200">·</td>
                <?php else: ?>
                  <?php
                    $quiz = $l['quiz'];
                    $solved = (int)$l['solved'];
                    $total = $l['total'] === null ? null : (int)$l['total'];
                    $taskCount = ($total !== null && $total > 0) ? $total : null;
                    // фон: зелёный — квиз 100% и (нет задач или все решены);
                    // жёлтый — были попытки, но не всё закрыто; белый — попыток не было
                    $attempted = ($quiz !== null) || ($taskCount !== null && $solved > 0);
                    $quizDone = ($quiz !== null && $quiz >= 100);
                    $allSolved = ($taskCount !== null && $solved >= $taskCount);
                    if ($quizDone && ($taskCount === null || $allSolved)) {
                        $cellBg = 'bg-emerald-100';
                    } elseif ($attempted) {
                        $cellBg = 'bg-amber-100';
                    } else {
                        $cellBg = '';
                    }
                  ?>
                  <td class="text-center whitespace-nowrap border-l border-slate-200<?php echo $cellBg !== '' ? ' ' . $cellBg : ''; ?>">
                    <?php
                      if ($quiz !== null) {
                          echo '<span class="' . ($quiz >= 80 ? 'text-emerald-700' : ($quiz >= 50 ? 'text-amber-700' : 'text-red-700')) . ' font-medium">' . (string)$quiz . '%</span>';
                      }
                      if ($taskCount !== null) {
                          if ($quiz !== null) {
                              echo ' <span class="text-slate-400">·</span> ';
                          }
                          echo '<span class="' . ($solved >= $taskCount ? 'text-emerald-700' : 'text-red-700') . ' font-medium">' . (string)$solved . '/' . (string)$taskCount . '</span>';
                      }
                      if ($quiz === null && $taskCount === null) {
                          echo '<span class="text-slate-300">—</span>';
                      }
                    ?>
                  </td>
                <?php endif; ?>
              <?php endfor; ?>
            </tr>
          <?php endforeach; ?>
          <?php // строка итогов по классу ?>
          <tr class="border-t-2 border-slate-300 bg-slate-50 font-medium">
            <td class="sticky left-0 z-10 bg-slate-50 border-r border-slate-200 border-l border-slate-200">Класс</td>
            <td class="text-center text-slate-400 border-l border-slate-200"><?php echo count($finalScores) ? (string)count($finalScores) : '—'; ?></td>
            <?php for ($n = $renderFrom; $n <= $renderTo; $n++): ?>
              <?php
                $ls = $lessonStats[$n];
                $fragments = [];
                if ($ls['quiz_cnt'] > 0) {
                    $fragments[] = 'ср. ' . (string)round($ls['quiz_sum'] / $ls['quiz_cnt']) . '%';
                }
                if ($ls['total'] > 0) {
                    $fragments[] = (string)$ls['solved'] . '/' . (string)((int)$ls['blocks'] * $ls['total'] ?: 0) . ' задач';
                }
              ?>
              <td class="text-center border-l border-slate-200">
                <?php if ($ls['blocks'] > 0): ?>
                  <span class="text-slate-600 text-xs"><?php echo e(implode(' · ', $fragments ?: ['—'])); ?></span>
                <?php else: ?>
                  <span class="text-slate-300">·</span>
                <?php endif; ?>
              </td>
            <?php endfor; ?>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="mt-3 text-xs text-slate-500 space-y-1">
      <p>Формат ячейки: <b>квиз%</b> · <b>решено/всего</b> задач урока. Звёздочка после номера урока — к уроку привязаны задачи-контесты.</p>
      <p>Фон ячейки: <span class="inline-block px-1.5 rounded bg-emerald-100 text-emerald-800 font-medium">зелёный</span> — квиз на 100% и (нет задач или все задачи решены);
        <span class="inline-block px-1.5 rounded bg-amber-100 text-amber-800 font-medium">жёлтый</span> — квиз не на 100% и/или задачи решены не полностью (были попытки);
        белый — квиз и задачи не пытались решать.</p>
      <p>«·» — данные по уроку отсутствуют (в т.ч. уроки вне диапазона, где ученики ещё ничего не решали).</p>
      <p>Диапазон уроков выбирается в фильтре выше; по умолчанию — с первого по последний урок с результатами в классе.</p>
      <p><a class="text-indigo-600 underline" href="<?php echo e($pyUrl); ?>" target="_blank">Открыть python-курс</a></p>
    </div>
  <?php endif; ?>
<?php elseif ($selected): ?>
  <div class="card p-6 text-center text-slate-400">Нет данных от внешних сервисов.</div>
<?php endif; ?>
