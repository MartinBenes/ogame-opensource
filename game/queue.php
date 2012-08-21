<?php

/*

Глобальная очередь событий.

Главный модуль для управления временным потоком игры.
Временной поток состоит из отрезков времени, между двумя событиями, которые влияют на состояние аккаунтов игроков.
События всех игроков выстраиваются в общую очередь. Очередь дискретна - каждое событие синхронизировано посекундно.
Проверка на завершение события (движение очереди) осуществляется когда игроки совершаются какие-либо действия (переходят по страницам).
Если два события попадают на одну секунду, то они обрабатываются в порядке приоритета (например если Атака совпадает по времени с Переработать,
на тех же координатах, то вначале обрабатывается Атака, а потом Переработать).  

Каждое событие имеет начало (время запуска) и конец (время завершения события). Некоторые события можно отменить. Отмена некоторых событий порождает 
другие события (например отмена задания флота порождает новое задание возврата флота).

Главные типы событий аккаунта:
 - Счётчики времени на аккаунте игрока (действие офицеров, удаление аккаунта итп.)
 - Строительство на планете/луне
 - Исследование
 - Строительство на верфи
 - Задания для флота и МПР
 - Глобальные события для всех игроков (релогин, чистка виртуального лома, удаление уничтоженных планет, пересчёт очков 3 раза в сутки итп.)

Запись старых очков: 8:05, 16:05, 20:05 по серверу

Статичный пересчёт очков игрока : 0:10 по серверу

Виртуальное ПО исчезает в понедельник в 1:10 по серверу, если на/от него не летит ни одного флота и если там 0 ресурсов.

Запись задания в таблице БД:
task_id: уникальный номер задания (INT AUTO_INCREMENT PRIMARY KEY)
owner_id: номер пользователя которому принадлежит задание  (INT)
type: тип задания, каждый тип имеет свой обработчик: (CHAR(20))
    "CommanderOff"   -- заканчивается офицер: Командир
    "AdmiralOff"     -- заканчивается офицер: Адмирал
    "EngineerOff"    -- заканчивается офицер: Инженер
    "GeologeOff"     -- заканчивается офицер: Геолог
    "TechnocrateOff" -- заканчивается офицер: Технократ
    "UnbanPlayer"    -- разбанить игрока
    "ChangeEmail"    -- записать постоянный почтовый адрес
    "AllowName"    -- разрешить смену имени игрока
    "AllowAttacks"    -- отменить запрет атак у игрока
    "UnloadAll"      -- сделать релогин всех игроков
    "CleanDebris"    -- чистка виртуальных полей обломков
    "CleanPlanets"   -- удаление уничтоженных планет / покинутых лун
    "CleanPlayers"   -- удаление неактивных игроков и поставленных на удаление (1:10)
    "UpdateStats"    -- сохранение старых очков статистики
    "RecalcPoints"    -- пересчёт статистики игроков
    "RecalcAllyPoints" -- пересчёт статистики альянсов
    "Build"          -- постройка на планете (sub_id - номер планеты, obj_id - тип постройки)
    "Demolish"       -- снос на планете (sub_id - номер планеты, obj_id - тип постройки)
    "Research"       -- исследование (sub_id - номер планеты где было запущено исследование, obj_id - тип исследования)
    "Shipyard"       -- задание для верфи (sub_id - номер планеты, obj_id - тип постройки)
    "Fleet"            -- Задание флота / Атака МПР (sub_id - номер записи в таблице флота)
    "DecRes"         -- Списать ресурсы на планете (sub_id - номер задания постройки для определения количества ресурсов)
    "Debug"          -- отладочное событие
    "AI"              -- задания для бота
sub_id: дополнительный номер, разный у каждого типа задания, например для постройки - ID планеты, для задания флота - ID флота (INT)
obj_id: дополнительный номер, разный у каждого типа задания, например для постройки - ID здания (INT)
level: уровень постройки / количество заказанных единиц на верфи (INT)
start: время начала задания (INT UNSIGNED)
end: время окончания задания (INT UNSIGNED)
prio: приоритет события, используется для событий, которые заканчиваются в одно и тоже время, чем выше приоритет, тем раньше выполнится событие (INT)

Примеры запуска заданий: 
Постройка шахты металла (3): AddQueue (player_id, "Build", planet_id, 1, 3, 241)
Заказ ракетная установка (25): AddQueue (player_id, "Shipyard", planet_id, 401, 25, 14400)

Как происходит обновление очереди:
После очередного клика одного из юзеров проверяется каждое задание очереди на завершение. Если задание завершено - вызывается его обработчик и задание
удаляется из очереди.

*/

// Добавить задание в очередь. Возвращает ID добавленного задания.
function AddQueue ($owner_id, $type, $sub_id, $obj_id, $level, $now, $seconds, $prio=0)
{
    $queue = array ( null, $owner_id, $type, $sub_id, $obj_id, $level, $now, $now+$seconds, $prio );
    $id = AddDBRow ( $queue, "queue" );
    return $id;
}

// Загрузить задание.
function LoadQueue ($task_id)
{
    global $db_prefix;
    $query = "SELECT * FROM ".$db_prefix."queue WHERE task_id = $task_id";
    $result = dbquery ($query);
    return dbarray ($result);
}

// Удалить задание из очереди. cb=1: вызвать обработчик отмененного задания, cb=0: не вызывать.
function RemoveQueue ($task_id, $cb)
{
    global $db_prefix;

    // Выполнить обработчик отмены задания.
    if ($cb)
    {
        $queue = LoadQueue ($task_id);
        if ($queue['type'] === "Build" || $queue['type'] === "Demolish") Queue_Build_Cancel ($queue);
    }

    $query = "DELETE FROM ".$db_prefix."queue WHERE task_id = $task_id";
    dbquery ($query);
}

// Продлить задание ещё на указанное количество секунд
function ProlongQueue ($task_id, $seconds)
{
    global $db_prefix;
    $query = "UPDATE ".$db_prefix."queue SET end = end + $seconds WHERE task_id = $task_id";
    dbquery ($query);
}

