<?php # реализация общения с советниками
# загрузка базовых функций
include_once "core.php";

# Создать объект для хранения данных
$registry = Registry::rel();
# создать объект работы с БД
$registry->db = new Sql;
$registry->debug = true;
# массив в котором будут находится параметры запроса
$registry->request = new Request;
# путь к корневой директории
$registry->site_path = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR;
# префиксы файлов 
$registry->controller_prefix = $controller_prefix;
$registry->model_prefix = $model_prefix;
# проверка устройства
$registry->mobile = CheckModileDevice();
# загрузить router и установить путь к контроллерам
$registry->router = new Router_Request(__DIR__ . DIRECTORY_SEPARATOR . 'controllers', __DIR__ . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'controllers');
# найти соответствующий обработчик
$registry->router->delegate();
#echo '<br/>--- FINISH ---';
