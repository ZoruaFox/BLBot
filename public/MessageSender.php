<?php
namespace kjBot\Frame;

class MessageSender{

    public $CQ;

    /**
     * @param kjBot\SDK\CoolQ $CoolQ 一个酷Q实例
     */
    function __construct(\kjBot\SDK\CoolQ $CoolQ){
        $this->CQ = $CoolQ;
    }

    /**
     * 发送一条消息
     * @param kjBot\Frame\Message $message
     * @return mixed 发送结果（成功时返回 CoolQ API 的 data 字段，失败时返回 null）
     */
    function send(Message $message){
        $result = null;
        if($message->toGroup){
            if($message->async){
                $result = $this->CQ->sendGroupMsgAsync($message->id, $message->msg, $message->auto_escape);
            }else{
                $result = $this->CQ->sendGroupMsg($message->id, $message->msg, $message->auto_escape);
            }
        }else{
            if($message->async){
                $result = $this->CQ->sendPrivateMsgAsync($message->id, $message->msg, $message->auto_escape);
            }else{
                $result = $this->CQ->sendPrivateMsg($message->id, $message->msg, $message->auto_escape);
            }
        }

        // 记录发送结果日志
        $this->logSendResult($message, $result);

        return $result;
    }

    /**
     * 记录消息发送结果到日志文件
     */
    private function logSendResult(Message $message, $result) {
        $enabled = $this->isSendLogEnabled();
        if (!$enabled && $result !== null) return; // 默认只记录失败日志；开启后记录全部

        $log_dir = dirname(__DIR__) . '/storage/data';
        $log_file = $log_dir . '/send_result.log';
        $status = ($result !== null) ? 'OK' : 'FAIL';

        // 截取消息前 200 字符作为预览，避免日志过大
        $msgPreview = mb_substr($message->msg, 0, 200);
        if (mb_strlen($message->msg) > 200) $msgPreview .= '...[truncated]';

        $targetType = $message->toGroup ? 'Group' : 'Private';
        $asyncLabel = $message->async ? '(async)' : '';

        $log_data = sprintf(
            "[%s] [%s] %s%s -> %d %s: %s\n",
            date('Y-m-d H:i:s'),
            $status,
            $targetType,
            $asyncLabel,
            $message->id,
            $message->auto_escape ? '(raw)' : '(CQ)',
            str_replace("\n", '\\n', $msgPreview)
        );
        @file_put_contents($log_file, $log_data, FILE_APPEND);
    }

    /**
     * 检查是否启用发送结果日志（通过 config.ini 的 SEND_LOG 配置）
     */
    private function isSendLogEnabled(): bool {
        static $enabled = null;
        if ($enabled !== null) return $enabled;

        $configFile = dirname(__DIR__) . '/config.ini';
        if (!file_exists($configFile)) {
            $enabled = false;
            return false;
        }

        $config = @parse_ini_file($configFile, false);
        if (!$config || !isset($config['SEND_LOG'])) {
            $enabled = false;
            return false;
        }

        $val = strtolower(trim((string)$config['SEND_LOG']));
        $enabled = in_array($val, ['1', 'true', 'yes', 'on'], true);
        return $enabled;
    }

}
