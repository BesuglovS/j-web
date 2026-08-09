<?php

// Централизованное определение маршрутов.
$r = new Router();

// ---- Аутентификация ----
$r->get('/login', 'AuthController@loginForm');
$r->post('/login', 'AuthController@login');
$r->get('/logout', 'AuthController@logout');
$r->post('/password', 'AuthController@changePassword');

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

// студенты (только просмотр; состав ведётся в auth-web и синхронизируется)
$r->get('/admin/students',           'AdminController@studentsIndex');
$r->post('/admin/students/sync',     'AdminController@studentSync');
$r->get('/admin/students/{id}',      'AdminController@studentView');
// родители
$r->get('/admin/parents',           'AdminController@parentsIndex');
$r->get('/admin/parents/new',       'AdminController@parentForm');
$r->get('/admin/parents/edit',      'AdminController@parentForm');
$r->post('/admin/parents/save',     'AdminController@parentSave');
$r->post('/admin/parents/delete/{id}', 'AdminController@parentDelete');
$r->post('/admin/links/save',       'AdminController@linkSave');

// журнал занятий
$r->get('/admin/lessons',          'AdminController@lessonsIndex');
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

// импорт
$r->get('/admin/import', 'AdminController@importIndex');
$r->post('/admin/import', 'AdminController@importProcess');
$r->get('/admin/import/logs', 'AdminController@importLogs');

// пользователи
$r->get('/admin/users', 'AdminController@usersIndex');
$r->post('/admin/users/reset', 'AdminController@userResetPassword');

// ---- Ученик ----
$r->get('/my',                  'StudentController@index');
$r->get('/my/grades',           'StudentController@grades');
$r->get('/my/homeworks',         'StudentController@homeworks');
$r->post('/my/homeworks/{id}/submit', 'StudentController@homeworkSubmit');
$r->get('/my/remarks',           'StudentController@remarks');

// ---- Родитель ----
$r->get('/parent',                'ParentController@index');
$r->get('/parent/child/{id}',     'ParentController@child');

return $r;