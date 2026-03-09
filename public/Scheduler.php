<?php

namespace BLBot;
class Scheduler {
    private $db, $name, $enabled, $validator, $runner, $interval, $timestamp;
    private function checkInterval(): bool {
        return $this->timestamp - ($this->db->get($this->name)['lastExecute'] ?? 0) >= $this->interval;
    }
    private function setInterval(): bool {
        return $this->db->set($this->name, ['lastExecute' => $this->timestamp]);
    }
    public function setTime(int $timestamp): void {
        $this->timestamp = $timestamp;
    }
    public function validate(): bool {
        return $this->enabled && $this->checkInterval() && ($this->validator)($this->timestamp);
    }
    public function run(): void {
        $this->setInterval();
        try {
            ($this->runner)($this->timestamp);
        } catch (\Throwable $e) {
            global $Queue;
            $time = date('Y/m/d H:i:s', $this->timestamp);
            $trace = implode("\n", array_slice(explode("\n", (string)$e), 0, 4));
            $errorMsg = "[{$time}] 执行 Scheduler {$this->name} 时发生异常：\n类型: " . get_class($e) . "\n信息: " . $e->getMessage() . "\n" . $trace . "\n...";
            $Queue[] = sendMaster($errorMsg);
            if (function_exists('sendDevGroup')) {
                $Queue[] = sendDevGroup($errorMsg);
            }
        }
    }
    public function __construct(string $name, bool $enabled, callable $validator, callable $runner, int $interval = -1) {
        $this->name = $name;
        $this->enabled = $enabled;
        $this->validator = $validator;
        $this->runner = $runner;
        $this->interval = $interval;
        $this->db = new Database('scheduler', ['key' => 'name']);
    }
}