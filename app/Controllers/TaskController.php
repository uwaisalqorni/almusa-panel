<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

class TaskController
{
    private string $logFile = '/tmp/panelExec.log';

    public function status(): void
    {
        if (!Auth::check()) {
            Response::json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $pdo = Database::getInstance();

        // 1. Count pending tasks
        $stmtPending = $pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'pending'");
        $pendingCount = (int)$stmtPending->fetchColumn();

        // 2. Fetch active running task
        $stmtRunning = $pdo->query("SELECT * FROM tasks WHERE status = 'processing' ORDER BY id ASC LIMIT 1");
        $runningTask = $stmtRunning->fetch() ?: null;

        // 3. Fetch latest finished task
        $stmtLast = $pdo->query("SELECT * FROM tasks WHERE status IN ('completed', 'failed') ORDER BY id DESC LIMIT 1");
        $lastTask = $stmtLast->fetch() ?: null;

        // 4. Read last 100 lines of /tmp/panelExec.log
        $logContent = '';
        if (file_exists($this->logFile)) {
            $logContent = self::tailFile($this->logFile, 100);
        }

        Response::json([
            'success'       => true,
            'pending_count' => $pendingCount,
            'running_task'  => $runningTask,
            'last_task'     => $lastTask,
            'log'           => $logContent,
            'log_size'      => file_exists($this->logFile) ? filesize($this->logFile) : 0,
            'timestamp'     => time(),
        ]);
    }

    public function history(): void
    {
        if (!Auth::check()) {
            Response::json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $pdo = Database::getInstance();
        $stmt = $pdo->query("SELECT * FROM tasks ORDER BY id DESC LIMIT 25");
        $tasks = $stmt->fetchAll();

        Response::json([
            'success' => true,
            'tasks'   => $tasks,
        ]);
    }

    public function clearLog(): void
    {
        if (!Auth::check()) {
            Response::json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        if (file_exists($this->logFile)) {
            @file_put_contents($this->logFile, "[System] Log cleared at " . date('Y-m-d H:i:s') . "\n");
        }

        Response::json([
            'success' => true,
            'message' => 'Log cleared successfully.',
        ]);
    }

    public static function tailFile(string $filepath, int $lines = 100): string
    {
        if (!file_exists($filepath) || !is_readable($filepath)) {
            return '';
        }

        $f = @fopen($filepath, "rb");
        if ($f === false) {
            return '';
        }

        $buffer = 4096;
        fseek($f, 0, SEEK_END);
        $pos = ftell($f);
        $output = '';
        $lineCount = 0;

        while ($pos > 0 && $lineCount <= $lines) {
            $seek = min($pos, $buffer);
            $pos -= $seek;
            fseek($f, $pos, SEEK_SET);
            $chunk = fread($f, $seek);
            $output = $chunk . $output;
            $lineCount = substr_count($output, "\n");
        }

        fclose($f);

        $allLines = explode("\n", trim($output));
        if (count($allLines) > $lines) {
            $allLines = array_slice($allLines, -$lines);
        }

        return implode("\n", $allLines);
    }
}
