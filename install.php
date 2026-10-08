<?php
/**
 * Инсталлятор сайта (однофайловый).
 *
 * Устройство:
 *  - $STEPS — реестр шагов. Каждый шаг: заголовок + обработчик POST + функция вывода.
 *  - Состояние хранится в $_SESSION['installer']:
 *      'done' => [stepKey => true]  — пройденные шаги
 *      'data' => [stepKey => [...]] — данные, введённые на шаге
 *  - Шаг доступен, только если все предыдущие пройдены.
 *  - Повторная проверка шага с ошибкой сбрасывает его и все следующие шаги.
 */

declare(strict_types=1);

const INSTALLER_VERSION = '0.6';
const LOCK_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'install.lock';   // создаётся на финальном шаге

error_reporting(E_ALL);
ini_set('display_errors', '0');

session_name('site_installer');
session_start();

if (!isset($_SESSION['installer']))
{
    $_SESSION['installer'] = ['done' => [], 'data' => []];
}

/* =====================================================================
 *  РЕЕСТР ШАГОВ
 *  Новый шаг = новая запись + две функции (handle / view).
 * ===================================================================== */
$STEPS = [
    'source' => [
        'title'  => 'Файлы движка',
        'handle' => 'step_source_handle',
        'view'   => 'step_source_view',
    ],
    'db' => [
        'title'  => 'База данных',
        'handle' => 'step_db_handle',
        'view'   => 'step_db_view',
    ],
    'tables' => [
        'title'  => 'Таблицы',
        'handle' => 'step_tables_handle',
        'view'   => 'step_tables_view',
    ],
    'admin' => [
        'title'  => 'Администратор',
        'handle' => 'step_admin_handle',
        'view'   => 'step_admin_view',
    ],
    'settings' => [
        'title'  => 'Настройки сайта',
        'handle' => 'step_settings_handle',
        'view'   => 'step_settings_view',
    ],
    'finish' => [
        'title'  => 'Завершение',
        'handle' => 'step_finish_handle',
        'view'   => 'step_finish_view',
    ],
];

/* =====================================================================
 *  ОБЩИЕ ФУНКЦИИ
 * ===================================================================== */

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['installer_csrf']))
    {
        $_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['installer_csrf'];
}

function csrf_check(): bool
{
    $sent = $_POST['_csrf'] ?? '';
    return is_string($sent) && hash_equals(csrf_token(), $sent);
}

function redirect_to_step(string $step): void
{
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?step=' . urlencode($step));
    exit;
}

function step_keys(): array
{
    global $STEPS;
    return array_keys($STEPS);
}

function step_done(string $key): bool
{
    return !empty($_SESSION['installer']['done'][$key]);
}

/** Шаг доступен, если все предыдущие пройдены. */
function step_available(string $key): bool
{
    foreach (step_keys() as $k)
    {
        if ($k === $key)
        {
            return true;
        }
        if (!step_done($k))
        {
            return false;
        }
    }
    return false;
}

/** Первый непройденный шаг (или последний, если пройдено всё). */
function first_pending_step(): string
{
    $keys = step_keys();
    foreach ($keys as $k)
    {
        if (!step_done($k))
        {
            return $k;
        }
    }
    return end($keys);
}

function next_step(string $key): ?string
{
    $keys = step_keys();
    $i = array_search($key, $keys, true);
    return ($i !== false && isset($keys[$i + 1])) ? $keys[$i + 1] : null;
}

/** Отметить шаг пройденным / сбросить его и все последующие. */
function mark_step(string $key, bool $ok): void
{
    if ($ok)
    {
        $_SESSION['installer']['done'][$key] = true;
        return;
    }
    $reset = false;
    foreach (step_keys() as $k)
    {
        if ($k === $key)
        {
            $reset = true;
        }
        if ($reset)
        {
            unset($_SESSION['installer']['done'][$k]);
        }
    }
}

/** Сбросить все шаги после указанного (например, если изменились данные шага). */
function reset_steps_after(string $key): void
{
    $after = false;
    foreach (step_keys() as $k)
    {
        if ($after)
        {
            unset($_SESSION['installer']['done'][$k]);
        }
        if ($k === $key)
        {
            $after = true;
        }
    }
}

function step_data(string $key): array
{
    return $_SESSION['installer']['data'][$key] ?? [];
}

function set_step_data(string $key, array $data): void
{
    $_SESSION['installer']['data'][$key] = $data;
}

/** Flash-сообщения: переживают один редирект (POST → GET). */
function flash(string $type, string $text): void
{
    $_SESSION['installer_flash'][] = ['type' => $type, 'text' => $text];
}

function take_flash(): array
{
    $f = $_SESSION['installer_flash'] ?? [];
    unset($_SESSION['installer_flash']);
    return $f;
}

/* =====================================================================
 *  ШАГ 1: ФАЙЛЫ ДВИЖКА (скачивание с GitHub и распаковка в корень сайта)
 * ===================================================================== */

const SOURCE_DEFAULTS = [
    'repo'   => 'xttz/mvc',   // владелец/репозиторий или ссылка на GitHub
    'branch' => 'main',
];

const SOURCE_TIMEOUT    = 300;   // секунд на скачивание
const SOURCE_USER_AGENT = 'PHP-Site-Installer';

/** Папка, куда распаковывается движок (корень сайта = папка инсталлятора). */
function source_target_dir(): string
{
    return __DIR__;
}

/** «owner/repo» или https://github.com/owner/repo(.git) → ['owner', 'repo'] или null. */
function github_parse_repo(string $s): ?array
{
    if (preg_match('#^(?:https?://github\.com/)?([A-Za-z0-9_.\-]+)/([A-Za-z0-9_.\-]+?)(?:\.git)?/?$#', $s, $m))
    {
        return [$m[1], $m[2]];
    }
    return null;
}

/** Проверка окружения: [текст => ok]. */
function source_requirements(): array
{
    return [
        'Расширение zip (ZipArchive)'               => class_exists('ZipArchive'),
        'Расширение curl или allow_url_fopen'       => function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN),
        'Запись в папку ' . source_target_dir()     => is_writable(source_target_dir()),
        'Запись во временную папку ' . sys_get_temp_dir() => is_writable(sys_get_temp_dir()),
    ];
}

/**
 * Скачивание файла по HTTP(S) в $dest.
 * Возвращает ['code' => HTTP-код, 'error' => текст ошибки или ''].
 */