// Проверить задания очереди на завершение до момента времени $until.
function UpdateQueue ($until)
{
    global $db_prefix;
    global $GlobalUni;

    $uni = $GlobalUni;
    if ( $uni['freeze'] ) return;

    LockTables ();

    $query = "SELECT * FROM ".$db_prefix."queue WHERE end <= $until ORDER BY end ASC, prio DESC";
    $result = dbquery ($query);

    $rows = dbrows ($result);
    while ($rows--) {
        $queue = dbarray ($result);

        if ( !SpecialEvent ( $queue ) )
        {
            if ( $queue['type'] === "Build" ) Queue_Build_End ($queue);
            else if ( $queue['type'] === "Demolish" ) Queue_Build_End ($queue);
            else if ( $queue['type'] === "DecRes" ) Queue_DecRes_End ($queue);
            else if ( $queue['type'] === "Research" ) Queue_Research_End ($queue);
            else if ( $queue['type'] === "Shipyard" ) Queue_Shipyard_End ($queue);
            else if ( $queue['type'] === "Fleet" ) Queue_Fleet_End ($queue);
            else if ( $queue['type'] === "UnloadAll" ) Queue_Relogin_End ($queue);
            else if ( $queue['type'] === "CleanDebris" ) Queue_CleanDebris_End ($queue);
            else if ( $queue['type'] === "CleanPlanets" ) Queue_CleanPlanets_End ($queue);
            else if ( $queue['type'] === "CleanPlayers" ) Queue_CleanPlayers_End ($queue);
            else if ( $queue['type'] === "UpdateStats" ) Queue_UpdateStats_End ($queue);
            else if ( $queue['type'] === "RecalcPoints" ) Queue_RecalcPoints_End ($queue);
            else if ( $queue['type'] === "RecalcAllyPoints" ) Queue_RecalcAllyPoints_End ($queue);
            else if ( $queue['type'] === "AllowName" ) Queue_AllowName_End ($queue);
            else if ( $queue['type'] === "UnbanPlayer" ) Queue_UnbanPlayer_End ($queue);
            else if ( $queue['type'] === "AllowAttacks" ) Queue_AllowAttacks_End ($queue);
            else if ( $queue['type'] === "Debug" ) Queue_Debug_End ($queue);
            else if ( $queue['type'] === "AI" ) Queue_Bot_End ($queue);

            else if ( $queue['type'] === "CommanderOff" ) Queue_Officer_End ($queue);
            else if ( $queue['type'] === "AdmiralOff" ) Queue_Officer_End ($queue);
            else if ( $queue['type'] === "EngineerOff" ) Queue_Officer_End ($queue);
            else if ( $queue['type'] === "GeologeOff" ) Queue_Officer_End ($queue);
            else if ( $queue['type'] === "TechnocrateOff" ) Queue_Officer_End ($queue);

            else Error ( "queue: Неизвестный тип задания для глобальной очереди: " . $queue['type']);
        }
    }

    UnlockTables ();
}

// ===============================================================================================================
// Постройки

// Получить прикрепленное задание списывания ресурсов.
function HasDecRes ($queue)
{
    global $db_prefix;

    $task_id = $queue['task_id'];

    $query = "SELECT * FROM ".$db_prefix."queue WHERE type = 'DecRes' AND sub_id = $task_id";
    $result = dbquery ($query);
    $decres = dbarray ($result);
    return ($decres==null) ? 0:1;
}

// Добавить новую постройку/снос в очередь
function BuildEnque ( $planet_id, $id, $destroy, $now=0 )
{
    global $db_prefix, $GlobalUni;

    $buildmap = array ( 1, 2, 3, 4, 12, 14, 15, 21, 22, 23, 24, 31, 33, 34, 41, 42, 43, 44 );
    if ( ! in_array ( $id, $buildmap ) ) return;

    $planet = GetPlanet ( $planet_id );
    $user = LoadUser ( $planet['owner_id'] );

    $prem = PremiumStatus ($user);
    //if ($prem['commander']) $maxcnt = 5;
    //else $maxcnt = 1;
    $maxcnt = 1;

    $uni = $GlobalUni;
    if ( $uni['freeze'] ) return;

    $result = GetBuildQueue ( $planet_id );
    $cnt = dbrows ( $result );
    if ( $cnt >= $maxcnt ) return;    // Очередь построек заполнена

    // В режиме отпуска нельзя строить
    if ( $user['vacation'] ) return;

    // Терраформер и Лунную базу нельзя снести.
    if ( $destroy && ($id == 33 || $id == 41) && $cnt == 0 ) return;

    // Загрузить очередь. Отсортирована по времени начала событий.
    $queue = array ();
    for ($i=0; $i<$cnt; $i++)
    {
        $queue[$i] = dbarray ($result);
    }

    // Определить добавляемый уровень.
    $nowlevel = $planet['b'.$id];
    for ($i=0; $i<$cnt; $i++)
    {
        if ( $queue[$i]['obj_id'] == $id ) $nowlevel = $queue[$i]['level'];
    }

    if ($destroy) {
        $lvl = $nowlevel - 1;
        if ($lvl < 0) return;        // Невозможно снести несуществующую постройку.
    }
    else $lvl = $nowlevel + 1;

    $unitab = $GlobalUni;
    $speed = $unitab['speed'];

    // Только первая постройка.
    if ($cnt == 0)
    {
        // На чужой планете строить нельзя
        if ( $planet['owner_id'] != $user['player_id'] ) return;

        // Лунные постройки нельзя строить на планете, а планетарные на луне
        if ( $planet['type'] != 0 && ($id == 41 || $id == 42 || $id == 43) ) return;
        if ( $planet['type'] == 0 && ( $id == 1 || $id == 2 || $id == 3 || $id == 4 || $id == 12 || $id == 15 || $id == 22 || $id == 23 || $id == 24 || $id == 31 || $id == 33 || $id == 44 ) ) return;

        // Проверить количество полей
        if ( $planet['fields'] >= $planet['maxfields'] && !$destroy ) return;

        $result = GetResearchQueue ( $user['player_id'] );
        $resqueue = dbarray ($result);
        $reslab_operating = ($resqueue != null);
        $result = GetShipyardQueue ( $planet['planet_id'] );
        $shipqueue = dbarray ($result);
        $shipyard_operating = ($shipqueue != null);

        // Идет исследование или строительство на верфи
        if ( $id == 31 && $reslab_operating ) return;
        if ( ($id == 15 || $id == 21) && $shipyard_operating ) return;

        // Проверить доступное количество ресурсов на планете
        $m = $k = $d = $e = 0;
        BuildPrice ( $id, $lvl, &$m, &$k, &$d, &$e );
        if ( !IsEnoughResources ( $planet, $m, $k, $d, $e ) ) return;

        // Проверить доступные технологии.
        if ( !BuildMeetRequirement ( $user, $planet, $id ) ) return;

        if ( $now == 0 ) $now = time ();

        // Списать ресурсы.
        AdjustResources ( $m, $k, $d, $planet_id, '-' );

        // Добавить в очередь
        $type = $destroy ? "Demolish" : "Build";
        AddQueue ( $user['player_id'], $type, $planet_id, $id, $lvl, $now, floor (BuildDuration ( $id, $lvl, $planet['b14'], $planet['b15'], $speed )), 1 );
    }
    else
    {
        // При добавлении постройки в очередь все условия проверяются в задании списывания ресурсов.

        // Время начала = время окончания последней постройки в очереди.
        $now = $queue[$cnt-1]['end'];

        // Добавить в очередь
        $type = $destroy ? "Demolish" : "Build";
        $qid = AddQueue ( $user['player_id'], $type, $planet_id, $id, $lvl, $now, floor (BuildDuration ( $id, $lvl, $planet['b14'], $planet['b15'], $speed )), 1 );

        // Добавить событие списывания ресов (время окончания = время начала добавляемого задания).
        // Приоритет этого задания ниже, поэтому оно выполняется ДО задания строительства.
        $q = LoadQueue ($qid);
        AddQueue ( $user['player_id'], "DecRes", $q['task_id'], 0, 0, $q['start'], 0 );
    }
}

