<?php
/**
 * Stand-alone error page (no layout, no database) so it can render even when
 * the database itself is the problem.
 * @var string $message
 * @var \Throwable|null $exception  only set when app.debug is true
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Something went wrong</title>
    <style>
        body { margin: 0; font-family: "Segoe UI", Arial, sans-serif; font-size: 14px; background: #f3f5f9; color: #1b2437; }
        .box { max-width: 680px; margin: 8vh auto; background: #fff; border-radius: 14px; border: 1px solid #e3e8ef; box-shadow: 0 10px 30px rgba(10,36,99,.1); overflow: hidden; }
        .head { background: #0A2463; color: #fff; padding: 1.2rem 1.5rem; border-bottom: 4px solid #C9A227; }
        .head h1 { margin: 0; font-size: 1.3rem; }
        .body { padding: 1.5rem; line-height: 1.6; }
        pre { background: #0f172a; color: #e2e8f0; padding: 1rem; border-radius: 8px; overflow: auto; font-size: 12px; max-height: 320px; }
        a { color: #0A2463; font-weight: 600; }
    </style>
</head>
<body>
<div class="box">
    <div class="head"><h1>We could not complete your request</h1></div>
    <div class="body">
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <p><a href="javascript:history.back()">&larr; Go back</a></p>
        <?php if (!empty($exception)): ?>
            <p><strong>Technical details</strong> (shown because debug mode is on in config/config.php):</p>
            <pre><?= htmlspecialchars(get_class($exception) . ': ' . $exception->getMessage() . "\n" . $exception->getFile() . ':' . $exception->getLine() . "\n\n" . $exception->getTraceAsString(), ENT_QUOTES, 'UTF-8') ?></pre>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
