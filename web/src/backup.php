<?php
require 'auth.php';
require_admin();
require 'config/db.php';

$db = get_db();

$equipments = $db->query("
    SELECT id, name, UI FROM equipments ORDER BY UI ASC, name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Sauvegarde & Restauration";
require "includes/header.php";
require "includes/user_menu.php";
?>
<main class="container flex-grow-1 mt-4 mb-5">

    <!-- ========================= SAUVEGARDE CONFIG ========================= -->
    <div class="card mb-4">
        <div class="card-header">
            <strong>Sauvegarde de la configuration</strong>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Télécharge un fichier JSON contenant les équipements, les groupes,
                les plannings et les paramètres d'administration
                (comptes SMTP, destinataires mail, alarmes température,
                paramètres système, rôles utilisateurs).
                Les mots de passe ne sont jamais inclus dans la sauvegarde.
            </p>
            <a href="export_config.php" class="btn btn-primary">
                <i class="bi bi-download"></i>
                Télécharger une sauvegarde
            </a>
        </div>
    </div>

    <!-- ========================= RESTAURATION CONFIG ========================= -->
    <div class="card mb-4">
        <div class="card-header">
            <strong>Restauration de la configuration</strong>
        </div>
        <div class="card-body">
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                La restauration remplace les données existantes des sections
                sélectionnées. Cette action est irréversible : téléchargez une
                sauvegarde récente avant de continuer.
            </div>
            <form method="POST" action="import_config.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?=csrf_token()?>">

                <div class="mb-3">
                    <label class="form-label">Fichier de sauvegarde (.json)</label>
                    <input type="file" class="form-control" name="file" accept=".json,application/json" required>
                </div>

                <div class="mb-3">
                    <label class="form-label d-block">Sections à restaurer</label>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="sections[]" value="equipments" id="secEquip">
                        <label class="form-check-label" for="secEquip">
                            Équipements &amp; groupes
                        </label>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="sections[]" value="schedules" id="secSched">
                        <label class="form-check-label" for="secSched">
                            Plannings
                        </label>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="sections[]" value="admin" id="secAdmin">
                        <label class="form-check-label" for="secAdmin">
                            Administration (paramètres système, SMTP, destinataires
                            mail, alarmes température, rôles utilisateurs)
                        </label>
                    </div>
                </div>

                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-upload"></i>
                    Restaurer
                </button>
            </form>
        </div>
    </div>

    <!-- ========================= EXPORT HISTORIQUE CSV ========================= -->
    <div class="card mb-4">
        <div class="card-header">
            <strong>Export CSV de l'historique des équipements</strong>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Exporte l'historique (température, état ON/OFF, défauts...)
                d'un équipement ou de tous les équipements, sur une période donnée.
            </p>
            <form method="GET" action="export_history_csv.php" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Équipement</label>
                    <select name="id" class="form-select">
                        <option value="0">Tous les équipements</option>
                        <?php foreach ($equipments as $eq): ?>
                            <option value="<?= (int)$eq['id'] ?>">
                                UI <?= (int)$eq['UI'] ?> - <?= htmlspecialchars($eq['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date début</label>
                    <input type="date" class="form-control" name="date_start">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date fin</label>
                    <input type="date" class="form-control" name="date_end">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-success w-100">
                        <i class="bi bi-file-earmark-spreadsheet"></i>
                        Export CSV
                    </button>
                </div>
            </form>
        </div>
    </div>

    <a href="admin.php" class="btn btn-outline-secondary">Retour administration</a>
</main>
<script>
document.querySelector('form[action="import_config.php"]').addEventListener('submit', function(e){
    const checked = this.querySelectorAll('input[name="sections[]"]:checked');
    if (checked.length === 0) {
        e.preventDefault();
        alert("Sélectionnez au moins une section à restaurer.");
        return;
    }
    if (!confirm("Confirmez-vous la restauration ? Les données existantes des sections sélectionnées seront remplacées.")) {
        e.preventDefault();
    }
});
</script>
<?php require "includes/footer.php"; ?>