// Отменить постройку/снос
function BuildDeque ( $planet_id, $listid )
{
    global $db_prefix, $GlobalUni;

    $uni = $GlobalUni;
    if ( $uni['freeze'] ) return;

    // Загрузить очередь. Отсортирована по времени начала событий.
    $result = GetBuildQueue ( $planet_id );
    $cnt = dbrows ( $result );
    $queue = array ();
    for ($i=0; $i<$cnt; $i++)
    {
        $queue[$i] = dbarray ($result);
    }

    $listid--;
    if ( $listid < 0 || $listid >= $cnt ) return;    // Невозможно удалить несуществующую очередь.

    RemoveQueue ( $queue[$listid]['task_id'], 1 );
}

// Получить очередь строительства для планеты.
function GetBuildQueue ( $planet_id)
{
    global $db_prefix;
    $query = "SELECT * FROM ".$db_prefix."queue WHERE (type = 'Build' OR type = 'Demolish') AND sub_id = $planet_id ORDER BY start ASC";
    return dbquery ($query);
}

// Завершить снос/строительство постройки.
// Все проверки осуществляются в прикрепленном задании списывания ресурсов.
function Queue_Build_End ($queue)
{
    global $db_prefix, $GlobalUser;

    $id = $queue['obj_id'];
    $lvl = $queue['level'];
    $planet_id = $queue['sub_id'];

    // Рассчитать производство планеты с момента последнего обновления.
    $planet = GetPlanet ( $planet_id );
    $player_id = $planet['owner_id'];
    ProdResources ( &$planet, $planet['lastpeek'], $queue['end'] );

    // Защита от дурака
    if ( $queue['type'] === "Build" && $planet["b".$id] >= $lvl ) { RemoveQueue ( $queue['task_id'], 0 ); return; }
    if ( $queue['type'] === "Demolish" && $planet["b".$id] <= $lvl ) { RemoveQueue ( $queue['task_id'], 0 ); return; }

    // Количество полей на планете.
    if ($queue['type'] === "Build" )
    {
        $fields = "fields = fields + 1";
        // Специальная обработка для постройки Терраформера или Лунной базы -- добавить максимальное количество полей.
        if ( $id == 33 ) $fields .= ", maxfields = maxfields + 5";
        if ( $id == 41 ) $fields .= ", maxfields = maxfields + 3";
    }
    else $fields = "fields = fields - 1";

    // Обновить уровень постройки и количество полей в базе данных.
    $query = "UPDATE ".$db_prefix."planets SET ".('b'.$id)." = $lvl, $fields WHERE planet_id = $planet_id";
    dbquery ($query);

    RemoveQueue ( $queue['task_id'], 0 );

    //if ($queue['type'] === "Build" ) Debug ( "Строительство ".loca("NAME_$id")." уровня $lvl на планете $planet_id завершено." );
    //else Debug ( "Снос ".loca("NAME_$id")." уровня $lvl на планете $planet_id завершен." );

    // Добавить очки. Места пересчитывать только для крупных построек.
    $m = $k = $d = $e = 0;
    if ( $queue['type'] === "Build" ) {
        BuildPrice ( $id, $lvl, &$m, &$k, &$d, &$e );
        $points = $m + $k + $d;
        AdjustStats ( $queue['owner_id'], $points, 0, 0, '+');
    }
    else {
        BuildPrice ( $id, $lvl+1, &$m, &$k, &$d, &$e );
        $points = $m + $k + $d;
        AdjustStats ( $queue['owner_id'], $points, 0, 0, '-');
    }
    if ( $lvl > 10 ) RecalcRanks ();

    if ( $GlobalUser['player_id'] == $player_id) {
        InvalidateUserCache ();
        $GlobalUser = LoadUser ( $player_id );    // обновить данные текущего пользователя
    }
}

// Отменить снос/строительство постройки.
function Queue_Build_Cancel ($queue)
{
    global $db_prefix;

    $id = $queue['obj_id'];
    $lvl = $queue['level'];
    $planet_id = $queue['sub_id'];
    $planet = GetPlanet ( $planet_id );
    $current_level = $planet["b".$id];

    // Вернуть ресурсы, если это необходимо.
    if ( !HasDecRes ($queue) )
    {
        $m = $k = $d = $e = 0;
        BuildPrice ( $id, $lvl, &$m, &$k, &$d, &$e );
        AdjustResources ( $m, $k, $d, $planet_id, '+' );
    }

    // Корректируем уровень других построек такого же типа.
    $query = "SELECT * FROM ".$db_prefix."queue WHERE (type = 'Build' OR type = 'Demolish') AND sub_id = $planet_id AND start > ".$queue['start']." ORDER BY start ASC";
    $result = dbquery ($query);
    $cnt = dbrows ( $result );
    $bqueue = array ();
    for ($i=0; $i<$cnt; $i++) $bqueue[$i] = dbarray ($result);
    RemoveQueue ( $queue['task_id'], 0 );
    for ($i=0; $i<$cnt; $i++) RemoveQueue ( $bqueue[$i]['task_id'], 0 );
    $now = time ();
    for ($i=0; $i<$cnt; $i++) BuildEnque ( $planet_id, $bqueue[$i]['obj_id'], $bqueue[$i]['type'] === 'Demolish' ? 1 : 0, $now++ );
}

