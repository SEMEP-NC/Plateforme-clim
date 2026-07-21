<?php
require 'auth.php';
session_start();
require_login();
require 'config/db.php';

$db = get_db();

/*
|--------------------------------------------------------------------------
| DATA
|--------------------------------------------------------------------------
*/

$groups = $db->query("
    SELECT * FROM groups_hvac ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$equipments = $db->query("
    SELECT * FROM equipments ORDER BY UI
")->fetchAll(PDO::FETCH_ASSOC);

$localisations = [];

foreach ($equipments as $equipment) {
    if (!empty($equipment['localisation'])) {
        $localisations[] = $equipment['localisation'];
    }
}

$localisations = array_unique($localisations);
sort($localisations);
/* relations */
$equipmentGroups = [];
$groupEquipments = [];

$stmt = $db->query("
    SELECT equipment_id, group_id FROM equipment_groups
");

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $equipmentGroups[$row['equipment_id']][] = $row['group_id'];
    $groupEquipments[$row['group_id']][] = $row['equipment_id'];
}
    $page_title = "Vue des equipements";
    require "includes/header.php";
    require "includes/user_menu.php";
?>
    <style>
        .page-title {
            font-size:2rem;
        }

        .card {
            border:none;
            border-radius:15px;
            box-shadow:0 4px 15px rgba(0,0,0,.08);
        }

        .sortable {
            cursor:pointer;
            user-select:none;
        }

        .sortable:hover {
            background:#eef5ff;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom"></script>
    <script src="https://cdn.jsdelivr.net/npm/date-fns"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns"></script>
    <main class="container flex-grow-1 mt-4">


        <!-- ========================= EQUIPMENTS ========================= -->
        <div class="card mb-4">
            <div class="card-header">
                <strong>Unités</strong>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped align-middle" id="equipmentsTable">
                            <thead>
                                <tr>
                                    <th>Localisation
                                        <div class="dropdown d-inline">
                                            <button 
                                                class="btn btn-sm btn-light"
                                                type="button"
                                                data-bs-toggle="dropdown">
                                                🔽
                                            </button>
                                            <ul class="dropdown-menu p-2" style="max-height:250px;overflow:auto">
                                                <?php foreach ($localisations as $loc): ?>
                                                    <li>
                                                        <label class="dropdown-item">
                                                            <input 
                                                                type="checkbox"
                                                                class="form-check-input me-2 localisation-filter"
                                                                value="<?= htmlspecialchars($loc) ?>">
                                                            <?= htmlspecialchars($loc) ?>
                                                        </label>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </th>
                                    <th data-sort="name" class="sortable">
                                        Nom <span>↕</span>
                                    </th>

                                    <th data-sort="ui" class="sortable">
                                        UI <span>↕</span>
                                    </th>
                                    
                                    <th data-sort="state" class="sortable">
                                        État <span>↕</span>
                                    </th>

                                    <th data-sort="fault" class="sortable">
                                        Défaut <span>↕</span>
                                    </th>

                                    <th data-sort="temp" class="sortable">
                                        Temp reprise <span>↕</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($equipments as $equipment): ?>
                                    <tr>
                                        <td data-localisation="<?= htmlspecialchars($equipment['localisation'] ?? '') ?>">
                                            <?= htmlspecialchars($equipment['localisation'] ?? '') ?>
                                        </td>
                                        <td data-sort="<?= htmlspecialchars($equipment['name']) ?>">
                                            <?= htmlspecialchars($equipment['name']) ?>
                                        </td>

                                        
                                        <td data-sort="<?= (int)$equipment['UI'] ?>">
                                            <?= htmlspecialchars($equipment['UI']) ?>
                                        </td>
                                        <td data-sort="<?= !empty($equipment['state']) ? 1 : 0 ?>">
                                            <?php if (!empty($equipment['state'])): ?>
                                                <span class="badge bg-success">ON</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">OFF</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-sort="<?= !empty($equipment['fault']) ? 1 : 0 ?>">
                                            <?php if (!empty($equipment['fault'])): ?>
                                                <span class="badge bg-danger blink">DÉFAUT</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">NORMAL</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-sort="<?= $equipment['return_temp'] ?? -999 ?>">
                                            <?= $equipment['return_temp'] !== null
                                                ? number_format($equipment['return_temp'], 1) . ' °C'
                                                : '-' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </div>

    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const equipModalEl = document.getElementById("commandModal");
        const equipModal = new bootstrap.Modal(equipModalEl);

        const groupModalEl = document.getElementById("groupCommandModal");
        const groupModal = new bootstrap.Modal(groupModalEl);

        let lastReadRegisters = [];
        let currentEquipmentId = null;
        let currentGroupId = null;

        /* =========================
        TRI TABLE UNITÉS
        ========================= */

        const sortDirections = {};

        document.querySelectorAll("#equipmentsTable th.sortable").forEach(th => {

            th.addEventListener("click", function () {

                const table = document.getElementById("equipmentsTable");
                const tbody = table.querySelector("tbody");

                const key = this.dataset.sort;

                // position réelle de la colonne cliquée
                const colIndex = Array.from(
                    this.parentElement.children
                ).indexOf(this);

                sortDirections[key] = !sortDirections[key];


                const rows = Array.from(tbody.querySelectorAll("tr"));

                rows.sort((a, b) => {

                    let cellA = a.cells[colIndex];
                    let cellB = b.cells[colIndex];

                    let valA = "";
                    let valB = "";


                    // Cas Nom avec input admin
                    const inputA = cellA.querySelector("input");
                    const inputB = cellB.querySelector("input");

                    if (inputA) {
                        valA = inputA.value;
                    } else {
                        valA = cellA.innerText;
                    }

                    if (inputB) {
                        valB = inputB.value;
                    } else {
                        valB = cellB.innerText;
                    }


                    valA = valA.trim();
                    valB = valB.trim();


                    // Colonnes numériques
                    if (["ui", "temp"].includes(key)) {

                        let numA = parseFloat(valA.replace(",", "."));
                        let numB = parseFloat(valB.replace(",", "."));

                        numA = isNaN(numA) ? -9999 : numA;
                        numB = isNaN(numB) ? -9999 : numB;

                        return sortDirections[key]
                            ? numB - numA
                            : numA - numB;
                    }


                    return sortDirections[key]
                        ? valB.localeCompare(valA, "fr")
                        : valA.localeCompare(valB, "fr");

                });


                rows.forEach(row => tbody.appendChild(row));


                // Mise à jour icônes
                document.querySelectorAll("#equipmentsTable th.sortable span")
                    .forEach(span => span.textContent = "↕");

                this.querySelector("span").textContent =
                    sortDirections[key] ? "↓" : "↑";

            });

        });
        /* =========================
        FILTRE TABLE UNITÉS
        ========================= */
        document.querySelectorAll(".localisation-filter").forEach(cb => {
            cb.addEventListener("change", function () {
                const selected = Array.from(
                    document.querySelectorAll(".localisation-filter:checked")
                )
                .map(cb => cb.value.toLowerCase());

                document
                    .querySelectorAll("#equipmentsTable tbody tr")
                    .forEach(row => {
                        const localisation = row.cells[0]
                            .dataset.localisation
                            .toLowerCase();

                        if (
                            selected.length === 0 ||
                            selected.includes(localisation)
                        ) {
                            row.style.display = "";
                        } else {
                            row.style.display = "none";
                        }
                    });
            });
        });
    </script>
<?php require "includes/footer.php"; ?>
