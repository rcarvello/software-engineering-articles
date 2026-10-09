<?php
// Front controller
declare(strict_types=1);

/** @var App\Container\Container $container */
$container = require __DIR__ . '/../bootstrap.php';

use App\Report\EmployeeReport;

function e(string|int|float $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

try {
    // The only place where application code "asks" the container for something
    $report = $container->get(EmployeeReport::class);
    $rows   = $report->rows();
    $avg    = $report->averageAge();
} catch (Throwable $ex) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error: ' . $ex->getMessage();
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Employees - DI container example</title>
    <style>
        body  { font-family: system-ui, sans-serif; margin: 2rem auto; max-width: 52rem; padding: 0 1rem; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border-bottom: 1px solid #ccc; padding: .5rem .6rem; text-align: left; }
        th    { background: #f3f3f3; }
        td.n  { text-align: right; }
    </style>
</head>
<body>
    <h1>Employees</h1>
    <table>
        <thead>
            <tr><th>#</th><th>Name</th><th>Age</th><th>Department</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="n"><?= e($r['id']) ?></td>
                <td><?= e($r['name']) ?></td>
                <td class="n"><?= e($r['age']) ?></td>
                <td><?= e($r['department']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p>Average age: <strong><?= e(number_format($avg, 1, '.', ',')) ?></strong> years</p>
</body>
</html>