// Списать ресурсы. Если ресурсов недостаточно или не выполнены условия - отменить задание строительства.
function Queue_DecRes_End ($queue)
{
    global $db_prefix;

    $q = LoadQueue ($queue['sub_id']);
    if ($q == null)
    {
        RemoveQueue ( $queue['task_id'], 0);    // Если прикрепленной постройки уже нет - просто удалить задание.
        return;
    }

    $id = $q['obj_id'];
    $lvl = $q['level'];
    $planet_id = $q['sub_id'];

    $m = $k = $d = $e = 0;
    BuildPrice ( $id, $lvl, &$m, &$k, &$d, &$e );

    $planet = GetPlanet ($planet_id);
    $user = LoadUser ($planet['owner_id']);

    // Проверить все условия возможности постройки/сноса
    $text = '';
    {
        $buildmap = array ( 1, 2, 3, 4, 12, 14, 15, 21, 22, 23, 24, 31, 33, 34, 41, 42, 43, 44 );

        $result = GetResearchQueue ( $user['player_id'] );
        $resqueue = dbarray ($result);
        $reslab_operating = ($resqueue != null);
        $result = GetShipyardQueue ( $planet['planet_id'] );
        $shipqueue = dbarray ($result);
        $shipyard_operating = ($shipqueue != null);

        $m = $k = $d = $e = 0;
        BuildPrice ( $id, $lvl, &$m, &$k, &$d, &$e );

        // Не постройка
        if ( ! in_array ( $id, $buildmap ) ) $text = "Неверный ID!";

        // В режиме отпуска нельзя строить
        else if ( $user['vacation'] ) $text = "В режиме отпуска (РО) строительство невозможно.";

        // На чужой планете строить нельзя
        else if ( $planet['owner_id'] != $user['player_id'] ) $text = "Неправильная планета!";

        // Лунные постройки нельзя строить на планете, а планетарные на луне
        else if ( $planet['type'] != 0 && ($id == 41 || $id == 42 || $id == 43) ) $text = "Неверный тип планеты.";
        else if ( $planet['type'] == 0 && ( $id == 1 || $id == 2 || $id == 3 || $id == 4 || $id == 12 || $id == 15 || $id == 22 || $id == 23 || $id == 24 || $id == 31 || $id == 33 || $id == 44 ) ) $text = "Неверный тип планеты.";

        // Проверить количество полей
        else if ( $planet['fields'] >= $planet['maxfields'] && $q['type'] === 'Build' ) $text = "На планете нет места для строительства.";

        // Идет исследование или строительство на верфи
        else if ( $id == 31 && $reslab_operating ) $text = "Идёт исследование!";
        else if ( ($id == 15 || $id == 21) && $shipyard_operating ) $text = "Корабельная верфь ещё занята.";

        // Проверить доступное количество ресурсов на планете
        else if ( !IsEnoughResources ( $planet, $m, $k, $d, $e ) ) $text = "У Вас недостаточно ресурсов!";

        // Проверить доступные технологии.
        else if ( !BuildMeetRequirement ( $user, $planet, $id ) ) $text = "Необходимые требования не выполнены!";
    }

    if ( $q['type'] === 'Demolish' )
    {
        if ( $id == 33 || $id == 41 ) $text = "Лунную базу и терраформер нельзя снести.";
        else if ( $planet["b".$id] <= 0 ) $text = "У Вас нет построек этого типа.";
    }

    if ( $text === '' )
    {
        // Списать ресурсы.
        AdjustResources ( $m, $k, $d, $planet_id, '-' );
    }
    else 
    {
        if ( $q['type'] === 'Build' ) $pre = 'Заказ на строительство';
        else if ( $q['type'] === 'Demolish' ) $pre = 'Заказ на снос';
        $pre = va ( "#1 для Вашей постройки #2 #3-го уровня на #4 выполнить не удалось.", $pre, loca ("NAME_$id"), $lvl, $planet['name'] . " <a href=\"javascript:showGalaxy(".$planet['g'].",".$planet['s'].",".$planet['p'].")\" >[".$planet['g'].":".$planet['s'].":".$planet['p']."]</a>" );
        SendMessage ( $user['player_id'], 'Системное сообщение', 'Производство отменено', $pre . "<br><br>" . $text, 5, $queue['end'] );

        RemoveQueue ( $queue['sub_id'], 0 );  // отменить строительство, недостаточно ресурсов или не выполнены условия.
    }

    RemoveQueue ( $queue['task_id'], 0 );    // убрать списывание ресов
}

// ===============================================================================================================
// Верфь

// Получить очередь заданий на верфи.
function GetShipyardQueue ($planet_id)
{
    global $db_prefix;
    $query = "SELECT * FROM ".$db_prefix."queue WHERE type = 'Shipyard' AND sub_id = $planet_id ORDER BY start ASC";
    return dbquery ($query);
}

// Получить время окончания последнего задания на верфи, используется чтобы узнать время начала нового задания.
function ShipyardLatestTime ($planet_id, $now)
{
    global $db_prefix;

    $query = "SELECT * FROM ".$db_prefix."queue WHERE type = 'Shipyard' AND sub_id = $planet_id ORDER BY end DESC";
    $result = dbquery ($query);
    if (dbrows($result) > 0) {
        $queue = dbarray ($result);
        return $queue['end'] + ($queue['end'] - $queue['start']) * ($queue['level'] - 1);
    }
    else {
        if ($now == 0) $now = time ();
        return $now;
    }
}