function http_download(string $url, array $headers, string $dest): array
{
    $fp = @fopen($dest, 'wb');
    if ($fp === false)
    {
        return ['code' => 0, 'error' => 'Не удалось создать временный файл ' . $dest];
    }

    if (function_exists('curl_init'))
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_USERAGENT      => SOURCE_USER_AGENT,   // GitHub требует User-Agent
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => SOURCE_TIMEOUT,
        ]);
        $ok    = curl_exec($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $error = $ok ? '' : curl_error($ch);
        unset($ch);
        fclose($fp);

        if ($errno === 60 || $errno === 77)
        {
            $error .= '. В PHP не настроен список корневых сертификатов: укажите путь к cacert.pem в curl.cainfo и openssl.cafile в php.ini.';
        }
        return ['code' => $code, 'error' => $error];
    }

    // Запасной вариант без curl: потоки PHP (нужен allow_url_fopen)
    $ctx = stream_context_create(['http' => [
        'header'          => implode("\r\n", $headers),
        'user_agent'      => SOURCE_USER_AGENT,
        'timeout'         => SOURCE_TIMEOUT,
        'follow_location' => 1,
        'max_redirects'   => 5,
        'ignore_errors'   => true,
    ]]);
    $in = @fopen($url, 'rb', false, $ctx);
    if ($in === false)
    {
        fclose($fp);
        $last = error_get_last();
        return ['code' => 0, 'error' => $last['message'] ?? 'Не удалось открыть ' . $url];
    }
    stream_copy_to_stream($in, $fp);
    fclose($in);
    fclose($fp);

    $responseHeaders = function_exists('http_get_last_response_headers')
        ? (http_get_last_response_headers() ?? [])
        : ($http_response_header ?? []);
    $code = 0;
    foreach ($responseHeaders as $line)
    {
        // После редиректов заголовков несколько — берём статус последнего ответа
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m))
        {
            $code = (int)$m[1];
        }
    }
    return ['code' => $code, 'error' => ''];
}

/** Текст ошибки ZipArchive::open(). */
function zip_error_text(int $code): string
{
    $texts = [
        ZipArchive::ER_INCONS => 'архив повреждён — возможно, скачался не полностью',
        ZipArchive::ER_MEMORY => 'не хватило памяти',
        ZipArchive::ER_NOENT  => 'файл архива не найден',
        ZipArchive::ER_NOZIP  => 'это не zip-архив — скорее всего, скачалась HTML-страница с ошибкой',
        ZipArchive::ER_OPEN   => 'не удалось открыть файл архива',
        ZipArchive::ER_READ   => 'ошибка чтения архива',
    ];
    return $texts[$code] ?? 'код ошибки ' . $code;
}

/**
 * Распаковка архива GitHub в $targetDir.
 * Верхняя папка архива («owner-repo-sha/») отбрасывается, файлы ложатся прямо в корень.
 * Возвращает количество записанных файлов.
 */
function extract_github_zip(string $zipFile, string $targetDir): int
{
    $zip  = new ZipArchive();
    $code = $zip->open($zipFile);
    if ($code !== true)
    {
        throw new RuntimeException('Не удалось открыть архив: ' . zip_error_text((int)$code) . '.');
    }

    $files = 0;
    $sep   = DIRECTORY_SEPARATOR;

    try
    {
        for ($i = 0; $i < $zip->numFiles; $i++)
        {
            $name = (string)$zip->getNameIndex($i);

            // Отрезаем верхнюю папку архива
            $slash = strpos($name, '/');
            if ($slash === false)
            {
                continue;
            }
            $rel = substr($name, $slash + 1);
            if ($rel === '')
            {
                continue;
            }

            // Защита от путей вида ../ и абсолютных путей внутри архива
            $parts = explode('/', rtrim($rel, '/'));
            if (in_array('..', $parts, true) || in_array('', $parts, true) || preg_match('#^[A-Za-z]:#', $rel))
            {
                throw new RuntimeException('Подозрительный путь в архиве: ' . $name);
            }

            $target = $targetDir . $sep . implode($sep, $parts);

            if (substr($name, -1) === '/')
            {
                if (!is_dir($target) && !@mkdir($target, 0755, true))
                {
                    throw new RuntimeException('Не удалось создать папку ' . $target);
                }
                continue;
            }

            $dir = dirname($target);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true))
            {
                throw new RuntimeException('Не удалось создать папку ' . $dir);
            }

            $in  = $zip->getStream($name);
            $out = @fopen($target, 'wb');
            if ($in === false || $out === false)
            {
                throw new RuntimeException('Не удалось записать файл ' . $target);
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $files++;
        }
    }
    finally
    {
        $zip->close();
    }

    return $files;
}

