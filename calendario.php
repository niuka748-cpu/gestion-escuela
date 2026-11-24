<?php
declare(strict_types=1);

session_start();

$usuario = $_SESSION['usuario'] ?? null;

if ($usuario === null) {
    header('Location: login.php');
    exit;
}

$rol = $usuario['rol'];
$esAdmin = $rol === 'Administrador';

if (!$esAdmin && $rol !== 'Estudiante') {
    http_response_code(403);
    exit('Este calendario está disponible para administradores y estudiantes.');
}

require_once __DIR__ . '/conexion.php';

function escapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

function campo(string $nombre): string
{
    $valor = $_POST[$nombre] ?? '';
    return is_string($valor) ? trim($valor) : '';
}

$_SESSION['token_calendario'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['token_calendario'];

$aviso = $_SESSION['aviso_calendario'] ?? '';
unset($_SESSION['aviso_calendario']);

$error = '';
$titulo = '';
$descripcion = '';
$fecha = '';
$eventos = [];

try {
    $db = conectarBaseDatos();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$esAdmin || !hash_equals($token, campo('token'))) {
            http_response_code(403);
            exit('No tienes permiso para publicar este evento.');
        }

        $titulo = campo('titulo');
        $descripcion = campo('descripcion');
        $fecha = campo('fecha');

        if ($titulo === '' || mb_strlen($titulo) > 100
            || $descripcion === '' || mb_strlen($descripcion) > 255) {
            $error = 'Completa el título y la descripción dentro de los límites indicados.';
        } elseif (!preg_match('/\A[1-9]\d{3}-\d{2}-\d{2}\z/', $fecha)
            || !checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4))) {
            $error = 'Selecciona una fecha válida.';
        } else {
            // Publica el evento en el calendario compartido.
            $consulta = $db->prepare(
                'INSERT INTO eventos_calendario (titulo, descripcion, fecha) VALUES (?, ?, ?)'
            );
            $consulta->execute([$titulo, $descripcion, $fecha]);

            $_SESSION['aviso_calendario'] = 'Evento publicado.';
            header('Location: calendario.php', true, 303);
            exit;
        }
    }

    // Muestra los eventos en orden de fecha.
    $eventos = $db->query(
        'SELECT titulo, descripcion, fecha
         FROM eventos_calendario ORDER BY fecha, id'
    )->fetchAll();
} catch (PDOException $excepcion) {
    error_log($excepcion->getMessage());
    $error = 'No se pudo completar la operación del calendario.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Calendario | Gestión Escolar</title>
    <style>
        /* Fondo celeste y rosado del sistema escolar. */
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 28px 16px;
            background: linear-gradient(135deg, #d9f3ff, #fff0f6);
            color: #243850;
            font-family: "Segoe UI", Arial, sans-serif;
        }

        main {
            max-width: 850px;
            margin: auto;
            padding: clamp(20px, 5vw, 40px);
            border-top: 6px solid #8bcfea;
            border-radius: 24px;
            background: white;
            box-shadow: 0 18px 45px #294d6920;
        }

        a { color: #235675; }

        form, .evento {
            display: grid;
            gap: 10px;
            padding: 20px;
            border-radius: 16px;
            background: #e2f5ff;
        }

        input, textarea, button {
            min-width: 0;
            padding: 10px 12px;
            border-radius: 10px;
            font: inherit;
        }

        input, textarea { border: 1px solid #8bcfea; }

        button {
            border: 0;
            background: #ffc9df;
            color: #5b2941;
            font-weight: 700;
            cursor: pointer;
        }

        .evento { margin-bottom: 12px; overflow-wrap: anywhere; }
        .evento:nth-of-type(even) { background: #ffe5f0; }
        .evento h3, .evento p { margin: 0; }
        time { font-weight: 700; }
        .aviso, .error { padding: 12px; border-radius: 10px; }
        .aviso { background: #e2f5ff; }
        .error { background: #ffe5f0; color: #742044; }
        footer { margin-top: 24px; text-align: center; color: #894368; }
    </style>
</head>
<body>
<main>
    <a href="<?= $esAdmin ? 'panel.php' : 'login.php' ?>">← Volver</a>
    <h1>Calendario escolar</h1>

    <?php if ($error !== ''): ?>
        <p class="error" role="alert"><?= escapar($error) ?></p>
    <?php elseif ($aviso !== ''): ?>
        <p class="aviso" role="status"><?= escapar($aviso) ?></p>
    <?php endif; ?>

    <?php if ($esAdmin): ?>
        <form method="post" action="calendario.php">
            <input type="hidden" name="token" value="<?= escapar($token) ?>">

            <label for="titulo">Título del evento</label>
            <input id="titulo" name="titulo" maxlength="100"
                   value="<?= escapar($titulo) ?>" required>

            <label for="descripcion">Descripción</label>
            <textarea id="descripcion" name="descripcion" rows="3"
                      maxlength="255" required><?= escapar($descripcion) ?></textarea>

            <label for="fecha">Fecha</label>
            <input id="fecha" name="fecha" type="date"
                   value="<?= escapar($fecha) ?>" required>

            <button type="submit">Publicar evento</button>
        </form>
    <?php endif; ?>

    <h2>Eventos programados</h2>

    <?php if ($eventos === [] && $error === ''): ?>
        <p>Todavía no hay eventos publicados.</p>
    <?php endif; ?>

    <?php foreach ($eventos as $evento): ?>
        <article class="evento">
            <time datetime="<?= escapar($evento['fecha']) ?>">
                <?= (new DateTimeImmutable($evento['fecha']))->format('d/m/Y') ?>
            </time>
            <h3><?= escapar($evento['titulo']) ?></h3>
            <p><?= escapar($evento['descripcion']) ?></p>
        </article>
    <?php endforeach; ?>

    <footer>Hecho por Norka Mideros</footer>
</main>
</body>
</html>