// Добавить флот/оборону на верфь ($gid - тип юнита, $value - количество)
function AddShipyard ($player_id, $planet_id, $gid, $value, $now=0 )
{
    global $db_prefix, $GlobalUni;

    $techmap = array ( 202, 203, 204, 205, 206, 207, 208, 209, 210, 211, 212, 213, 214, 215, 401, 402, 403, 404, 405, 406, 407, 408, 502, 503 );
    if ( ! in_array ( $gid, $techmap ) ) return;

    $uni = $GlobalUni;
    if ( $uni['freeze'] ) return;

    // Щитовые купола можно строить не более 1 единицы.
    if ( ($gid == 407 || $gid == 408) && $value > 1 ) $value = 1;

    $planet = GetPlanet ( $planet_id );

    // Если в очереди уже строится купол такого же типа, то не добавлять ещё один купол в очередь.
    // Ограничить количество заказанных ракет уже строящимися
    $result = GetShipyardQueue ($planet_id);
    $tasknum = dbrows ($result);
    $rak_space = $planet['b44'] * 10 - ($planet['d502'] + 2 * $planet['d503']);
    while ($tasknum--)
    {
        $queue = dbarray ( $result );
        if ( $queue['obj_id'] == 407 || $queue['obj_id'] == 408 )
        {
            if ( $queue['obj_id'] == $gid ) return;    // в очереди строится купол такого же типа.
        }
        if ( $queue['obj_id'] == 502 || $queue['obj_id'] == 503 )
        {
            if ( $queue['obj_id'] == 502 ) $rak_space -= $queue['level'];
            else $rak_space -= 2 * $queue['level'];
        }
    }

    if ( $gid == 502 ) $value = min ( $rak_space, $value );
    if ( $gid == 503 ) $value = min ( floor ($rak_space / 2), $value );
    if ( $value <= 0 ) return;

    $user = LoadUser ( $player_id );

    $m = $k = $d = $e = 0;
    ShipyardPrice ( $gid, &$m, &$k, &$d, &$e );
    $m *= $value;
    $k *= $value;
    $d *= $value;

    if ( IsEnoughResources ( $planet, $m, $k, $d, $e ) && ShipyardMeetRequirement ($user, $planet, $gid) ) {
        $speed = $uni['speed'];
        $now = ShipyardLatestTime ($planet_id, $now);
        $shipyard = $planet["b21"];
        $nanits = $planet["b15"];
        $seconds = ShipyardDuration ( $gid, $shipyard, $nanits, $speed );

        // Списать ресурсы.
        AdjustResources ( $m, $k, $d, $planet_id, '-' );

        AddQueue ($player_id, "Shipyard", $planet_id, $gid, $value, $now, $seconds);
        Debug ("Запустить постройку ".loca("NAME_$gid")." ($value) на планете [".$planet['g'].":".$planet['s'].":".$planet['p']."] ".$planet['name'] . ", длительность $seconds сек." );
    }
}

// Закончить постройку на верфи.
function Queue_Shipyard_End ($queue)
{
    global $db_prefix, $GlobalUser;

    $now = time ();
    $gid = $queue['obj_id'];
    $planet_id = $queue['sub_id'];
    $planet = GetPlanet ($planet_id);
    $player_id = $planet['owner_id'];

    // Старые значения
    $s = $queue['start'];
    $e = $queue['end'];
    $n = $queue['level'];
    $one = $e - $s;

    // Новые значения
    $done =  min ($n, floor ( ($now - $s) / $one ));
    $news = $s + $done * $one;
    $newe = $e + $done * $one;

    // Добавить флот на планете
    if ($gid > 400) $query = "UPDATE ".$db_prefix."planets SET d$gid = d$gid + $done WHERE planet_id = $planet_id";
    else $query = "UPDATE ".$db_prefix."planets SET f$gid = f$gid + $done WHERE planet_id = $planet_id";
    dbquery ($query);

    // Добавить очки.
    $m = $k = $d = $enrg = 0;
    ShipyardPrice ( $gid, &$m, &$k, &$d, &$enrg );
    $points = ($m + $k + $d) * $done;
    if ($gid < 400) $fpoints = $done;
    else $fpoints = 0;
    AdjustStats ( $queue['owner_id'], $points, $fpoints, 0, '+');

    // Обновить задание или удалить его, если всё построено.
    if ( $done < $n )
    {
        $query = "UPDATE ".$db_prefix."queue SET start = $news, end = $newe, level = level - $done WHERE task_id = ".$queue['task_id'];
        dbquery ($query);
        //Debug ( "На верфи [".$planet['g'].":".$planet['s'].":".$planet['p']."] ".$planet['name']." построено ".loca("NAME_$gid")." ($done), осталось достроить (".($n-$done).")" );
        if ( $one > 60 ) RecalcRanks ();
    }
    else {
        //Debug ( "На верфи [".$planet['g'].":".$planet['s'].":".$planet['p']."] ".$planet['name']." завершена постройка ".loca("NAME_$gid")." ($done)" );
        RemoveQueue ( $queue['task_id'], 0 );
        RecalcRanks ();
    }

    if ( $GlobalUser['player_id'] == $player_id) {
        InvalidateUserCache ();
        $GlobalUser = LoadUser ( $player_id );    // обновить данные текущего пользователя
    }
}

// ===============================================================================================================
// Исследования

// Начать исследование на планете (включает в себя все проверки).
function StartResearch ($player_id, $planet_id, $id, $now)
{
    global $db_prefix, $GlobalUni;

    $resmap = array ( 106, 108, 109, 110, 111, 113, 114, 115, 117, 118, 120, 121, 122, 123, 124, 199 );
    if ( ! in_array ( $id, $resmap ) ) return;

    $uni = $GlobalUni;
    if ( $uni['freeze'] ) return;

    Debug ("Запустить исследование ".loca("NAME_$id")." на планете $planet_id игрока $player_id" );

    // Исследование уже ведется?
    $result = GetResearchQueue ( $player_id);
    $resq = dbarray ($result);
    if ($resq) return;

    // Исследовательская лаборатория усовершенствуется хоть на одной планете ?
    $query = "SELECT * FROM ".$db_prefix."queue WHERE obj_id = 31 AND (type = 'Build' OR type = 'Demolish') AND start < $now AND owner_id = " . $player_id;
    $result = dbquery ( $query );
    if ( dbrows ($result) > 0 ) return;

    // Получить уровень исследования.
    $user = LoadUser ( $player_id );
    $level = $user['r'.$id] + 1;

    $prem = PremiumStatus ($user);
    if ( $prem['technocrat'] ) $r_factor = 1.1;
    else $r_factor = 1.0;

    // Проверить условия.
    $planet = GetPlanet ( $planet_id );
    $m = $k = $d = $e = 0;
    ResearchPrice ( $id, $level, &$m, &$k, &$d, &$e );

    if ( IsEnoughResources ( $planet, $m, $k, $d, $e ) && ResearchMeetRequirement ( $user, $planet, $id ) ) {
        $speed = $uni['speed'];
        if ($now == 0) $now = time ();
        $reslab = ResearchNetwork ( $planet['planet_id'], $id );
        $seconds = ResearchDuration ( $id, $level, $reslab, $speed * $r_factor);

        // Списать ресурсы.
        AdjustResources ( $m, $k, $d, $planet_id, '-' );

        //echo "--------------------- Запустить исследование $id на планете $planet_id игрока $player_id, уровень $level, продолжительность $seconds" ;
        AddQueue ($player_id, "Research", $planet_id, $id, $level, $now, $seconds);
    }
}