function step_source_handle(): bool
{
    if (($_POST['action'] ?? '') === 'skip')
    {
        flash('ok', 'Скачивание пропущено: файлы движка уже на месте.');
        return true;
    }

    $in = [
        'repo'   => trim((string)($_POST['repo'] ?? '')),
        'branch' => trim((string)($_POST['branch'] ?? '')),
    ];
    $token = trim((string)($_POST['token'] ?? ''));   // токен в сессии не храним
    set_step_data('source', $in);

    // --- валидация ---
    $errors = [];
    foreach (source_requirements() as $text => $ok)
    {
        if (!$ok)
        {
            $errors[] = 'Не выполнено требование: ' . $text . '.';
        }
    }
    $repo = github_parse_repo($in['repo']);
    if ($repo === null)
    {
        $errors[] = 'Репозиторий: укажите «владелец/репозиторий» или ссылку на GitHub.';
    }
    if (!preg_match('#^[A-Za-z0-9._/\-]{1,100}$#', $in['branch']))
    {
        $errors[] = 'Некорректное имя ветки.';
    }
    if ($errors)
    {
        foreach ($errors as $e)
        {
            flash('error', $e);
        }
        return false;
    }

    // --- скачивание ---
    [$owner, $name] = $repo;
    $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($name)
         . '/zipball/' . str_replace('%2F', '/', rawurlencode($in['branch']));
    $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
    if ($token !== '')
    {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    @set_time_limit(SOURCE_TIMEOUT + 60);
    $tmp = tempnam(sys_get_temp_dir(), 'inst');

    try
    {
        $res = http_download($url, $headers, $tmp);
        if ($res['error'] !== '')
        {
            flash('error', 'Ошибка скачивания: ' . $res['error']);
            return false;
        }
        if ($res['code'] !== 200)
        {
            $hints = [
                401 => 'токен недействителен',
                403 => 'доступ запрещён или превышен лимит запросов к GitHub API (60 в час без токена)',
                404 => 'репозиторий или ветка не найдены; если репозиторий приватный — укажите токен',
            ];
            flash('error', 'GitHub ответил кодом ' . $res['code'] . ': ' . ($hints[$res['code']] ?? 'неожиданный ответ') . '.');
            return false;
        }
        $size = (int)filesize($tmp);
        $head = (string)file_get_contents($tmp, false, null, 0, 4);
        if ($head !== "PK\x03\x04")
        {
            flash('error', 'Скачанный файл не является zip-архивом (' . $size . ' байт).');
            return false;
        }
        flash('ok', 'Архив ' . $owner . '/' . $name . ' (' . $in['branch'] . ') скачан: ' . number_format($size / 1024, 0, ',', ' ') . ' КБ.');

        // --- распаковка ---
        $files = extract_github_zip($tmp, source_target_dir());
        flash('ok', 'Распаковано файлов: ' . $files . '.');
    }
    catch (RuntimeException $e)
    {
        flash('error', $e->getMessage());
        return false;
    }
    finally
    {
        @unlink($tmp);
    }

    return true;
}

function step_source_view(): void
{
    $d    = step_data('source') + SOURCE_DEFAULTS;
    $reqs = source_requirements();
    ?>
    <table class="list">
        <tr><th>Проверка</th><th>Состояние</th></tr>
        <?php foreach ($reqs as $text => $ok): ?>
            <tr>
                <td><?= h($text) ?></td>
                <td class="<?= $ok ? 'ok' : 'err' ?>"><?= $ok ? 'да' : 'нет' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <form method="post" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <div class="grid">
            <label>Репозиторий GitHub
                <input name="repo" value="<?= h($d['repo']) ?>" placeholder="владелец/репозиторий" required>
            </label>
            <label>Ветка
                <input name="branch" value="<?= h($d['branch']) ?>" required>
            </label>
            <label class="wide">Токен доступа <small>(только для приватного репозитория, не сохраняется)</small>
                <input name="token" type="password" autocomplete="off">
            </label>
        </div>
        <p class="muted">Файлы распаковываются в <code><?= h(source_target_dir()) ?></code>.</p>
        <div class="actions">
            <button type="submit" name="action" value="download"
                    onclick="this.textContent='Скачивание…'">Скачать и распаковать</button>
            <button type="submit" name="action" value="skip" formnovalidate>Пропустить</button>
            <?php if (step_done('source') && ($n = next_step('source'))): ?>
                <a class="btn primary" href="?step=<?= h($n) ?>">Далее →</a>
            <?php endif; ?>
        </div>
    </form>
    <?php
}

/* =====================================================================
 *  ШАГ 2: БАЗА ДАННЫХ
 * ===================================================================== */

const DB_DEFAULTS = [
    'host'    => 'localhost',
    'port'    => '3306',
    'name'    => '',
    'user'    => '',
    'pass'    => '',
    'prefix'  => '',
    'create'  => '0',
];

const DB_CHARSET     = 'utf8mb4';
const DB_COLLATION   = 'utf8mb4_0900_ai_ci';
const DB_MIN_VERSION = '8.0.16';   // utf8mb4_0900_*, рабочие CHECK-ограничения

/** Подключение через PDO. $withDb=false — подключение к серверу без выбора БД. */
function db_connect(array $c, bool $withDb = true): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], (int)$c['port'], DB_CHARSET);
    if ($withDb)
    {
        $dsn .= ';dbname=' . $c['name'];
    }
    return new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
}

/** Человекочитаемое описание типичных ошибок MySQL. */
function db_error_text(PDOException $e): string
{
    $msg  = $e->getMessage();
    $code = $e->errorInfo[1] ?? null;
    if (!$code && preg_match('/\[(\d{4})\]/', $msg, $m))
    {
        $code = (int)$m[1];
    }
    $hints = [
        1044 => 'У пользователя нет доступа к этой базе данных.',
        1045 => 'Неверное имя пользователя или пароль.',
        1049 => 'База данных не существует. Отметьте «Создать базу, если её нет» или создайте её вручную.',
        2002 => 'Не удалось подключиться к серверу. Проверьте хост, порт и что MySQL запущен.',
        2005 => 'Неизвестный хост MySQL.',
        2006 => 'Сервер MySQL разорвал соединение.',
        1062 => 'Запись с таким значением уже существует.',
    ];
    $hint = $hints[(int)$code] ?? 'Ошибка базы данных.';
    return $hint . ' (' . $msg . ')';
}

