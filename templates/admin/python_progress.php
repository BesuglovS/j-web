<?php $classes = $data['classes'] ?? []; ?>
<?php $selected = $data['selected'] ?? null; ?>
<?php $summary = $data['summary'] ?? null; ?>
<?php $maxLessons = (int)($data['maxLessons'] ?? 50); ?>
<?php $finalN = (int)($data['finalN'] ?? $maxLessons + 1); ?>
<?php $lessonFrom = (int)($data['lessonFrom'] ?? 1); ?>
<?php $lessonTo = (int)($data['lessonTo'] ?? 0); ?>
<?php $quizFrom = (int)($data['quizFrom'] ?? 1); ?>
<?php $quizTo = (int)($data['quizTo'] ?? $maxLessons); ?>
<?php $taskFrom = (int)($data['taskFrom'] ?? 1); ?>
<?php $taskTo = (int)($data['taskTo'] ?? $maxLessons); ?>
<div class="mb-4">
  <form method="get" action="<?php echo e($data['formAction'] ?? '/admin/python-progress'); ?>" class="flex flex-wrap items-end gap-3 bg-white border border-slate-200 rounded-lg p-3">
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
        <option value="<?php echo $finalN; ?>" <?php echo $lessonFrom === $finalN ? 'selected' : ''; ?>>Итог</option>
      </select>
    </div>
    <div>
      <label class="block text-xs text-slate-500 mb-1">По урок</label>
      <select name="lesson_to" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
        <?php for ($n = 1; $n <= $maxLessons; $n++): ?>
          <option value="<?php echo $n; ?>" <?php echo $n === $lessonTo ? 'selected' : ''; ?>>Урок <?php echo $n; ?><?php echo $n === (int)($summary['max_lesson'] ?? 0) ? ' (последний с результатами)' : ''; ?></option>
        <?php endfor; ?>
        <option value="<?php echo $finalN; ?>" <?php echo $lessonTo === $finalN ? 'selected' : ''; ?>>Итог</option>
      </select>
    </div>
    <div class="flex flex-wrap items-end gap-3 border-l border-slate-200 pl-3">
      <div>
        <label class="block text-xs text-slate-500 mb-1" title="Баллы квизов: уроки, квиз которых сдан на 100%">Баллы квизов: с урока</label>
        <select name="quiz_from" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
          <?php for ($n = 1; $n <= $maxLessons; $n++): ?>
            <option value="<?php echo $n; ?>" <?php echo $n === $quizFrom ? 'selected' : ''; ?>>Урок <?php echo $n; ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs text-slate-500 mb-1">Баллы квизов: по урок</label>
        <select name="quiz_to" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
          <?php for ($n = 1; $n <= $maxLessons; $n++): ?>
            <option value="<?php echo $n; ?>" <?php echo $n === $quizTo ? 'selected' : ''; ?>>Урок <?php echo $n; ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs text-slate-500 mb-1" title="Баллы задач: сумма решённых задач по контестам уроков">Баллы задач: с урока</label>
        <select name="task_from" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
          <?php for ($n = 1; $n <= $maxLessons; $n++): ?>
            <option value="<?php echo $n; ?>" <?php echo $n === $taskFrom ? 'selected' : ''; ?>>Урок <?php echo $n; ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs text-slate-500 mb-1">Баллы задач: по урок</label>
        <select name="task_to" class="border border-slate-300 rounded px-2 py-1.5 text-sm">
          <?php for ($n = 1; $n <= $maxLessons; $n++): ?>
            <option value="<?php echo $n; ?>" <?php echo $n === $taskTo ? 'selected' : ''; ?>>Урок <?php echo $n; ?></option>
          <?php endfor; ?>
        </select>
      </div>
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
          if ($n === $finalN) continue; // итоговый тест — без агреатов по классу
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

      // Максимум баллов: каждый урок в диапазоне квизов даёт максимум 1 балл,
      // задачи — суммарное число задач (total) по урокам диапазона задач.
      $maxQuiz = max(0, $quizTo - $quizFrom + 1);
      $taskTotals = [];
      foreach ($students as $s) {
          foreach ($s['lessons'] as $num => $l) {
              $num = (int)$num;
              if ($num >= $taskFrom && $num <= $taskTo && $l['total'] !== null && (int)$l['total'] > 0) {
                  $taskTotals[$num] = max($taskTotals[$num] ?? 0, (int)$l['total']);
              }
          }
      }
      $maxTask = array_sum($taskTotals);
      $maxPoints = $maxQuiz + $maxTask;
      // Оценка: < половины — 2; вторая половина делится на 3 равные части — 3/4/5.
      $gradeOf = function (int $points) use ($maxPoints): ?int {
          if ($maxPoints <= 0) return null;
          $half = $maxPoints / 2;
          $third = $half / 3;
          if ($points < $half) return 2;
          if ($points < $half + $third) return 3;
          if ($points < $half + 2 * $third) return 4;
          return 5;
      };
      $gradeColor = function (?int $g): string {
          return match ($g) {
              5 => 'bg-emerald-100 text-emerald-700',
              4 => 'bg-indigo-100 text-indigo-700',
              3 => 'bg-amber-100 text-amber-700',
              2 => 'bg-red-100 text-red-700',
              default => 'bg-slate-100 text-slate-500',
          };
      };
      $studentPoints = [];
      foreach ($students as $i => $s) {
          $quizPoints = 0;
          for ($n = $quizFrom; $n <= $quizTo; $n++) {
              if (isset($s['lessons'][$n]) && $s['lessons'][$n]['quiz'] !== null && (int)$s['lessons'][$n]['quiz'] >= 100) {
                  $quizPoints++;
              }
          }
          $taskPoints = 0;
          for ($n = $taskFrom; $n <= $taskTo; $n++) {
              if (isset($s['lessons'][$n])) {
                  $taskPoints += (int)$s['lessons'][$n]['solved'];
              }
          }
          $points = $quizPoints + $taskPoints;
          $studentPoints[$i] = ['quiz' => $quizPoints, 'task' => $taskPoints, 'points' => $points, 'grade' => $gradeOf($points)];
      }
    ?>
    <div class="pp-results">
      <div class="mb-2 flex flex-wrap items-center justify-end gap-x-5 gap-y-2">
        <label class="inline-flex items-center gap-2 cursor-pointer select-none text-sm text-slate-600" title="Растянуть таблицу на всю ширину экрана">
          <input type="checkbox" id="pp-full-width" class="sr-only peer" checked>
          <span class="relative inline-flex h-5 w-9 items-center rounded-full bg-slate-300 transition-colors peer-checked:bg-blue-600
                       after:absolute after:left-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:shadow after:transition-transform
                       peer-checked:after:translate-x-4"></span>
          <span>100% ширины</span>
        </label>
        <label class="inline-flex items-center gap-2 cursor-pointer select-none text-sm text-slate-600" title="Показывать столбцы «Баллы» и «Оценка»">
          <input type="checkbox" id="pp-show-points" class="sr-only peer" checked>
          <span class="relative inline-flex h-5 w-9 items-center rounded-full bg-slate-300 transition-colors peer-checked:bg-blue-600
                       after:absolute after:left-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:shadow after:transition-transform
                       peer-checked:after:translate-x-4"></span>
          <span>Баллы и оценка</span>
        </label>
        <label class="inline-flex items-center gap-2 cursor-pointer select-none text-sm text-slate-600" title="Дополнительно показывать отдельные столбцы баллов по квизам и задачам (общий «Баллы» сохраняется)">
          <input type="checkbox" id="pp-split-points" class="sr-only peer">
          <span class="relative inline-flex h-5 w-9 items-center rounded-full bg-slate-300 transition-colors peer-checked:bg-indigo-600
                       after:absolute after:left-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:shadow after:transition-transform
                       peer-checked:after:translate-x-4"></span>
          <span>Баллы квизов/задач отдельно</span>
        </label>
      </div>
    <div class="pp-table-wrap card overflow-x-auto overscroll-x-contain">
      <table class="table text-sm min-w-max">
        <thead>
          <tr>
            <th class="sticky left-0 top-0 z-30 bg-slate-50 border-r border-slate-200 shadow-[1px_0_0_rgba(0,0,0,0.03)]">Ученик</th>
            <?php for ($n = $renderFrom; $n <= $renderTo; $n++): ?>
              <?php if ($n === $finalN): ?>
                <th class="sticky top-0 z-20 bg-slate-50 text-center whitespace-nowrap border-l border-slate-200" title="Итоговый тест">Итог</th>
                <?php continue; ?>
              <?php endif; ?>
              <th class="sticky top-0 z-20 bg-slate-50 text-center whitespace-nowrap border-l border-slate-200" title="Урок <?php echo $n; ?>">
                <?php if (isset($lessonContests[$n])): ?>
                  <span class="text-indigo-600" title="Урок <?php echo $n; ?> + решённые задачи (контест <?php echo (int)$lessonContests[$n]; ?>)"><?php echo $n; ?>*</span>
                <?php else: ?>
                  <?php echo $n; ?>
                <?php endif; ?>
              </th>
            <?php endfor; ?>
            <th class="pp-col-points pp-col-total sticky top-0 z-20 bg-slate-50 text-center whitespace-nowrap border-l border-slate-200" title="Баллы: квизы на 100% + решённые задачи (диапазоны задаются в фильтре)">Баллы</th>
            <th class="pp-col-points pp-col-quiz sticky top-0 z-20 bg-slate-50 text-center whitespace-nowrap border-l border-slate-200" title="Баллы за квизы, сданные на 100% (уроки диапазона квизов)">Баллы квизов</th>
            <th class="pp-col-points pp-col-task sticky top-0 z-20 bg-slate-50 text-center whitespace-nowrap border-l border-slate-200" title="Баллы за решённые задачи (уроки диапазона задач)">Баллы задач</th>
            <th class="pp-col-grade sticky top-0 z-20 bg-slate-50 text-center whitespace-nowrap border-l border-slate-200" title="Оценка из баллов">Оценка</th>
          </tr>
        </thead>
        <tbody>
            <?php foreach ($students as $i => $s): ?>
              <tr class="hover:bg-slate-50 group">
              <td class="sticky left-0 z-10 bg-white border-r border-slate-200 group-hover:bg-slate-50 font-medium whitespace-nowrap"><?php echo e($s['name']); ?></td>
              <?php for ($n = $renderFrom; $n <= $renderTo; $n++): ?>
                <?php if ($n === $finalN): ?>
                  <?php $quiz = isset($s['lessons'][-1]) && $s['lessons'][-1]['quiz'] !== null ? (int)$s['lessons'][-1]['quiz'] : null; ?>
                  <td class="text-center whitespace-nowrap border-l border-slate-200">
                    <?php if ($quiz !== null): ?>
                      <span class="inline-block px-1.5 rounded <?php echo $quiz >= 90 ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'; ?>"><?php echo $quiz; ?>%</span>
                    <?php else: ?>
                      <span class="text-slate-300">—</span>
                    <?php endif; ?>
                  </td>
                  <?php continue; ?>
                <?php endif; ?>
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
              <?php $sp = $studentPoints[$i]; ?>
              <td class="pp-col-points pp-col-total text-center whitespace-nowrap border-l border-slate-200 font-medium" title="Квизы на 100%: <?php echo (int)$sp['quiz']; ?> · решено задач: <?php echo (int)$sp['task']; ?>"><?php echo (int)$sp['points']; ?></td>
              <td class="pp-col-points pp-col-quiz text-center whitespace-nowrap border-l border-slate-200 font-medium" title="Баллы за квизы, сданные на 100%"><?php echo (int)$sp['quiz']; ?></td>
              <td class="pp-col-points pp-col-task text-center whitespace-nowrap border-l border-slate-200 font-medium" title="Баллы за решённые задачи"><?php echo (int)$sp['task']; ?></td>
              <td class="pp-col-grade text-center whitespace-nowrap border-l border-slate-200">
                <?php if ($sp['grade'] !== null): ?>
                  <span class="inline-block px-1.5 rounded font-medium <?php echo $gradeColor($sp['grade']); ?>"><?php echo (int)$sp['grade']; ?></span>
                <?php else: ?>
                  <span class="text-slate-300">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php // строка итогов по классу ?>
          <tr class="border-t-2 border-slate-300 bg-slate-50 font-medium">
            <td class="sticky left-0 z-10 bg-slate-50 border-r border-slate-200 border-l border-slate-200">Класс</td>
            <?php for ($n = $renderFrom; $n <= $renderTo; $n++): ?>
              <?php if ($n === $finalN): ?>
                <td class="text-center text-slate-400 border-l border-slate-200"><?php echo count($finalScores) ? (string)count($finalScores) : '—'; ?></td>
                <?php continue; ?>
              <?php endif; ?>
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
            <?php
              $studentCount = $students ? count($students) : 0;
              $avgPoints = $studentCount ? array_sum(array_column($studentPoints, 'points')) / $studentCount : null;
              $avgQuizPoints = $studentCount ? array_sum(array_column($studentPoints, 'quiz')) / $studentCount : null;
              $avgTaskPoints = $studentCount ? array_sum(array_column($studentPoints, 'task')) / $studentCount : null;
              $gradeList = array_values(array_filter(array_column($studentPoints, 'grade'), fn($g) => $g !== null));
              $avgGrade = $gradeList ? array_sum($gradeList) / count($gradeList) : null;
            ?>
            <td class="pp-col-points pp-col-total text-center border-l border-slate-200 text-slate-600 text-xs" title="Средние баллы по классу"><?php echo $avgPoints !== null ? 'ср. ' . e(number_format($avgPoints, 1, ',', ' ')) : '—'; ?></td>
            <td class="pp-col-points pp-col-quiz text-center border-l border-slate-200 text-slate-600 text-xs" title="Средние баллы за квизы"><?php echo $avgQuizPoints !== null ? 'ср. ' . e(number_format($avgQuizPoints, 1, ',', ' ')) : '—'; ?></td>
            <td class="pp-col-points pp-col-task text-center border-l border-slate-200 text-slate-600 text-xs" title="Средние баллы за задачи"><?php echo $avgTaskPoints !== null ? 'ср. ' . e(number_format($avgTaskPoints, 1, ',', ' ')) : '—'; ?></td>
            <td class="pp-col-grade text-center border-l border-slate-200 text-slate-600 text-xs" title="Средняя оценка по классу"><?php echo $avgGrade !== null ? 'ср. ' . e(number_format($avgGrade, 2, ',', ' ')) : '—'; ?></td>
          </tr>
        </tbody>
      </table>
    </div>
    </div>

    <div class="mt-3 text-xs text-slate-500 space-y-1">
      <p>Формат ячейки: <b>квиз%</b> · <b>решено/всего</b> задач урока. Звёздочка после номера урока — к уроку привязаны задачи-контесты.</p>
      <p>Фон ячейки: <span class="inline-block px-1.5 rounded bg-emerald-100 text-emerald-800 font-medium">зелёный</span> — квиз на 100% и (нет задач или все задачи решены);
        <span class="inline-block px-1.5 rounded bg-amber-100 text-amber-800 font-medium">жёлтый</span> — квиз не на 100% и/или задачи решены не полностью (были попытки);
        белый — квиз и задачи не пытались решать.</p>
      <p>«·» — данные по уроку отсутствуют (в т.ч. уроки вне диапазона, где ученики ещё ничего не решали).</p>
      <p>Диапазон уроков выбирается в фильтре выше; по умолчанию — с первого по последний урок с результатами в классе.</p>
      <p><b>Баллы</b> — число уроков, квиз которых сдан на 100%, плюс суммарно решённых задач; диапазоны уроков для квизов и задач задаются отдельно в фильтре.
        <b>Оценка</b>: меньше половины максимума — 2; вторая половина делится на три равные части — 3, 4 и 5.
        Переключатель «Баллы и оценка» скрывает эти столбцы, «Баллы квизов/задач отдельно» добавляет столбцы баллов по квизам и задачам (к общему столбцу «Баллы»).</p>
      <p><a class="text-indigo-600 underline" href="<?php echo e($pyUrl); ?>" target="_blank">Открыть python-курс</a></p>
    </div>
  <?php endif; ?>
<?php elseif ($selected): ?>
  <div class="card p-6 text-center text-slate-400">Нет данных от внешних сервисов.</div>
<?php endif; ?>
