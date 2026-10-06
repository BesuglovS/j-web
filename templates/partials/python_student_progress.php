<?php
/**
 * Блок прогресса ученика по python-курсу (квизы python-web + задачи contest-web).
 * Слоты: $data['progress'] (результат StudentProgressService::mySummary/
 * studentSummary) и $data['refreshUrl'] (ссылка «Обновить», обычно ?refresh=1).
 * Используется на страницах /my, /parent/child/{id} и /tutor/class/{id}.
 */
$progress = $data['progress'] ?? null;
$refreshUrl = (string)($data['refreshUrl'] ?? '#');
?>
<div class="card mt-6 p-4">
  <div class="flex items-center justify-between mb-3">
    <h4 class="font-semibold">Python-курс: квизы и контесты</h4>
    <a href="<?php echo e($refreshUrl); ?>" class="text-sm text-blue-600 hover:underline">Обновить</a>
  </div>
  <?php if ($progress === null): ?>
    <p class="text-slate-400 text-sm">Прогресс по python-курсу недоступен. Попробуйте позже.</p>
  <?php else: ?>
    <?php if ($progress['errors']): ?>
      <p class="text-amber-700 text-sm mb-2"><?php echo e(implode('; ', $progress['errors'])); ?></p>
    <?php endif; ?>
    <?php if ($progress['lessons'] || $progress['final'] !== null): ?>
      <?php
      $contestSiteUrl = config()['contest_site_url'];
      $quizDone = count(array_filter($progress['lessons'], fn($l) => $l['quiz'] !== null));
      $solvedTotal = array_sum(array_map(fn($l) => $l['solved'], $progress['lessons']));
      ?>
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
        <?php foreach ($progress['lessons'] as $l): ?>
          <?php
          // статус плитки: зелёная — квиз сдан на 100% и задачи контеста решены
          // (если к уроку задач нет — достаточно квиза); жёлтая — есть частичный результат
          $full = $l['quiz'] !== null && (int)$l['quiz'] === 100
              && (!$l['has_contest'] || ($l['total'] !== null && $l['solved'] >= $l['total']));
          $partial = !$full && ($l['quiz'] !== null || $l['solved'] > 0);
          $cls = $full ? 'bg-emerald-50 border-emerald-300'
              : ($partial ? 'bg-amber-50 border-amber-300' : 'bg-slate-50 border-slate-200');
          ?>
          <div class="border rounded-lg p-3 <?php echo $cls; ?>">
            <div class="font-semibold text-slate-800 text-sm mb-2">
              <?php if (!empty($l['quiz_url'])): ?>
                <a href="<?php echo e($l['quiz_url']); ?>" target="_blank" rel="noopener"
                   class="hover:underline" title="Открыть урок">Урок <?php echo (int)$l['num']; ?></a>
              <?php else: ?>
                Урок <?php echo (int)$l['num']; ?>
              <?php endif; ?>
            </div>
            <?php if (!empty($l['quiz_url'])): ?>
              <a href="<?php echo e($l['quiz_url'] . '#quiz'); ?>" target="_blank" rel="noopener"
                 class="text-xs flex justify-between py-0.5 hover:underline">
                <span class="text-slate-500">Квиз</span>
                <span class="font-semibold <?php echo $l['quiz'] !== null ? 'text-slate-800' : 'text-slate-400'; ?>">
                  <?php echo $l['quiz'] !== null ? (int)$l['quiz'] : '→'; ?>
                </span>
              </a>
            <?php else: ?>
              <div class="text-xs flex justify-between py-0.5">
                <span class="text-slate-500">Квиз</span>
                <span class="font-semibold <?php echo $l['quiz'] !== null ? 'text-slate-800' : 'text-slate-400'; ?>">
                  <?php echo $l['quiz'] !== null ? (int)$l['quiz'] : '—'; ?>
                </span>
              </div>
            <?php endif; ?>
            <?php if ($l['has_contest'] && !empty($l['contest_id'])): ?>
              <a href="<?php echo e($contestSiteUrl . '/index.php?page=contest&id=' . (int)$l['contest_id']); ?>"
                 target="_blank" rel="noopener" class="text-xs flex justify-between py-0.5 hover:underline">
                <span class="text-slate-500">Контест</span>
                <span class="font-semibold <?php echo ($l['solved'] > 0) ? ($full ? 'text-emerald-700' : 'text-slate-800') : 'text-slate-400'; ?>">
                  <?php if ($l['total'] !== null && $l['total'] > 0): ?>
                    <?php echo (int)$l['solved']; ?>/<?php echo (int)$l['total']; ?>
                  <?php elseif ($l['solved'] > 0): ?>
                    <?php echo (int)$l['solved']; ?>
                  <?php else: ?>
                    →
                  <?php endif; ?>
                </span>
              </a>
            <?php else: ?>
              <div class="text-xs flex justify-between py-0.5">
                <span class="text-slate-500">Контест</span>
                <span class="font-semibold text-slate-400">—</span>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if ($progress['final'] !== null): ?>
          <div class="border rounded-lg p-3 bg-indigo-50 border-indigo-300">
            <div class="font-semibold text-slate-800 text-sm mb-2">Итоговый тест</div>
            <a href="<?php echo e($progress['final_url'] . '#quiz'); ?>" target="_blank" rel="noopener"
               class="text-xs flex justify-between py-0.5 hover:underline">
              <span class="text-slate-500">Квиз</span>
              <span class="font-semibold text-slate-800"><?php echo (int)$progress['final']; ?></span>
            </a>
            <div class="text-xs flex justify-between py-0.5">
              <span class="text-slate-500">Контест</span>
              <span class="font-semibold text-slate-400">—</span>
            </div>
          </div>
        <?php endif; ?>
      </div>
      <p class="text-slate-500 text-xs mt-2">Сдано квизов: <?php echo (int)$quizDone; ?> · Решено задач в контестах: <?php echo (int)$solvedTotal; ?></p>
    <?php else: ?>
      <p class="text-slate-400 text-sm">Пока нет решённых квизов и задач по python-курсу.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>