function step_db_handle(): bool
{
    $in = [];
    foreach (DB_DEFAULTS as $k => $def)
    {
        $v = $_POST[$k] ?? $def;
        $in[$k] = is_string($v) ? trim($v) : $def;
    }
    $in['pass']   = (string)($_POST['pass'] ?? '');      // пароль не обрезаем
    $in['create'] = isset($_POST['create']) ? '1' : '0';

    // Изменились параметры подключения — следующие шаги нужно пройти заново
    if (step_data('db') !== $in)
    {
        reset_steps_after('db');
    }
    set_step_data('db', $in);

    // --- валидация ---
    $errors = [];
    if (!extension_loaded('pdo_mysql'))
    {
        $errors[] = 'В PHP не установлено расширение pdo_mysql.';
    }
    if ($in['host'] === '')
    {
        $errors[] = 'Укажите хост.';
    }
    if (!ctype_digit($in['port']) || (int)$in['port'] < 1 || (int)$in['port'] > 65535)
    {
        $errors[] = 'Порт должен быть числом от 1 до 65535.';
    }
    if (!preg_match('/^[A-Za-z0-9_$]{1,64}$/', $in['name']))
    {
        $errors[] = 'Имя базы: латиница, цифры, «_» или «$», до 64 символов.';
    }
    if ($in['user'] === '')
    {
        $errors[] = 'Укажите пользователя.';
    }
    if ($in['prefix'] !== '' && !preg_match('/^[A-Za-z0-9_]{1,20}$/', $in['prefix']))
    {
        $errors[] = 'Префикс таблиц: латиница, цифры и «_», до 20 символов.';
    }
    if ($errors)
    {
        foreach ($errors as $e)
        {
            flash('error', $e);
        }
        return false;
    }

    // --- подключение ---
    try
    {
        $server = db_connect($in, false);
        $version = (string)$server->query('SELECT VERSION()')->fetchColumn();
        flash('ok', 'Подключение к серверу MySQL успешно. Версия: ' . $version);

        if (stripos($version, 'mariadb') !== false)
        {
            flash('error', 'Нужен MySQL ' . DB_MIN_VERSION . ' или новее, MariaDB не поддерживается.');
            return false;
        }
        $numeric = preg_match('/^\d+\.\d+\.\d+/', $version, $m) ? $m[0] : '0';
        if (version_compare($numeric, DB_MIN_VERSION, '<'))
        {
            flash('error', 'Версия MySQL ' . $version . ' слишком старая, нужна ' . DB_MIN_VERSION . ' или новее.');
            return false;
        }

        $st = $server->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $st->execute([$in['name']]);
        $exists = (bool)$st->fetchColumn();

        if (!$exists)
        {
            if ($in['create'] !== '1')
            {
                flash('error', 'База «' . $in['name'] . '» не найдена. Отметьте «Создать базу, если её нет» или создайте её вручную.');
                return false;
            }
            $server->exec(sprintf('CREATE DATABASE `%s` CHARACTER SET %s COLLATE %s', $in['name'], DB_CHARSET, DB_COLLATION));
            flash('ok', 'База «' . $in['name'] . '» создана.');
        }
        $server = null;

        // Подключение к выбранной базе и проверка прав на создание/удаление таблиц
        $pdo = db_connect($in, true);
        $test = '`' . $in['prefix'] . '__installer_check`';
        $pdo->exec("CREATE TABLE IF NOT EXISTS $test (id INT PRIMARY KEY) ENGINE=InnoDB");
        $pdo->exec("DROP TABLE $test");
        flash('ok', 'Права на создание таблиц в «' . $in['name'] . '» есть.');
    }
    catch (PDOException $e)
    {
        flash('error', db_error_text($e));
        return false;
    }

    return true;
}

function step_db_view(): void
{
    $d = step_data('db') + DB_DEFAULTS;
    ?>
    <form method="post" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <div class="grid">
            <label>Хост
                <input name="host" value="<?= h($d['host']) ?>" required>
            </label>
            <label>Порт
                <input name="port" value="<?= h($d['port']) ?>" inputmode="numeric" required>
            </label>
            <label>Имя базы данных
                <input name="name" value="<?= h($d['name']) ?>" required>
            </label>
            <label>Пользователь
                <input name="user" value="<?= h($d['user']) ?>" required>
            </label>
            <label>Пароль
                <input name="pass" type="password" value="<?= h($d['pass']) ?>">
            </label>
            <label>Префикс таблиц <small>(необязательно)</small>
                <input name="prefix" value="<?= h($d['prefix']) ?>" placeholder="например, site_">
            </label>
        </div>
        <label class="check">
            <input type="checkbox" name="create" value="1" <?= $d['create'] === '1' ? 'checked' : '' ?>>
            Создать базу, если её нет
        </label>
        <div class="actions">
            <button type="submit" name="action" value="check">Проверить подключение</button>
            <?php if (step_done('db') && ($n = next_step('db'))): ?>
                <a class="btn primary" href="?step=<?= h($n) ?>">Далее →</a>
            <?php endif; ?>
        </div>
    </form>
    <?php
}

/* =====================================================================
 *  ШАГ 3: ТАБЛИЦЫ
 * ===================================================================== */

/** Подключение к базе по данным шага «База данных». */
function db_from_session(): PDO
{
    return db_connect(step_data('db') + DB_DEFAULTS, true);
}

/** Имя таблицы (или ограничения) с префиксом. */
function tbl(string $name): string
{
    return (step_data('db')['prefix'] ?? '') . $name;
}

/**
 * Схема БД: имя таблицы без префикса => CREATE TABLE.
 * Порядок важен: таблицы, на которые ссылаются внешние ключи, идут раньше.
 * Имена CHECK и FOREIGN KEY в MySQL уникальны в пределах всей базы,
 * поэтому они тоже получают префикс.
 */
function db_schema(): array
{
    $opts   = 'ENGINE=InnoDB DEFAULT CHARSET=' . DB_CHARSET . ' COLLATE=' . DB_COLLATION;
    $params = tbl('params');
    $users  = tbl('users');
    $chk    = tbl('chk_users_login');

    return [
        'params' => <<<SQL
            CREATE TABLE `{$params}` (
                `name`       VARCHAR(64)  NOT NULL,
                `value`      TEXT         NULL,
                `type`       ENUM('string','text','int','bool','json') NOT NULL DEFAULT 'string',
                `section`    VARCHAR(32)  NOT NULL DEFAULT 'main',
                `label`      VARCHAR(128) NOT NULL DEFAULT '',
                `sort`       SMALLINT     NOT NULL DEFAULT 0,
                `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`name`)
            ) {$opts}
            SQL,

        'users' => <<<SQL
            CREATE TABLE `{$users}` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `login`         VARCHAR(64)  NOT NULL,
                `email`         VARCHAR(255) NULL,
                `password_hash` VARCHAR(255) NULL,
                `role`          VARCHAR(32)  NOT NULL DEFAULT 'user',
                `is_active`     BOOLEAN      NOT NULL DEFAULT TRUE,
                `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_login_at` DATETIME     NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_login` (`login`),
                UNIQUE KEY `uq_email` (`email`),
                CONSTRAINT `{$chk}` CHECK (`login` <> '')
            ) {$opts}
            SQL,
    ];
}

/** Какие из таблиц схемы уже есть в базе: [имя без префикса => bool]. */
function db_existing_tables(PDO $pdo): array
{
    $found = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')
        ->fetchAll(PDO::FETCH_COLUMN);
    $found = array_flip($found);

    $result = [];
    foreach (array_keys(db_schema()) as $name)
    {
        $result[$name] = isset($found[tbl($name)]);
    }
    return $result;
}