// Отменить исследование.
function StopResearch ($player_id)
{
    global $db_prefix, $GlobalUni;

    $uni = $GlobalUni;
    if ( $uni['freeze'] ) return;

    // Получить очередь исследований.
    $result = GetResearchQueue ( $player_id);
    if ( $result == null ) return;        // Исследование не ведется.
    $resq = dbarray ($result);

    $id = $resq['obj_id'];
    $planet_id = $resq['sub_id'];
    $level = $resq['level'];

    // Получить стоимость исследования
    $user = LoadUser ( $player_id );
    $planet = GetPlanet ( $planet_id );
    if ($planet['owner_id'] != $player_id )
    {
        Error ( "Невозможно отменить исследование -".loca("NAME_$id")."-, игрока ".$user['oname'].", запущенное на чужой планете [".$planet['g'].":".$planet['s'].":".$planet['p']."] " . $planet['name'] );
        return;
    }
    $m = $k = $d = $e = 0;
    ResearchPrice ( $id, $level, &$m, &$k, &$d, &$e );

    // Вернуть ресурсы
    AdjustResources ( $m, $k, $d, $planet_id, '+' );

    RemoveQueue ( $resq['task_id'], 0 );

    Debug ( "Отменить исследование -".loca("NAME_$id")."- у игрока ".$user['oname'] );
}

// Получить текущее исследование для аккаунта.
function GetResearchQueue ($player_id)
{
    global $db_prefix;
    $query = "SELECT * FROM ".$db_prefix."queue WHERE type = 'Research' AND owner_id = $player_id ORDER BY start ASC";
    return dbquery ($query);
}

// Закончить исследование.
function Queue_Research_End ($queue)
{
    global $db_prefix, $GlobalUser;

    $id = $queue['obj_id'];
    $lvl = $queue['level'];
    $planet_id = $queue['sub_id'];
    $player_id = $queue['owner_id'];

    // Рассчитать производство планеты с момента последнего обновления.
    $planet = GetPlanet ( $planet_id );
    ProdResources ( &$planet, $planet['lastpeek'], $queue['end'] );

    // Обновить уровень исследования в базе данных.
    $query = "UPDATE ".$db_prefix."users SET ".('r'.$id)." = $lvl WHERE player_id = $player_id";
    dbquery ($query);

    RemoveQueue ( $queue['task_id'], 0 );

    // Добавить очки.
    $m = $k = $d = $e = 0;
    ResearchPrice ( $id, $lvl, &$m, &$k, &$d, &$e );
    $points = $m + $k + $d;
    AdjustStats ( $queue['owner_id'], $points, 0, 1, '+');
    RecalcRanks ();

    Debug ( "Исследование ".loca("NAME_$id")." уровня $lvl для пользователя $player_id завершено." );

    if ( $GlobalUser['player_id'] == $player_id) {
        InvalidateUserCache ();
        $GlobalUser = LoadUser ( $player_id );    // обновить данные текущего пользователя
    }
}

// ===============================================================================================================
// Игрок

// Получить время окончания офицера. $off - символьное обозначения задания очереди, связанного с офицерами.
function GetOfficerLeft ($player_id, $off)
{
    global $db_prefix;
    $query = "SELECT * FROM ".$db_prefix."queue WHERE type = '".$off."' AND owner_id = $player_id ORDER BY end ASC";
    $result = dbquery ($query);
    if ( $result )
    {
        $queue = dbarray ($result);
        return $queue['end'];
    }
    else return 0;
}

// Продлить офицера на указанное количество секунд.
function RecruitOfficer ( $player_id, $off, $seconds )
{
    global $db_prefix;

    $query = "SELECT * FROM ".$db_prefix."queue WHERE type = '".$off."' AND owner_id = $player_id LIMIT 1";
    $result = dbquery ($query);
    if ( dbrows ($result) == 0 )
    {
        AddQueue ( $player_id, $off, 0, 0, 0, time (), $seconds, 0 );
    }
    else
    {
        $queue = dbarray ( $result );
        $query = "UPDATE ".$db_prefix."queue SET end = end + $seconds WHERE task_id = " . $queue['task_id'];
        dbquery ($query);
    }
}

// Закончилось действие офицера.
function Queue_Officer_End ($queue)
{
    RemoveQueue ( $queue['task_id'], 0 );
}

// Добавить задание пересчёта очков у игрока, если его ещё не существует.
// Вызывается при логине любого игрока.
function AddRecalcPointsEvent ($player_id)
{
    global $db_prefix;

    $query = "SELECT * FROM ".$db_prefix."queue WHERE type = 'RecalcPoints' AND owner_id = $player_id";
    $result = dbquery ($query);
    if ( dbrows ($result) == 0 )
    {
        $now = time ();
        $when = mktime(0, 10, 0, date("m"), date("d")+1, date("y"));
        $queue = array ( null, $player_id, "RecalcPoints", 0, 0, 0, $now, $when, 500 );
        AddDBRow ( $queue, "queue" );
    }
}

// Пересчитать количество набранных очков игрока и его место в статистике.
function Queue_RecalcPoints_End ($queue)
{
    RecalcStats ( $queue['owner_id'] );
    RecalcRanks ();
    RemoveQueue ( $queue['task_id'], 0 );
}

// Можно уйти в РО или нет.
function CanEnableVacation ($player_id)
{
    global $db_prefix;
    $query = "SELECT * FROM ".$db_prefix."queue WHERE (type = 'Build' OR type = 'Demolish' OR type = 'Research' OR type = 'Shipyard' OR type = 'Fleet') AND owner_id = $player_id";
    $result = dbquery ( $query );
    if ( dbrows ($result) > 0 ) return false;
    else return true;
}

// Добавить задание разрешения смены имени.
function AddAllowNameEvent ($player_id)
{
    global $db_prefix;

    $query = "SELECT * FROM ".$db_prefix."queue WHERE type = 'AllowName' AND owner_id = $player_id";
    $result = dbquery ($query);
    if ( dbrows ($result) == 0 )
    {
        $now = time ();
        $when = $now + 7 * 24 * 60 * 60;
        $queue = array ( null, $player_id, "AllowName", 0, 0, 0, $now, $when, 0 );
        $id = AddDBRow ( $queue, "queue" );
        $query = "UPDATE ".$db_prefix."users SET name_changed = 1, name_until = $when WHERE player_id = $player_id";
        dbquery ($query);
        Debug ( $query );
    }
}

