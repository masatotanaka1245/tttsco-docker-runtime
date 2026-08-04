<?php

$appRoot = getenv('TEPSCO_APP_ROOT') ?: __DIR__ . '/../AI_System_Data';
require_once $appRoot . '/src/ChatRequestDuplicateGuard.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (($argv[1] ?? '') === '--hold') {
    $lock = ChatRequestDuplicateGuard::acquire($argv[2], 1, 2, 3, 'request-a');
    fwrite(STDOUT, "locked\n");
    fflush(STDOUT);
    sleep(3);
    ChatRequestDuplicateGuard::release($lock);
    exit(0);
}

$dir = sys_get_temp_dir() . '/tepsco-duplicate-guard-' . getmypid() . '-' . bin2hex(random_bytes(4));
assertTrue(mkdir($dir, 0700, true), 'test directory creation failed');

try {
    foreach (['', 'with space', 'x/y', str_repeat('a', 129)] as $invalidRequestId) {
        assertTrue(!ChatRequestDuplicateGuard::isValidRequestId($invalidRequestId), 'invalid request id was accepted');
    }
    assertTrue(ChatRequestDuplicateGuard::isValidRequestId('request_A-1'), 'valid request id was rejected');

    $first = ChatRequestDuplicateGuard::acquire($dir, 1, 2, 3, 'request-a');
    assertTrue($first !== null, 'first lock was not acquired');
    assertTrue(ChatRequestDuplicateGuard::acquire($dir, 1, 2, 3, 'request-a') === null, 'same request id lock conflict was not detected');
    $differentRequest = ChatRequestDuplicateGuard::acquire($dir, 1, 2, 3, 'request-b');
    $differentUser = ChatRequestDuplicateGuard::acquire($dir, 2, 2, 3, 'request-a');
    $differentProject = ChatRequestDuplicateGuard::acquire($dir, 1, 3, 3, 'request-a');
    $differentThread = ChatRequestDuplicateGuard::acquire($dir, 1, 2, 4, 'request-a');
    assertTrue($differentRequest !== null, 'different request id was blocked');
    assertTrue($differentUser !== null, 'different user was blocked');
    assertTrue($differentProject !== null, 'different project was blocked');
    assertTrue($differentThread !== null, 'different thread was blocked');
    ChatRequestDuplicateGuard::release($differentRequest);
    ChatRequestDuplicateGuard::release($differentUser);
    ChatRequestDuplicateGuard::release($differentProject);
    ChatRequestDuplicateGuard::release($differentThread);

    $lockFile = $first['file'];
    ChatRequestDuplicateGuard::release($first);
    ChatRequestDuplicateGuard::release($first);
    assertTrue(is_file($lockFile), 'lock file should be harmlessly retained after release');
    $reacquired = ChatRequestDuplicateGuard::acquire($dir, 1, 2, 3, 'request-a');
    assertTrue($reacquired !== null, 'released lock file could not be reacquired');
    ChatRequestDuplicateGuard::release($reacquired);
    ChatRequestDuplicateGuard::release($reacquired);

    $exceptionLock = null;
    try {
        $exceptionLock = ChatRequestDuplicateGuard::acquire($dir, 1, 2, 3, 'request-exception');
        throw new RuntimeException('simulated route exception');
    } catch (RuntimeException $exception) {
        ChatRequestDuplicateGuard::release($exceptionLock);
    }
    $afterException = ChatRequestDuplicateGuard::acquire($dir, 1, 2, 3, 'request-exception');
    assertTrue($afterException !== null, 'lock was not released after exception cleanup');
    ChatRequestDuplicateGuard::release($afterException);

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --hold ' . escapeshellarg($dir);
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    assertTrue(is_resource($process), 'lock-holder process did not start');
    $line = fgets($pipes[1]);
    assertTrue(trim((string)$line) === 'locked', 'lock-holder process did not acquire its lock');
    assertTrue(ChatRequestDuplicateGuard::acquire($dir, 1, 2, 3, 'request-a') === null, 'cross-process lock conflict was not detected');
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    assertTrue(proc_close($process) === 0, 'lock-holder process failed');

    $afterHolderExit = ChatRequestDuplicateGuard::acquire($dir, 1, 2, 3, 'request-a');
    assertTrue($afterHolderExit !== null, 'lock was not released after lock-holder exit');
    ChatRequestDuplicateGuard::release($afterHolderExit);

    echo "ChatRequestDuplicateGuardTest: ok\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($dir);
}