function step_tables_handle(): bool
{
    $recreate = isset($_POST['recreate']);
    $schema   = db_schema();

    try
    {
        $pdo      = db_from_session();
        $existing = db_existing_tables($pdo);

        // Удаление — в обратном порядке, чтобы не мешали внешние ключи
        if ($recreate)
        {
            reset_steps_after('tables');   // данные удаляются — администратора нужно создать заново
            foreach (array_reverse(array_keys($schema)) as $name)
            {
                if ($existing[$name])
                {
                    $pdo->exec('DROP TABLE `' . tbl($name) . '`');
                    $existing[$name] = false;
                    flash('ok', 'Таблица «' . tbl($name) . '» удалена.');
                }
            }
        }

        foreach ($schema as $name => $sql)
        {
            if ($existing[$name])
            {
                flash('ok', 'Таблица «' . tbl($name) . '» уже есть, пропущена.');
                continue;
            }
            $pdo->exec($sql);
            flash('ok', 'Таблица «' . tbl($name) . '» создана.');
        }

        if (in_array(false, db_existing_tables($pdo), true))
        {
            flash('error', 'Не все таблицы удалось создать.');
            return false;
        }
    }
    catch (PDOException $e)
    {
        flash('error', db_error_text($e));
        return false;
    }

    return true;
}

function step_tables_view(): void
{
    try
    {
        $existing = db_existing_tables(db_from_session());
    }
    catch (PDOException $e)
    {
        echo '<div class="msg error">' . h(db_error_text($e)) . '</div>';
        echo '<p><a href="?step=db">← Вернуться к настройкам базы данных</a></p>';
        return;
    }
    $anyExists = in_array(true, $existing, true);
    ?>
    <table class="list">
        <tr><th>Таблица</th><th>Состояние</th></tr>
        <?php foreach ($existing as $name => $exists): ?>
            <tr>
                <td><code><?= h(tbl($name)) ?></code></td>
                <td class="<?= $exists ? 'ok' : 'muted' ?>"><?= $exists ? 'есть' : 'нет' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <?php if ($anyExists): ?>
            <label class="check">
                <input type="checkbox" name="recreate" value="1">
                Пересоздать существующие таблицы (все данные в них будут удалены)
            </label>
        <?php endif; ?>
        <div class="actions">
            <button type="submit" name="action" value="create"
                    onclick="var r=this.form.recreate; return !(r && r.checked) || confirm('Удалить существующие таблицы со всеми данными?')">
                Создать таблицы
            </button>
            <?php if (step_done('tables') && ($n = next_step('tables'))): ?>
                <a class="btn primary" href="?step=<?= h($n) ?>">Далее →</a>
            <?php endif; ?>
        </div>
    </form>
    <?php
}

/* =====================================================================
 *  ШАГ 4: АДМИНИСТРАТОР
 * ===================================================================== */

const ADMIN_ROLE         = 'admin';
const ADMIN_MIN_PASSWORD = 8;

/** Список администраторов, уже существующих в базе: [['id', 'login', 'email'], ...]. */
function admin_list(PDO $pdo): array
{
    $st = $pdo->prepare('SELECT `id`, `login`, `email` FROM `' . tbl('users') . '` WHERE `role` = ? ORDER BY `id`');
    $st->execute([ADMIN_ROLE]);
    return $st->fetchAll();
}

