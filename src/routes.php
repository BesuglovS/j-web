<?php

// Централизованное определение маршрутов.
$r = new Router();

// ---- Аутентификация ----
$r->get('/login', 'AuthController@loginForm');
$r->get('/logout', 'AuthController@logout');

// ---- Корень ----
$r->get('/', 'DashboardController@index');

// ---- Администратор ----
$r->get('/admin', 'AdminController@index');
// справочники
$r->get('/admin/classes',        'AdminController@classes');
$r->post('/admin/classes/sync',  'AdminController@classSync');
$r->get('/admin/subjects',       'AdminController@subjects');
$r->post('/admin/subjects/save', 'AdminController@subjectSave');
$r->post('/admin/subjects/delete/{id}', 'AdminController@subjectDelete');
$r->get('/admin/quarters',       'AdminController@quartersIndex');
$r->post('/admin/quarters/save', 'AdminController@quarterSave');
$r->post('/admin/quarters/delete/{id}', 'AdminController@quarterDelete');

// тьюторы (классные руководители): учётки — логины SSO auth-web,
// вход на портал; журнал хранит только логин/ФИО и привязку к классам
$r->get('/admin/tutors',              'AdminController@tutors');
$r->post('/admin/tutors/save',        'AdminController@tutorSave');
$r->post('/admin/tutors/delete/{id}', 'AdminController@tutorDelete');

// студенты (только просмотр; состав ведётся в auth-web и синхронизируется)
$r->get('/admin/students',           'AdminController@studentsIndex');
$r->post('/admin/students/sync',     'AdminController@studentSync');
$r->get('/admin/students/{id}',      'AdminController@studentView');
// родители: CRUD и связи ведутся в auth-web, журнал — read-only зеркало
// (ParentService::ensureSynced())

// журнал занятий
$r->get('/admin/lessons',          'AdminController@lessonsIndex');
$r->get('/admin/lessons/quick',    'AdminController@quickDay');
$r->post('/admin/lessons/quick/save', 'AdminController@quickDaySave');
$r->get('/admin/lessons/attend',    'AdminController@quickAttend');
$r->post('/admin/lessons/attend/save', 'AdminController@quickAttendSave');
$r->get('/admin/lessons/new',      'AdminController@lessonForm');
$r->get('/admin/lessons/edit',     'AdminController@lessonForm');
$r->post('/admin/lessons/save',    'AdminController@lessonSave');
$r->post('/admin/lessons/delete/{id}', 'AdminController@lessonDelete');
$r->get('/admin/lessons/{id}',     'AdminController@lessonView');
// операции внутри занятия
$r->post('/admin/lessons/{id}/marks',      'AdminController@markSave');
$r->post('/admin/lessons/{id}/remarks',    'AdminController@remarkSave');
$r->post('/admin/lessons/{id}/homework',   'AdminController@homeworkSave');
$r->post('/admin/lessons/{id}/submissions', 'AdminController@submissionSave');
$r->post('/admin/homework/delete/{id}',    'AdminController@homeworkDelete');

// итоги и отчёт
$r->get('/admin/grades', 'AdminController@gradesIndex');

// успеваемость по python-курсу (квизы python-web + задачи contest-web)
$r->get('/admin/python-progress', 'AdminController@pythonProgress');

// ---- Ученик ----
$r->get('/my',                  'StudentController@index');
$r->get('/my/grades',           'StudentController@grades');
$r->get('/my/homeworks',         'StudentController@homeworks');
$r->post('/my/homeworks/{id}/submit', 'StudentController@homeworkSubmit');
$r->get('/my/remarks',           'StudentController@remarks');

// ---- Тьютор (классный руководитель): read-only просмотр своих классов ----
$r->get('/tutor',        'TutorController@index');
$r->get('/tutor/grades', 'TutorController@grades');

// ---- Родитель ----
$r->get('/parent',                'ParentController@index');
$r->get('/parent/child/{id}',     'ParentController@child');

// ---- API v1 (JSON) для мобильного приложения ----
$r->options('/api/v1/{any}', 'ApiController@me'); // CORS preflight
$r->get('/api/v1/me',                       'ApiController@me');
$r->get('/api/v1/classes',                  'ApiController@classes');
$r->get('/api/v1/classes/{id}/subjects',    'ApiController@classSubjects');
$r->get('/api/v1/lessons',                  'ApiController@lessons');
$r->get('/api/v1/lessons/{id}',             'ApiController@lessonDetail');
$r->post('/api/v1/lessons',                 'ApiController@lessonCreate');
$r->post('/api/v1/lessons/{id}/marks',      'ApiController@markSave');
$r->post('/api/v1/lessons/{id}/remarks',    'ApiController@remarkSave');
  $r->post('/api/v1/lessons/{id}/attendance', 'ApiController@attendanceSave');
  $r->post('/api/v1/lessons/{id}/homework',   'ApiController@homeworkSave');
  $r->post('/api/v1/homework/{id}/delete',   'ApiController@homeworkDelete');
$r->get('/api/v1/students',                 'ApiController@students');
$r->get('/api/v1/quarters',                 'ApiController@quarters');
$r->get('/api/v1/class-journal',            'ApiController@classJournal');
$r->get('/api/v1/grades',                   'ApiController@grades');

// Внутренний сервер-к-сервер эндпоинт: auth-web после изменения родителей
// вызывает его с доверенного IP — мгновенный синк зеркала (ParentService)
$r->post('/api/internal/parents-sync', 'ApiController@parentsSync');

return $r;