// Разрешить сменить имя.
function Queue_AllowName_End ($queue)
{
    global $db_prefix;
    $player_id = $queue['owner_id'];
    $query = "UPDATE ".$db_prefix."users SET name_changed = 0 WHERE player_id = $player_id";
    dbquery ($query);
    RemoveQueue ( $queue['task_id'], 0 );
}

// Разбанить игрока
function Queue_UnbanPlayer_End ($queue)
{
    global $db_prefix;
    $player_id = $queue['owner_id'];
    $query = "UPDATE ".$db_prefix."users SET banned = 0, banned_until = 0 WHERE player_id = $player_id";
    dbquery ($query);
    RemoveQueue ( $queue['task_id'], 0 );
}

// Разрешить атаки
function Queue_AllowAttacks_End ($queue)
{
    global $db_prefix;
    $player_id = $queue['owner_id'];
    $query = "UPDATE ".$db_prefix."users SET noattack = 0, noattack_until = 0 WHERE player_id = $player_id";
    dbquery ($query);
    RemoveQueue ( $queue['task_id'], 0 );
}

// ===============================================================================================================
// Вселенная

// Добавить задание сохранения "старой" статистики.
// Вызывается при логине любого игрока.
function AddUpdateStatsEvent ($now=0)
{
    global $db_prefix;

    if ($now == 0) $now = time ();

    $query = "SELECT * FROM ".$db_prefix."queue WHERE type = 'UpdateStats'";
    $result = dbquery ($query);
    if ( dbrows ($result) == 0 )
    {
        $today = getdate ( $now );
        $hours = $today['hours'];
        if ( $hours >= 8 && $hours < 16 ) $when = mktime ( 16, 5, 0 );
        else if ( $hours >= 16 && $hours < 20 ) $when = mktime ( 20, 5, 0 );
        else $when = mktime ( 8, 5, 0, $today['mon'], $today['mday'] + 1 );

        $queue = array ( null, 99999, "UpdateStats", 0, 0, 0, $now, $when, 510 );
        AddDBRow ( $queue, "queue" );
    }
}

// Сохранить "старые" очки игроков и альянсов.
function Queue_UpdateStats_End ($queue)
{
    global $db_prefix;

    $when = $queue['end'];
    $query = "UPDATE ".$db_prefix."users SET oldscore1 = score1, oldscore2 = score2, oldscore3 = score3, oldplace1 = place1, oldplace2 = place2, oldplace3 = place3, scoredate = $when;";
    dbquery ( $query ); 
    $query = "UPDATE ".$db_prefix."ally SET oldscore1 = score1, oldscore2 = score2, oldscore3 = score3, oldplace1 = place1, oldplace2 = place2, oldplace3 = place3, scoredate = $when;";
    dbquery ( $query ); 

    RemoveQueue ( $queue['task_id'], 0 );
    AddUpdateStatsEvent ($when);
    Debug ( date ("H:i", $when) . " - Old scores saved" );
}

// Добавить задание отгрузки игроков, если его ещё не существует.
// Вызывается при логине любого игрока.
function AddReloginEvent ()
{
    global $db_prefix;

    $query = "SELECT * FROM ".$db_prefix."queue WHERE type = 'UnloadAll'";
    $result = dbquery ($query);
    if ( dbrows ($result) == 0 )
    {
        $now = time ();
        $when = mktime(3, 0, 0, date("m"), date("d")+1, date("y"));;
        $queue = array ( null, 99999, "UnloadAll", 0, 0, 0, $now, $when, 777 );
        $id = AddDBRow ( $queue, "queue" );
    }
}

// Сделать отгрузку всех игроков.
function Queue_Relogin_End ($queue)
{
    // Чистка непосещаемых бесконечных далей.
    global $db_prefix;
    $query = "SELECT target_planet FROM ".$db_prefix."fleet WHERE mission = 15 OR mission = 115 OR mission = 215";
    $query = "DELETE FROM ".$db_prefix."planets WHERE type=20000 AND planet_id <> ALL ($query)";
    dbquery ( $query );
    Debug ( "Удалено бесконечных далей : " . mysql_affected_rows() );

    UnloadAll ();
    RemoveQueue ( $queue['task_id'], 0 );
}

// Добавить задание чистки виртуальных ПО, если его ещё не существует.
// Вызывается при логине любого игрока.
function AddCleanDebrisEvent ()
{
    global $db_prefix;

    $query = "SELECT * FROM ".$db_prefix."queue WHERE type = 'CleanDebris'";
    $result = dbquery ($query);
    if ( dbrows ($result) == 0 )
    {
        $now = time ();
        $week = mktime(0, 0, 0, date('m'), date('d')-date('w'), date('Y')) + 24 * 60 * 60;
        $when = $week + 7 * 24 * 60 * 60 + 10 * 60;
        $queue = array ( null, 99999, "CleanDebris", 0, 0, 0, $now, $when, 600 );
        $id = AddDBRow ( $queue, "queue" );
    }
}

// Чистка виртуальных ПО.
function Queue_CleanDebris_End ($queue)
{
    global $db_prefix;
    $query = "SELECT target_planet FROM ".$db_prefix."fleet WHERE mission = 8 OR mission = 108";
    $query = "DELETE FROM ".$db_prefix."planets WHERE (type=10000 AND m=0 AND k=0) AND planet_id <> ALL ($query)";
    dbquery ( $query );
    Debug ( "Удалено виртуальных ПО : " . mysql_affected_rows() );
    RemoveQueue ( $queue['task_id'], 0 );
    AddCleanDebrisEvent ();
    GalaxyToolUpdate ();
}

// Добавить задание чистки удаленных планет и лун, если его ещё не существует.
// Вызывается при логине любого игрока.
function AddCleanPlanetsEvent ()
{
    global $db_prefix;

    $query = "SELECT * FROM ".$db_prefix."queue WHERE type = 'CleanPlanets'";
    $result = dbquery ($query);
    if ( dbrows ($result) == 0 )
    {
        $now = time ();
        $when = mktime(1, 10, 0, date("m"), date("d")+1, date("y"));
        $queue = array ( null, 99999, "CleanPlanets", 0, 0, 0, $now, $when, 700 );
        $id = AddDBRow ( $queue, "queue" );
    }
}

