<?php
declare(strict_types=1);

session_start();

$usuario = $_SESSION['usuario'] ?? null;

if (!$usuario) {
    header('Location: login.php');
    exit;
}

// Opciones disponibles según el rol del usuario.
$menus = [
    'Administrador' => [
        ['👩‍🎓', 'Estudiantes', 'estudiantes.php'],
        ['👩‍🏫', 'Profesores', 'profesores.php'],
        ['📚', 'Asignaturas', 'asignaturas.php'],
        ['🏫', 'Cursos', 'cursos.php'],
        ['📝', 'Matrículas', 'matriculas.php'],
        ['📊', 'Calificaciones', 'calificaciones.php'],
        ['📅', 'Calendario escolar', 'calendario.php']
    ],
    'Profesor' => [
    ['📚', 'Mis cursos', 'mis_cursos.php'],
    ['🎓', 'Mis estudiantes', 'mis_estudiantes.php'],
    ['📊', 'Calificaciones', 'calificaciones.php']
],
    'Estudiante' => [
        ['📝', 'Mis matrículas', 'matriculas.php'],
        ['📊', 'Mis calificaciones', 'calificaciones.php'],
        ['📅', 'Calendario escolar', 'calendario.php']
    ]
];

$opciones = $menus[$usuario['rol']] ?? [];

$_SESSION['token_logout'] ??= bin2hex(random_bytes(32));

function escapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel principal | Gestión Escolar</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #d9f3ff, #fff0f6);
            font-family: "Segoe UI", Arial, sans-serif;
            color: #243850;
        }

        header {
            background: #ffffff;
            padding: 20px;
            border-bottom: 4px solid #8bcfea;
            box-shadow: 0 4px 15px #294d6910;
        }

        .bienvenida {
            background: white;
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 25px;
        }

        .perfil {
            display: inline-block;
            background: #ffc9df;
            padding: 7px 15px;
            border-radius: 30px;
            font-weight: 600;
        }

        .tarjeta {
            display: block;
            text-decoration: none;
            text-align: center;
            background: #e2f5ff;
            color: #243850;
            padding: 30px 15px;
            border-radius: 20px;
            height: 100%;
            box-shadow: 0 8px 20px #294d6910;
            transition: transform 0.2s;
        }

        .rosada {
            background: #ffe5f0;
        }

        .tarjeta:hover {
            transform: translateY(-5px);
            color: #243850;
        }

        .icono {
            font-size: 45px;
            margin-bottom: 12px;
        }

        .salir {
            border: none;
            background: #ffc9df;
            color: #5b2941;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
        }

        footer {
            text-align: center;
            padding: 30px;
            color: #894368;
        }
    </style>
</head>
<body>

<header>
    <div class="container d-flex flex-wrap align-items-center
                justify-content-between gap-3">

        <h3 class="mb-0">🏫 Gestión Escolar</h3>

        <form action="cerrar_sesion.php" method="post">
            <input type="hidden" name="token"
                   value="<?= escapar($_SESSION['token_logout']) ?>">
            <button class="salir" type="submit">Cerrar sesión</button>
        </form>

    </div>
</header>

<main class="container py-4">

    <section class="bienvenida">
        <h1>¡Bienvenida/o, <?= escapar($usuario['nombre']) ?>!</h1>
        <p>Selecciona la sección que deseas gestionar.</p>
        <span class="perfil"><?= escapar($usuario['rol']) ?></span>
    </section>

    <div class="row g-4">

        <?php foreach ($opciones as $indice => $opcion): ?>

            <div class="col-lg-3 col-md-4 col-sm-6">

                <a href="<?= escapar($opcion[2]) ?>"
                   class="tarjeta <?= $indice % 2 ? 'rosada' : '' ?>">

                    <div class="icono"><?= $opcion[0] ?></div>

                    <h5><?= escapar($opcion[1]) ?></h5>

                </a>

            </div>

        <?php endforeach; ?>

    </div>

</main>

<footer>
    Hecho por Norka Mideros
</footer>

</body>
</html>