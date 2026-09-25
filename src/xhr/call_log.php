<?php
/**
 * 通话时长记录（2026-09-25 新增）
 *
 * 通话结束后由通话页面上报本端实际通话秒数，服务端写入一条聊天消息，
 * 聊天记录里以「通话已结束 01:21」的系统行展示（type_two = call_log）。
 *
 * 去重：一次通话双方页面都会上报，用房间号做标记写在 notification_id 上，
 *       并用 MySQL 命名锁串行化「查重 + 插入」，保证一通电话只落一条记录。
 * 不新增语言键：文案用现有 call_ended + 服务端格式化的时长。
 */
if ($f == 'call_log') {
    header("Content-type: application/json; charset=utf-8");
    $data = array('status' => 400);

    $to_id    = (isset($_GET['to_id']) && is_numeric($_GET['to_id']) && $_GET['to_id'] > 0) ? (int) $_GET['to_id'] : 0;
    $duration = (isset($_GET['duration']) && is_numeric($_GET['duration'])) ? (int) $_GET['duration'] : 0;
    $room     = (isset($_GET['room'])) ? preg_replace('/[^A-Za-z0-9\-_]/', '', $_GET['room']) : '';
    $from_id  = (int) $wo['user']['user_id'];

    // 登录态 + 参数校验：只接受 1 秒 ~ 24 小时的通话时长
    if (Wo_CheckMainSession($hash_id) !== true || $to_id < 1 || $to_id == $from_id || $duration < 1 || $duration > 86400) {
        echo json_encode($data);
        exit();
    }
    $recipient = Wo_UserData($to_id);
    if (empty($recipient['user_id'])) {
        echo json_encode($data);
        exit();
    }
    if (strlen($room) > 32) {
        $room = substr($room, 0, 32);
    }
    // 房间号即本次通话的唯一标记；极少数拿不到房间号的情况退化为按「双方 + 5 分钟」去重
    $mark      = 'calllog_' . ($room != '' ? $room : $from_id . '_' . $to_id . '_' . floor(time() / 300));
    $mark_sql  = Wo_Secure($mark);
    $lock_name = Wo_Secure('seosq_calllog_' . md5($mark));
    $locked    = false;
    $lock_res  = mysqli_query($sqlConnect, "SELECT GET_LOCK('{$lock_name}', 3) AS `got`");
    if ($lock_res) {
        $lock_row = mysqli_fetch_assoc($lock_res);
        $locked   = !empty($lock_row['got']);
    }

    if ($locked) {
        $check = mysqli_query($sqlConnect, "SELECT `id` FROM " . T_MESSAGES . " WHERE `notification_id` = '{$mark_sql}' AND `type_two` = 'call_log' LIMIT 1");
        if ($check && mysqli_num_rows($check) == 0) {
            $text   = $wo['lang']['call_ended'] . ' ' . wo_call_log_duration($duration);
            $new_id = Wo_RegisterMessage(array(
                'from_id'         => $from_id,
                'to_id'           => $to_id,
                'text'            => mysqli_real_escape_string($sqlConnect, $text),
                'time'            => time(),
                'type_two'        => 'call_log',
                'notification_id' => $mark_sql,
            ));
            if ($new_id) {
                $data['status'] = 200;
                $data['id']     = (int) $new_id;
            } else {
                $data['status'] = 500;
            }
        } else {
            // 对端已经记录过这通电话，直接返回成功，避免聊天记录里出现两条
            $data['status'] = 200;
            $data['note']   = 'already_logged';
        }
        mysqli_query($sqlConnect, "SELECT RELEASE_LOCK('{$lock_name}')");
    } else {
        // 拿到锁失败：同一通话的另一次写入正在处理，前端忽略即可
        $data['status'] = 429;
    }

    echo json_encode($data);
    exit();
}

/**
 * 秒 -> 时长文案：MM:SS，满 1 小时才显示 HH:MM:SS（与通话中的计时口径一致）
 */
function wo_call_log_duration($seconds)
{
    $seconds = (int) $seconds;
    if ($seconds < 0) {
        $seconds = 0;
    }
    $h   = floor($seconds / 3600);
    $m   = floor(($seconds % 3600) / 60);
    $s   = $seconds % 60;
    $out = ($h > 0 ? str_pad($h, 2, '0', STR_PAD_LEFT) . ':' : '')
         . str_pad($m, 2, '0', STR_PAD_LEFT) . ':' . str_pad($s, 2, '0', STR_PAD_LEFT);
    return $out;
}