// Чистка уничтоженных планет.
function Queue_CleanPlanets_End ($queue)
{
    global $db_prefix;

    $when = $queue['end'];
    $query = "SELECT * FROM ".$db_prefix."planets WHERE remove <= $when AND remove <> 0";
    $result = dbquery ( $query );
    $rows = dbrows ( $result );
    $count = 0;
    while ( $rows-- ) 
    {
        $planet = dbarray ( $result );
        $planet_id = $planet['planet_id'];

        // Развернуть флоты, летящие на удаляемую планету.
        $query = "SELECT * FROM ".$db_prefix."fleet WHERE target_planet = $planet_id AND mission < 100;";
        $fleet_result = dbquery ( $query );
        $fleets = dbrows ($fleet_result);
        while ( $fleets-- )
        {
            $fleet_obj = dbarray ( $fleet_result );
            RecallFleet ( $fleet_obj['fleet_id'], $when );
        }

        DestroyPlanet ( $planet_id );
        $count++;
    }

    Debug ( "Чистка уничтоженных планет ($count)" );
    RemoveQueue ( $queue['task_id'], 0 );
    AddCleanPlanetsEvent ();
}

// Добавить задание чистки ишек и игроков поставленных на удаление
// Вызывается при логине любого игрока.
function AddCleanPlayersEvent ()
{
    global $db_prefix;

    $query = "SELECT * FROM ".$db_prefix."queue WHERE type = 'CleanPlayers'";
    $result = dbquery ($query);
    if ( dbrows ($result) == 0 )
    {
        $now = time ();
        $when = mktime(1, 10, 0, date("m"), date("d")+1, date("y"));
        $queue = array ( null, 99999, "CleanPlayers", 0, 0, 0, $now, $when, 900 );
        $id = AddDBRow ( $queue, "queue" );
    }
}

// Удалить игроков и ишки
function Queue_CleanPlayers_End ($queue)
{
    global $db_prefix;

    // Удаление игроков, поставленных на удаление
    $when = $queue['end'];
    $query = "SELECT * FROM ".$db_prefix."users WHERE disable_until <= $when AND disable_until <> 0 AND admin < 1 AND disable <> 0";
    $result = dbquery ( $query );
    $rows = dbrows ( $result );
    while ($rows-- )
    {
        $user = dbarray ( $result );
        RemoveUser ( $user['player_id'], $queue['end'] );
    }

    // Удаление игроков, неактивных более 35 дней. Неактивных ботов не удалять.
    $when = $queue['end'] - 35*24*60*60;
    $query = "SELECT * FROM ".$db_prefix."users WHERE lastclick < $when AND admin < 1 AND lastclick <> 0";
    $result = dbquery ( $query );
    $rows = dbrows ( $result );
    while ($rows-- )
    {
        $user = dbarray ( $result );
        if ( !IsBot ($user['player_id']) ) RemoveUser ( $user['player_id'], $queue['end'] );
    }

    RemoveQueue ( $queue['task_id'], 0 );
    AddCleanPlayersEvent ();
}

// Добавить задание пересчёта очков у игрока, если его ещё не существует.
// Вызывается при логине любого игрока.
function AddRecalcAllyPointsEvent ()
{
    global $db_prefix;

    $query = "SELECT * FROM ".$db_prefix."queue WHERE type = 'RecalcAllyPoints' ";
    $result = dbquery ($query);
    if ( dbrows ($result) == 0 )
    {
        $now = time ();
        $when = mktime(0, 10, 0, date("m"), date("d")+1, date("y"));
        $queue = array ( null, 99999, "RecalcAllyPoints", 0, 0, 0, $now, $when, 400 );
        AddDBRow ( $queue, "queue" );
    }
}

// Пересчитать количество набранных очков игрока и его место в статистике.
function Queue_RecalcAllyPoints_End ($queue)
{
    RecalcAllyStats ();
    RecalcAllyRanks ();
    RemoveQueue ( $queue['task_id'], 0 );
}

// Добавить отладочное событие.
function AddDebugEvent ($when)
{
    $now = time ();
    $queue = array ( null, 99999, "Debug", 0, 0, 0, $now, $when, 9999 );
    $id = AddDBRow ( $queue, "queue" );
}
// Отладочное событие.
function Queue_Debug_End ($queue)
{
    RemoveQueue ( $queue['task_id'], 0 );
}

// ===============================================================================================================
// Флот.

function GetFleetQueue ($fleet_id)
{
    global $db_prefix;
    $query = "SELECT * FROM ".$db_prefix."queue WHERE type = 'Fleet' AND sub_id = $fleet_id";
    $result = dbquery ($query);
    if ($result) return dbarray ($result);
    else return NULL;
}

// Перечислить свои задания флота, а также дружественные и вражеские.
function EnumFleetQueue ($player_id)
{
    global $db_prefix;
    $query = "SELECT planet_id FROM ".$db_prefix."planets WHERE owner_id = $player_id AND type < 10000";
    $query = "SELECT fleet_id FROM ".$db_prefix."fleet WHERE target_planet = ANY ($query) AND (mission < 100 OR mission = 205)";
    $query = "SELECT * FROM ".$db_prefix."queue WHERE type = 'Fleet' AND (sub_id = ANY ($query) OR owner_id = $player_id)";
    $result = dbquery ($query);
    return $result;
}

// Перечислить только свои задания флота.
// ipm: 1 -- учитывать также летящие МПР (для пересчёта очков)
function EnumOwnFleetQueue ($player_id, $ipm=0)
{
    global $db_prefix;
    if ($ipm) $query = "SELECT * FROM ".$db_prefix."queue WHERE type = 'Fleet' AND owner_id = $player_id ORDER BY end ASC, prio DESC";
    else
    {
        $query = "SELECT fleet_id FROM ".$db_prefix."fleet WHERE mission <> 20 AND owner_id = $player_id";
        $query = "SELECT * FROM ".$db_prefix."queue WHERE type = 'Fleet' AND sub_id = ANY ($query) ORDER BY end ASC, prio DESC";
    }
    $result = dbquery ($query);
    return $result;
}

// Перечислить флоты летящие от или на планету.
function EnumPlanetFleets ($planet_id)
{
    global $db_prefix;
    $query = "SELECT * FROM ".$db_prefix."fleet WHERE start_planet = $planet_id OR target_planet = $planet_id;";
    $result = dbquery ($query);
    return $result;
}

// Обработчик завершения задания флота находится в модуле fleet.php

?>