function step_admin_handle(): bool
{
    $login = trim((string)($_POST['login'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['pass'] ?? '');
    $pass2 = (string)($_POST['pass2'] ?? '');

    // Пароль в сессии не храним — только логин и email для повторного показа формы
    set_step_data('admin', ['login' => $login, 'email' => $email]);

    // --- валидация ---
    $errors = [];
    if (!preg_match('/^[A-Za-z0-9_.\-]{3,64}$/', $login))
    {
        $errors[] = 'Логин: от 3 до 64 символов, латиница, цифры, «_», «.» или «-».';
    }
    if ($email !== '' && (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false))
    {
        $errors[] = 'Некорректный email.';
    }
    if (mb_strlen($pass) < ADMIN_MIN_PASSWORD)
    {
        $errors[] = 'Пароль должен быть не короче ' . ADMIN_MIN_PASSWORD . ' символов.';
    }
    elseif ($pass !== $pass2)
    {
        $errors[] = 'Пароли не совпадают.';
    }
    if ($errors)
    {
        foreach ($errors as $e)
        {
            flash('error', $e);
        }
        return false;
    }

    // --- запись в базу ---
    $users = tbl('users');
    $hash  = password_hash($pass, PASSWORD_DEFAULT);

    try
    {
        $pdo = db_from_session();

        $st = $pdo->prepare("SELECT `id` FROM `{$users}` WHERE `login` = ?");
        $st->execute([$login]);
        $id = $st->fetchColumn();

        if ($id !== false)
        {
            // Повторный запуск инсталлятора: обновляем существующего пользователя
            $st = $pdo->prepare("UPDATE `{$users}`
                SET `email` = ?, `password_hash` = ?, `role` = ?, `is_active` = TRUE
                WHERE `id` = ?");
            $st->execute([$email !== '' ? $email : null, $hash, ADMIN_ROLE, $id]);
            flash('ok', 'Пользователь «' . $login . '» уже был в базе: пароль обновлён, назначена роль администратора.');
        }
        else
        {
            $st = $pdo->prepare("INSERT INTO `{$users}` (`login`, `email`, `password_hash`, `role`, `is_active`)
                VALUES (?, ?, ?, ?, TRUE)");
            $st->execute([$login, $email !== '' ? $email : null, $hash, ADMIN_ROLE]);
            flash('ok', 'Администратор «' . $login . '» создан.');
        }
    }
    catch (PDOException $e)
    {
        if ((int)($e->errorInfo[1] ?? 0) === 1062)
        {
            flash('error', 'Email «' . $email . '» уже используется другим пользователем.');
        }
        else
        {
            flash('error', db_error_text($e));
        }
        return false;
    }

    return true;
}

function step_admin_view(): void
{
    $d = step_data('admin') + ['login' => 'admin', 'email' => ''];

    try
    {
        $admins = admin_list(db_from_session());
    }
    catch (PDOException $e)
    {
        echo '<div class="msg error">' . h(db_error_text($e)) . '</div>';
        echo '<p><a href="?step=tables">← Вернуться к таблицам</a></p>';
        return;
    }
    ?>
    <?php if ($admins): ?>
        <p class="muted">Администраторы в базе:
            <?= h(implode(', ', array_column($admins, 'login'))) ?>.
            Если указать существующий логин, его пароль будет заменён.
        </p>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <div class="grid">
            <label>Логин
                <input name="login" value="<?= h($d['login']) ?>" required autocomplete="username">
            </label>
            <label>Email <small>(необязательно)</small>
                <input name="email" type="email" value="<?= h($d['email']) ?>">
            </label>
            <label>Пароль
                <input name="pass" type="password" minlength="<?= ADMIN_MIN_PASSWORD ?>" required autocomplete="new-password">
            </label>
            <label>Пароль ещё раз
                <input name="pass2" type="password" minlength="<?= ADMIN_MIN_PASSWORD ?>" required autocomplete="new-password">
            </label>
        </div>
        <div class="actions">
            <button type="submit" name="action" value="save">Сохранить администратора</button>
            <?php if (step_done('admin') && ($n = next_step('admin'))): ?>
                <a class="btn primary" href="?step=<?= h($n) ?>">Далее →</a>
            <?php endif; ?>
        </div>
    </form>
    <?php
}

/* =====================================================================
 *  ШАГ 5: НАСТРОЙКИ САЙТА
 * ===================================================================== */

/**
 * Базовые настройки сайта: имя в params => описание.
 *  label    — подпись поля (сохраняется в params.label для админки)
 *  type     — тип значения в params.type
 *  section  — раздел в админке
 *  required — обязательное поле
 *  max      — максимальная длина в символах
 *  check    — дополнительная проверка: url | timezone
 * Новая настройка = новая запись здесь, форма и сохранение подхватят её сами.
 */
function settings_defs(): array
{
    return [
        'site_name' => [
            'label'    => 'Название сайта',
            'type'     => 'string',
            'section'  => 'main',
            'required' => true,
            'max'      => 255,
            'default'  => '',
        ],
        'site_url' => [
            'label'    => 'Адрес сайта',
            'type'     => 'string',
            'section'  => 'main',
            'required' => true,
            'max'      => 255,
            'check'    => 'url',
            'default'  => detect_site_url(),
        ],
        'timezone' => [
            'label'    => 'Часовой пояс',
            'type'     => 'string',
            'section'  => 'main',
            'required' => true,
            'max'      => 64,
            'check'    => 'timezone',
            'default'  => 'Europe/Moscow',
        ],
        'meta_title' => [
            'label'    => 'Title главной страницы',
            'type'     => 'string',
            'section'  => 'seo',
            'required' => false,
            'max'      => 255,
            'default'  => '',
        ],
        'meta_description' => [
            'label'    => 'Meta description',
            'type'     => 'text',
            'section'  => 'seo',
            'required' => false,
            'max'      => 1000,
            'default'  => '',
        ],
        'meta_keywords' => [
            'label'    => 'Meta keywords',
            'type'     => 'text',
            'section'  => 'seo',
            'required' => false,
            'max'      => 1000,
            'default'  => '',
        ],
    ];
}

/** Адрес сайта по текущему запросу: папка, в которой лежит инсталлятор. */
function detect_site_url(): string
{
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir;
}

/** Значения настроек, уже сохранённые в params (при повторной установке). */
function settings_from_db(PDO $pdo): array
{
    $names = array_keys(settings_defs());
    $marks = implode(',', array_fill(0, count($names), '?'));
    $st = $pdo->prepare('SELECT `name`, `value` FROM `' . tbl('params') . "` WHERE `name` IN ({$marks})");
    $st->execute($names);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

function step_settings_handle(): bool
{
    $defs   = settings_defs();
    $values = [];
    foreach ($defs as $name => $def)
    {
        $v = $_POST[$name] ?? '';
        $values[$name] = is_string($v) ? trim($v) : '';
    }
    $values['site_url'] = rtrim($values['site_url'], '/');

    set_step_data('settings', $values);

    // --- валидация ---
    $errors = [];
    foreach ($defs as $name => $def)
    {
        $v = $values[$name];
        if ($v === '')
        {
            if ($def['required'])
            {
                $errors[] = 'Заполните поле «' . $def['label'] . '».';
            }
            continue;
        }
        if (mb_strlen($v) > $def['max'])
        {
            $errors[] = 'Поле «' . $def['label'] . '» длиннее ' . $def['max'] . ' символов.';
            continue;
        }
        switch ($def['check'] ?? '')
        {
            case 'url':
                if (filter_var($v, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $v))
                {
                    $errors[] = 'Поле «' . $def['label'] . '»: нужен адрес вида https://example.com';
                }
                break;
            case 'timezone':
                if (!in_array($v, DateTimeZone::listIdentifiers(), true))
                {
                    $errors[] = 'Неизвестный часовой пояс «' . $v . '».';
                }
                break;
        }
    }
    if ($errors)
    {
        foreach ($errors as $e)
        {
            flash('error', $e);
        }
        return false;
    }

    // --- запись в params ---
    // Синтаксис «AS new» вместо устаревшего VALUES() в ON DUPLICATE KEY UPDATE (MySQL 8.0.19+)
    $sql = 'INSERT INTO `' . tbl('params') . '` (`name`, `value`, `type`, `section`, `label`, `sort`)
        VALUES (?, ?, ?, ?, ?, ?) AS new
        ON DUPLICATE KEY UPDATE
            `value` = new.`value`, `type` = new.`type`, `section` = new.`section`,
            `label` = new.`label`, `sort` = new.`sort`';

    try
    {
        $pdo = db_from_session();
        $pdo->beginTransaction();
        $st   = $pdo->prepare($sql);
        $sort = 0;
        foreach ($defs as $name => $def)
        {
            $sort += 10;
            $st->execute([$name, $values[$name], $def['type'], $def['section'], $def['label'], $sort]);
        }
        $pdo->commit();
        flash('ok', 'Настройки сохранены.');
    }
    catch (PDOException $e)
    {
        if (isset($pdo) && $pdo->inTransaction())
        {
            $pdo->rollBack();
        }
        flash('error', db_error_text($e));
        return false;
    }

    return true;
}

function step_settings_view(): void
{
    $defs = settings_defs();

    // Приоритет: введённое на шаге → уже сохранённое в базе → значения по умолчанию
    $values = step_data('settings');
    if (!$values)
    {
        try
        {
            $values = settings_from_db(db_from_session());
        }
        catch (PDOException $e)
        {
            echo '<div class="msg error">' . h(db_error_text($e)) . '</div>';
            echo '<p><a href="?step=tables">← Вернуться к таблицам</a></p>';
            return;
        }
    }
    foreach ($defs as $name => $def)
    {
        $values[$name] = $values[$name] ?? $def['default'];
    }

    $sections = ['main' => 'Основное', 'seo' => 'SEO'];
    ?>
    <form method="post" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <?php foreach ($sections as $section => $title): ?>
            <h2><?= h($title) ?></h2>
            <div class="grid">
                <?php foreach ($defs as $name => $def):
                    if ($def['section'] !== $section)
                    {
                        continue;
                    }
                    $label = h($def['label']) . ($def['required'] ? '' : ' <small>(необязательно)</small>');
                    ?>
                    <?php if (($def['check'] ?? '') === 'timezone'): ?>
                        <label><?= $label ?>
                            <select name="<?= h($name) ?>">
                                <?php foreach (DateTimeZone::listIdentifiers() as $tz): ?>
                                    <option <?= $tz === $values[$name] ? 'selected' : '' ?>><?= h($tz) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php elseif ($def['type'] === 'text'): ?>
                        <label class="wide"><?= $label ?>
                            <textarea name="<?= h($name) ?>" rows="3" maxlength="<?= (int)$def['max'] ?>"><?= h($values[$name]) ?></textarea>
                        </label>
                    <?php else: ?>
                        <label class="<?= $name === 'site_name' || $name === 'meta_title' ? 'wide' : '' ?>"><?= $label ?>
                            <input name="<?= h($name) ?>" value="<?= h($values[$name]) ?>" maxlength="<?= (int)$def['max'] ?>"
                                <?= $def['required'] ? 'required' : '' ?>
                                <?= ($def['check'] ?? '') === 'url' ? 'type="url"' : '' ?>>
                        </label>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <div class="actions">
            <button type="submit" name="action" value="save">Сохранить настройки</button>
            <?php if (step_done('settings') && ($n = next_step('settings'))): ?>
                <a class="btn primary" href="?step=<?= h($n) ?>">Далее →</a>
            <?php endif; ?>
        </div>
    </form>
    <?php
}

/* =====================================================================
 *  ФИНАЛ: КОНФИГ И БЛОКИРОВКА
 * ===================================================================== */

const CONFIG_DB_FILE = __DIR__ . DIRECTORY_SEPARATOR . 'config_db.php';

/** Содержимое config_db.php. var_export экранирует кавычки и слеши в значениях. */
function config_db_content(): string
{
    $db = step_data('db') + DB_DEFAULTS;
    $values = [
        'db_host'    => $db['host'],
        'db_port'    => (int)$db['port'],
        'db_name'    => $db['name'],
        'db_user'    => $db['user'],
        'db_pass'    => $db['pass'],
        'db_charset' => DB_CHARSET,
        'db_prefix'  => $db['prefix'],
    ];

    $lines = ['<?php', "\t// Создано инсталлятором " . date('Y-m-d H:i:s')];
    foreach ($values as $key => $value)
    {
        $lines[] = "\t\$config['" . $key . "'] = " . var_export($value, true) . ';';
    }
    return implode("\n", $lines) . "\n";
}

/** Запись через временный файл: при сбое старый конфиг не окажется обрезанным. */
function write_file_atomic(string $path, string $content): bool
{
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $content, LOCK_EX) === false)
    {
        return false;
    }
    if (!@rename($tmp, $path))
    {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Можно ли записать файл: сам файл (если есть) или папку (если нет). */
function path_writable(string $path): bool
{
    return is_file($path) ? is_writable($path) : is_writable(dirname($path));
}

/** Установка завершена в этом запросе — показываем итоговую страницу. */
function install_finished(): bool
{
    return !empty($GLOBALS['INSTALL_FINISHED']);
}

function step_finish_handle(): bool
{
    if (!write_file_atomic(CONFIG_DB_FILE, config_db_content()))
    {
        flash('error', 'Не удалось записать ' . basename(CONFIG_DB_FILE) . '. Проверьте права на запись в папку сайта.');
        return false;
    }
    if (function_exists('opcache_invalidate'))
    {
        @opcache_invalidate(CONFIG_DB_FILE, true);
    }

    if (@file_put_contents(LOCK_FILE, 'Installed ' . date('Y-m-d H:i:s') . "\n") === false)
    {
        flash('error', 'Конфиг записан, но не удалось создать ' . basename(LOCK_FILE) . '.');
        return false;
    }

    // Итог для страницы, которую покажем один раз после блокировки
    $_SESSION['installer_finished'] = [
        'admin'    => step_data('admin')['login'] ?? '',
        'site_url' => step_data('settings')['site_url'] ?? './',
    ];
    return true;
}

function step_finish_view(): void
{
    if (install_finished())
    {
        $summary = $_SESSION['installer_finished'];
        ?>
        <div class="msg ok">Установка завершена.</div>
        <p>Файл <code><?= h(basename(CONFIG_DB_FILE)) ?></code> записан, установщик заблокирован файлом <code><?= h(basename(LOCK_FILE)) ?></code>.</p>
        <p>Вход в админку: логин <b><?= h($summary['admin']) ?></b>.</p>
        <p><b>Удалите <code><?= h(basename(__FILE__)) ?></code> с сервера.</b></p>
        <div class="actions">
            <a class="btn primary" href="<?= h($summary['site_url']) ?>">Открыть сайт</a>
        </div>
        <?php
        return;
    }

    $db       = step_data('db');
    $settings = step_data('settings');
    $writable = path_writable(CONFIG_DB_FILE) && path_writable(LOCK_FILE);
    ?>
    <p>Все шаги пройдены. Осталось записать конфиг и заблокировать установщик.</p>
    <table class="list">
        <tr><td>Сайт</td><td><?= h($settings['site_name'] ?? '') ?> — <?= h($settings['site_url'] ?? '') ?></td></tr>
        <tr><td>База данных</td><td><?= h($db['user'] ?? '') ?>@<?= h($db['host'] ?? '') ?>:<?= h($db['port'] ?? '') ?>/<?= h($db['name'] ?? '') ?></td></tr>
        <tr><td>Префикс таблиц</td><td><?= h(($db['prefix'] ?? '') !== '' ? $db['prefix'] : '—') ?></td></tr>
        <tr><td>Администратор</td><td><?= h(step_data('admin')['login'] ?? '') ?></td></tr>
        <tr><td>Конфиг</td><td><code><?= h(CONFIG_DB_FILE) ?></code></td></tr>
    </table>

    <?php if (is_file(CONFIG_DB_FILE)): ?>
        <div class="msg error">Файл <?= h(basename(CONFIG_DB_FILE)) ?> уже существует и будет перезаписан.</div>
    <?php endif; ?>

    <?php if (!$writable): ?>
        <div class="msg error">Нет прав на запись в папку сайта. Дайте права на запись и нажмите кнопку ещё раз.</div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <div class="actions">
            <button type="submit" name="action" value="finish" class="btn primary">Завершить установку</button>
        </div>
    </form>
    <?php
}

/* =====================================================================
 *  РОУТИНГ
 * ===================================================================== */

$INSTALL_FINISHED = false;

if (is_file(LOCK_FILE))
{
    if (empty($_SESSION['installer_finished']))
    {
        http_response_code(403);
        exit('Сайт уже установлен. Чтобы запустить установку заново, удалите файл install.lock.');
    }
    // Сразу после завершения: один раз показываем итоговую страницу
    $INSTALL_FINISHED = true;
    $_GET['step'] = 'finish';
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

if (isset($_GET['reset']))
{
    unset($_SESSION['installer'], $_SESSION['installer_flash']);
    redirect_to_step(step_keys()[0]);
}

$step = (string)($_GET['step'] ?? '');
if (!isset($STEPS[$step]) || !step_available($step))
{
    $step = first_pending_step();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    if (!csrf_check())
    {
        flash('error', 'Сессия устарела, отправьте форму ещё раз.');
        redirect_to_step($step);
    }
    $handler = $STEPS[$step]['handle'];
    if ($handler)
    {
        $ok = $handler();
        mark_step($step, $ok);
    }
    redirect_to_step($step);   // PRG: после POST всегда GET
}

$flash = take_flash();
$keys  = step_keys();
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Установка сайта — <?= h($STEPS[$step]['title']) ?></title>
<style>
    :root { --bg:#f4f5f7; --card:#fff; --text:#1f2328; --muted:#6b7280; --line:#e2e5ea;
            --accent:#2563eb; --ok:#15803d; --ok-bg:#ecfdf3; --err:#b42318; --err-bg:#fef3f2; }
    * { box-sizing: border-box; }
    body { margin:0; font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--text); }
    .wrap { max-width:760px; margin:40px auto; padding:0 16px; }
    h1 { font-size:22px; margin:0 0 16px; }
    .steps { display:flex; gap:4px; margin-bottom:-1px; flex-wrap:wrap; }
    .steps a, .steps span { padding:10px 16px; border:1px solid var(--line); border-bottom:none;
            border-radius:8px 8px 0 0; background:#eceef2; color:var(--muted); text-decoration:none; }
    .steps a:hover { color:var(--text); }
    .steps .current { background:var(--card); color:var(--text); font-weight:600; }
    .steps .locked { opacity:.5; cursor:not-allowed; }
    .steps .done::before { content:"✓ "; color:var(--ok); }
    .card { background:var(--card); border:1px solid var(--line); border-radius:0 8px 8px 8px; padding:24px; }
    .grid { display:grid; grid-template-columns:1fr 1fr; gap:14px 18px; }
    @media (max-width:560px) { .grid { grid-template-columns:1fr; } }
    label { display:flex; flex-direction:column; gap:4px; font-weight:500; }
    label small { color:var(--muted); font-weight:400; }
    input, select, textarea { font:inherit; padding:8px 10px; border:1px solid var(--line); border-radius:6px; }
    input:focus, select:focus, textarea:focus { outline:2px solid var(--accent); outline-offset:-1px; }
    textarea { resize:vertical; }
    .grid .wide { grid-column:1 / -1; }
    h2 { font-size:16px; margin:22px 0 12px; }
    h2:first-of-type { margin-top:0; }
    .check { flex-direction:row; align-items:center; gap:8px; margin-top:14px; font-weight:400; }
    .actions { display:flex; gap:10px; margin-top:20px; align-items:center; }
    button, .btn { font:inherit; padding:9px 18px; border-radius:6px; border:1px solid var(--line);
            background:#fff; cursor:pointer; text-decoration:none; color:var(--text); }
    button:hover, .btn:hover { border-color:var(--accent); }
    .btn.primary { background:var(--accent); border-color:var(--accent); color:#fff; }
    .msg { padding:10px 14px; border-radius:6px; margin-bottom:10px; word-break:break-word; }
    .msg.ok { background:var(--ok-bg); color:var(--ok); }
    .msg.error { background:var(--err-bg); color:var(--err); }
    .muted { color:var(--muted); }
    .ok { color:var(--ok); }
    .err { color:var(--err); }
    table.list { width:100%; border-collapse:collapse; margin-bottom:16px; }
    table.list th, table.list td { text-align:left; padding:8px 10px; border-bottom:1px solid var(--line); }
    table.list th { font-weight:600; color:var(--muted); font-size:13px; }
    code { font:13px/1.4 ui-monospace,Consolas,monospace; }
    footer { margin-top:14px; font-size:13px; color:var(--muted); display:flex; justify-content:space-between; }
    footer a { color:var(--muted); }
</style>
</head>
<body>
<div class="wrap">
    <h1>Установка сайта</h1>

    <nav class="steps">
        <?php foreach ($keys as $i => $k):
            $cls   = [];
            if ($k === $step)      $cls[] = 'current';
            if (step_done($k))     $cls[] = 'done';
            $label = ($i + 1) . '. ' . h($STEPS[$k]['title']);
            if (step_available($k)): ?>
                <a class="<?= implode(' ', $cls) ?>" href="?step=<?= h($k) ?>"><?= $label ?></a>
            <?php else: ?>
                <span class="locked"><?= $label ?></span>
            <?php endif;
        endforeach; ?>
    </nav>

    <div class="card">
        <?php foreach ($flash as $f): ?>
            <div class="msg <?= h($f['type']) ?>"><?= h($f['text']) ?></div>
        <?php endforeach; ?>

        <?php ($STEPS[$step]['view'])(); ?>
    </div>

    <footer>
        <span>Инсталлятор v<?= h(INSTALLER_VERSION) ?> · PHP <?= h(PHP_VERSION) ?></span>
        <?php if (!install_finished()): ?>
            <a href="?reset=1" onclick="return confirm('Сбросить все введённые данные?')">Начать заново</a>
        <?php endif; ?>
    </footer>
</div>
</body>
</html>
<?php
// После показа итоговой страницы стираем из сессии все данные установки (в том числе пароль БД)
if (install_finished())
{
    unset($_SESSION['installer'], $_SESSION['installer_finished'], $_SESSION['installer_flash'], $_SESSION['installer_csrf']);
}