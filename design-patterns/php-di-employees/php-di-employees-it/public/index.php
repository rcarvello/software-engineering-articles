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
    // L'unico punto in cui il codice applicativo "chiede" qualcosa al container
    $report = $container->get(EmployeeReport::class);
    $rows   = $report->rows();
    $avg    = $report->averageAge();
} catch (Throwable $ex) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Errore: ' . $ex->getMessage();
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Dipendenti - esempio DI container</title>
    <style>
        body  { font-family: system-ui, sans-serif; margin: 2rem auto; max-width: 52rem; padding: 0 1rem; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border-bottom: 1px solid #ccc; padding: .5rem .6rem; text-align: left; }
        th    { background: #f3f3f3; }
        td.n  { text-align: right; }
    </style>
</head>
<body>
    <h1>Dipendenti</h1>
    <table>
        <thead>
            <tr><th>#</th><th>Nome</th><th>Et&agrave;</th><th>Dipartimento</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="n"><?= e($r['id']) ?></td>
                <td><?= e($r['nome']) ?></td>
                <td class="n"><?= e($r['eta']) ?></td>
                <td><?= e($r['dipartimento']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p>Et&agrave; media: <strong><?= e(number_format($avg, 1, ',', '.')) ?></strong> anni</p>
</body>
</html>
