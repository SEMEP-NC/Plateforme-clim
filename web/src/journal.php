<?php
require 'config/db.php';
require 'auth.php';
session_start();
require_admin();

$pdo = get_db();
$db = get_db();


if($_SESSION['user']['role'] !== 'admin'){
        die("Accès réservé administrateur");
    }

$user_session = $_SESSION['user'];
/*
|--------------------------------------------------------------------------
| FILTRES
|--------------------------------------------------------------------------
*/

$userFilter   = $_GET['username'] ?? '';
$actionFilter = $_GET['action'] ?? '';
$dateStart    = $_GET['date_start'] ?? '';
$dateEnd      = $_GET['date_end'] ?? '';

$page = max(1, (int)($_GET['page'] ?? 1));

$limit = 25;
$offset = ($page - 1) * $limit;

/*
|--------------------------------------------------------------------------
| ACTIONS DISPONIBLES
|--------------------------------------------------------------------------
*/

$actions = $db->query("
    SELECT DISTINCT action 
    FROM audit_logs
    ORDER BY action
")->fetchAll(PDO::FETCH_COLUMN);

/*
|--------------------------------------------------------------------------
| UTILISATEURS
|--------------------------------------------------------------------------
*/

$users = $db->query("
    SELECT DISTINCT username
    FROM audit_logs
    ORDER BY username
")->fetchAll(PDO::FETCH_COLUMN);


/*
|--------------------------------------------------------------------------
| REQUETE JOURNAL
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];

if ($userFilter !== '') {
    $where[] = "username = ?";
    $params[] = $userFilter;
}

if ($actionFilter !== '') {
    $where[] = "action = ?";
    $params[] = $actionFilter;
}

if ($dateStart !== '') {
    $where[] = "created_at >= ?";
    $params[] = $dateStart . " 00:00:00";
}

if ($dateEnd !== '') {
    $where[] = "created_at <= ?";
    $params[] = $dateEnd . " 23:59:59";
}

$sqlWhere = "";

if ($where) {
    $sqlWhere = "WHERE " . implode(" AND ", $where);
}

/*
|--------------------------------------------------------------------------
| TOTAL POUR PAGINATION
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare("
    SELECT COUNT(*)
    FROM audit_logs
    $sqlWhere
");
$stmt->execute($params);
$total = $stmt->fetchColumn();
$totalPages = ceil($total / $limit);

/*
|--------------------------------------------------------------------------
| DONNEES PAGE COURANTE
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT *
    FROM audit_logs
    $sqlWhere
    ORDER BY created_at DESC
    LIMIT $limit OFFSET $offset
";

$stmt = $db->prepare($sql);
$stmt->execute($params);

$journal = $stmt->fetchAll(PDO::FETCH_ASSOC);
$db=get_db();
    $page_title = "Journal logs";
    require "includes/header.php";
    require "includes/user_menu.php";
?>
    <main class="container flex-grow-1 mt-4">
        <!-- Journal -->
        <div class="card mt-4">
            <div class="card-header">
                <strong>Journal</strong>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <!-- UTILISATEUR -->
                    <div class="col-md-3">
                        <label class="form-label">Utilisateur</label>
                        <select name="username" class="form-select">
                            <option value="">
                                Tous
                            </option>
                            <?php foreach($users as $u): ?>
                                <option value="<?= htmlspecialchars($u) ?>"
                                    <?= $userFilter == $u ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- ACTION -->
                    <div class="col-md-3">
                        <label class="form-label">
                            Action
                        </label>
                        <select name="action" class="form-select">
                            <option value="">
                                Toutes
                            </option>
                            <?php foreach($actions as $a): ?>
                                <option value="<?= htmlspecialchars($a) ?>"
                                    <?= $actionFilter == $a ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($a) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- DATE DEBUT -->
                    <div class="col-md-2">
                        <label class="form-label">
                            Début
                        </label>
                        <input 
                            type="date"
                            name="date_start"
                            class="form-control"
                            value="<?= htmlspecialchars($dateStart) ?>">
                    </div>
                    <!-- DATE FIN -->
                    <div class="col-md-2">
                        <label class="form-label">
                            Fin
                        </label>
                        <input 
                            type="date"
                            name="date_end"
                            class="form-control"
                            value="<?= htmlspecialchars($dateEnd) ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button class="btn btn-primary w-100">
                            <i class="bi bi-search"></i>
                            Filtrer
                        </button>
                    </div>
                </form>
                <hr>
                <table class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Utilisateur</th>
                            <th>Action</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($journal as $j): ?>
                            <tr>
                                <td>
                                    <?php
                                        $date = new DateTime($j['created_at'], new DateTimeZone('UTC'));
                                        $date->setTimezone(new DateTimeZone('Pacific/Noumea'));
                                        echo $date->format('d/m/Y H:i:s');
                                    ?>
                                </td>
                                <td><?= htmlspecialchars($j['username']) ?></td>
                                <td><?= htmlspecialchars($j['action']) ?></td>
                                <td><?= htmlspecialchars($j['description']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <nav>
                    <ul class="pagination justify-content-center">
                        <?php for($i=1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                <a class="page-link"
                                    href="?page=<?= $i ?>&username=<?= urlencode($userFilter) ?>&action=<?= urlencode($actionFilter) ?>&date_start=<?= urlencode($dateStart) ?>&date_end=<?= urlencode($dateEnd) ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
        </div>
    </main>
<?php require "includes/footer.php"; ?>