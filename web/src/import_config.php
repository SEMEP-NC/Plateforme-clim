<?php
/*
|--------------------------------------------------------------------------
| IMPORT_CONFIG.PHP
|--------------------------------------------------------------------------
| Restaure une sauvegarde générée par export_config.php, en deux temps :
|
|   1) Upload du fichier + choix des sections
|      -> le fichier est validé, analysé, stocké temporairement hors
|         du webroot, et un APERÇU (comptage par table) est affiché.
|
|   2) Confirmation explicite
|      -> la restauration réelle n'est appliquée qu'après validation
|         de l'aperçu par l'administrateur, dans une transaction SQL.
|
| Réservé aux administrateurs.
*/

require 'auth.php';
require_admin();
require 'config/db.php';
require 'lib/audit.php';

$db = get_db();

$TMP_DIR = '/var/www/storage/tmp_backups/';
$TOKEN_TTL = 900; // 15 minutes

if (!is_dir($TMP_DIR)) {
    @mkdir($TMP_DIR, 0700, true);
}

/*
|--------------------------------------------------------------------------
| Nettoyage des aperçus expirés (best effort, à chaque requête)
|--------------------------------------------------------------------------
*/
function cleanup_pending_restores($TMP_DIR, $TOKEN_TTL) {
    if (!empty($_SESSION['pending_restore'])) {
        foreach ($_SESSION['pending_restore'] as $token => $info) {
            if (time() - $info['created_at'] > $TOKEN_TTL) {
                if (!empty($info['path']) && is_file($info['path'])) {
                    @unlink($info['path']);
                }
                unset($_SESSION['pending_restore'][$token]);
            }
        }
    }
    // Fichiers orphelins (ex. session perdue) plus vieux qu'1h
    foreach (glob($TMP_DIR . '*.json') as $file) {
        if (time() - filemtime($file) > 3600) {
            @unlink($file);
        }
    }
}
cleanup_pending_restores($TMP_DIR, $TOKEN_TTL);

function insert_rows(PDO $db, string $table, array $rows) {
    if (empty($rows)) {
        return;
    }
    $columns = array_keys($rows[0]);
    $columnList = implode(',', array_map(fn($c) => "`$c`", $columns));
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $stmt = $db->prepare("INSERT INTO `$table` ($columnList) VALUES ($placeholders)");
    foreach ($rows as $row) {
        $values = [];
        foreach ($columns as $c) {
            $values[] = $row[$c];
        }
        $stmt->execute($values);
    }
}

function upsert_single($db, string $table, array $row) {
    $cols = array_keys($row);
    $colList = implode(',', array_map(fn($c) => "`$c`", $cols));
    $set = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", $cols));
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $db->prepare("INSERT INTO `$table` ($colList) VALUES ($ph) ON DUPLICATE KEY UPDATE $set")
       ->execute(array_values($row));
}

$message = null;
$messageType = 'danger';
$preview = null;

/*
|--------------------------------------------------------------------------
| ETAPE 2 : confirmation -> exécution réelle de la restauration
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmed'])) {
    verify_csrf();

    $token = $_POST['confirm_token'] ?? '';
    $sections = $_POST['sections'] ?? [];

    if (empty($token) || empty($_SESSION['pending_restore'][$token])) {
        $message = "Cet aperçu a expiré ou est invalide. Merci de réimporter le fichier.";
    } else {
        $info = $_SESSION['pending_restore'][$token];

        if (!is_file($info['path'])) {
            $message = "Le fichier temporaire de restauration est introuvable (expiré). Merci de réimporter le fichier.";
            unset($_SESSION['pending_restore'][$token]);
        } else {
            $raw = file_get_contents($info['path']);
            $data = json_decode($raw, true);

            $restoreEquipments = in_array('equipments', $sections);
            $restoreSchedules  = in_array('schedules', $sections);
            $restoreAdmin      = in_array('admin', $sections);

            try {
                $db->beginTransaction();
                $db->exec('SET FOREIGN_KEY_CHECKS=0');

                $restored = [];
                $createdUsers = [];

                if ($restoreEquipments) {
                    $db->exec('DELETE FROM equipment_groups');
                    $db->exec('DELETE FROM groups_hvac');
                    $db->exec('DELETE FROM equipments');

                    insert_rows($db, 'equipments', $data['equipments'] ?? []);
                    insert_rows($db, 'groups_hvac', $data['groups_hvac'] ?? []);
                    insert_rows($db, 'equipment_groups', $data['equipment_groups'] ?? []);

                    $restored[] = 'Équipements & groupes';
                }

                if ($restoreSchedules) {
                    $db->exec('DELETE FROM schedules');
                    insert_rows($db, 'schedules', $data['schedules'] ?? []);

                    $restored[] = 'Plannings';
                }

                if ($restoreAdmin) {
                    $db->exec('DELETE FROM equipment_temperature_alarms');
                    $db->exec('DELETE FROM discovery_config');
                    $db->exec('DELETE FROM mail_recipients');

                    insert_rows($db, 'equipment_temperature_alarms', $data['equipment_temperature_alarms'] ?? []);
                    insert_rows($db, 'discovery_config', $data['discovery_config'] ?? []);
                    insert_rows($db, 'mail_recipients', $data['mail_recipients'] ?? []);

                    foreach (($data['settings'] ?? []) as $row) {
                        $stmt = $db->prepare("
                            INSERT INTO settings (`key`, `value`)
                            VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
                        ");
                        $stmt->execute([$row['key'], $row['value']]);
                    }

                    foreach (($data['mail_accounts'] ?? []) as $row) {
                        upsert_single($db, 'mail_accounts', $row);
                    }

                    foreach (($data['mail_config'] ?? []) as $row) {
                        upsert_single($db, 'mail_config', $row);
                    }

                    // Utilisateurs : jamais de mot de passe restauré.
                    foreach (($data['users'] ?? []) as $row) {
                        $stmt = $db->prepare("SELECT id FROM users WHERE username=?");
                        $stmt->execute([$row['username']]);
                        $existing = $stmt->fetch();

                        if ($existing) {
                            $db->prepare("UPDATE users SET role=? WHERE id=?")
                               ->execute([$row['role'], $existing['id']]);
                        } else {
                            $tempPassword = bin2hex(random_bytes(6));
                            $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
                            $db->prepare("
                                INSERT INTO users (username, password_hash, role)
                                VALUES (?, ?, ?)
                            ")->execute([$row['username'], $hash, $row['role']]);
                            $createdUsers[] = $row['username'] . ' (mot de passe temporaire : ' . $tempPassword . ')';
                        }
                    }

                    $restored[] = 'Administration (paramètres, mail, alarmes, utilisateurs)';
                }

                $db->exec('SET FOREIGN_KEY_CHECKS=1');
                $db->commit();

                @unlink($info['path']);
                unset($_SESSION['pending_restore'][$token]);

                audit(
                    'IMPORT_CONFIG',
                    'Restauration de configuration : ' . implode(', ', $restored)
                );

                $message = "Restauration effectuée avec succès : " . implode(', ', $restored) . ".";
                if (!empty($createdUsers)) {
                    $message .= "\nComptes utilisateurs créés — " . implode(' ; ', $createdUsers);
                }
                $messageType = 'success';
            } catch (Exception $e) {
                $db->exec('SET FOREIGN_KEY_CHECKS=1');
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $message = "Échec de la restauration : " . $e->getMessage();
            }
        }
    }
}
/*
|--------------------------------------------------------------------------
| ETAPE 1 : upload -> analyse et aperçu (rien n'est écrit en base ici)
|--------------------------------------------------------------------------
*/
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $sections = $_POST['sections'] ?? [];

    if (empty($sections)) {
        $message = "Sélectionnez au moins une section à restaurer.";
    } elseif (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $message = "Aucun fichier de sauvegarde valide n'a été reçu.";
    } else {
        $raw = file_get_contents($_FILES['file']['tmp_name']);
        $data = json_decode($raw, true);

        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            $message = "Le fichier sélectionné n'est pas une sauvegarde JSON valide.";
        } elseif (!isset($data['meta']['type']) || $data['meta']['type'] !== 'config_backup') {
            $message = "Ce fichier ne correspond pas à une sauvegarde de configuration reconnue.";
        } else {
            // Stockage temporaire hors webroot, nom aléatoire
            $token = bin2hex(random_bytes(16));
            $path = $TMP_DIR . $token . '.json';
            file_put_contents($path, $raw);
            chmod($path, 0600);

            $_SESSION['pending_restore'][$token] = [
                'path' => $path,
                'created_at' => time(),
                'sections' => $sections,
            ];

            // Comptage pour l'aperçu (lecture seule, aucune écriture en base)
            $restoreEquipments = in_array('equipments', $sections);
            $restoreSchedules  = in_array('schedules', $sections);
            $restoreAdmin      = in_array('admin', $sections);

            $counts = [];

            if ($restoreEquipments) {
                $counts['Équipements'] = count($data['equipments'] ?? []);
                $counts['Groupes'] = count($data['groups_hvac'] ?? []);
                $counts['Liaisons équipement ↔ groupe'] = count($data['equipment_groups'] ?? []);
            }

            if ($restoreSchedules) {
                $counts['Plannings'] = count($data['schedules'] ?? []);
            }

            $userPreview = ['new' => [], 'update' => []];

            if ($restoreAdmin) {
                $counts['Paramètres système'] = count($data['settings'] ?? []);
                $counts['Config détection'] = count($data['discovery_config'] ?? []);
                $counts['Alarmes température'] = count($data['equipment_temperature_alarms'] ?? []);
                $counts['Destinataires mail'] = count($data['mail_recipients'] ?? []);
                $counts['Comptes SMTP'] = count($data['mail_accounts'] ?? []);

                foreach (($data['users'] ?? []) as $row) {
                    $stmt = $db->prepare("SELECT id FROM users WHERE username=?");
                    $stmt->execute([$row['username']]);
                    if ($stmt->fetch()) {
                        $userPreview['update'][] = $row['username'] . ' (' . $row['role'] . ')';
                    } else {
                        $userPreview['new'][] = $row['username'] . ' (' . $row['role'] . ')';
                    }
                }
            }

            $preview = [
                'token' => $token,
                'sections' => $sections,
                'meta' => $data['meta'] ?? [],
                'counts' => $counts,
                'users' => $userPreview,
            ];
        }
    }
}

$page_title = "Restauration de configuration";
require "includes/header.php";
require "includes/user_menu.php";
?>
<main class="container flex-grow-1 mt-4 mb-5">

    <?php if ($message): ?>
        <div class="card mb-4">
            <div class="card-header">
                <strong>Résultat</strong>
            </div>
            <div class="card-body">
                <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
                    <?= nl2br(htmlspecialchars($message)) ?>
                </div>
                <a href="backup.php" class="btn btn-secondary">Retour</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($preview): ?>
        <div class="card mb-4">
            <div class="card-header">
                <strong>Aperçu de la restauration</strong>
            </div>
            <div class="card-body">

                <div class="alert alert-info">
                    <div><strong>Fichier :</strong>
                        exporté le
                        <?= htmlspecialchars($preview['meta']['exported_at'] ?? '?') ?>
                        par
                        <?= htmlspecialchars($preview['meta']['exported_by'] ?? '?') ?>
                    </div>
                </div>

                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    Cette action va <strong>remplacer</strong> les données existantes
                    des sections ci-dessous. Vérifiez les volumes avant de confirmer.
                </div>

                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Élément</th>
                            <th>Nombre de lignes dans la sauvegarde</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview['counts'] as $label => $count): ?>
                            <tr>
                                <td><?= htmlspecialchars($label) ?></td>
                                <td><span class="badge bg-secondary"><?= (int)$count ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (!empty($preview['users']['new']) || !empty($preview['users']['update'])): ?>
                    <h6 class="mt-4">Utilisateurs</h6>
                    <?php if (!empty($preview['users']['update'])): ?>
                        <p class="mb-1">
                            <strong><?= count($preview['users']['update']) ?> compte(s) existant(s)</strong>
                            verront uniquement leur rôle mis à jour (mot de passe conservé) :
                        </p>
                        <p class="text-muted"><?= htmlspecialchars(implode(', ', $preview['users']['update'])) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($preview['users']['new'])): ?>
                        <p class="mb-1">
                            <strong><?= count($preview['users']['new']) ?> compte(s) seront créés</strong>
                            avec un mot de passe temporaire généré aléatoirement :
                        </p>
                        <p class="text-muted"><?= htmlspecialchars(implode(', ', $preview['users']['new'])) ?></p>
                    <?php endif; ?>
                <?php endif; ?>

                <form method="POST" class="d-flex gap-2 mt-4">
                    <input type="hidden" name="csrf_token" value="<?=csrf_token()?>">
                    <input type="hidden" name="confirm_token" value="<?= htmlspecialchars($preview['token']) ?>">
                    <input type="hidden" name="confirmed" value="1">
                    <?php foreach ($preview['sections'] as $s): ?>
                        <input type="hidden" name="sections[]" value="<?= htmlspecialchars($s) ?>">
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-check-circle"></i>
                        Confirmer la restauration
                    </button>
                    <a href="backup.php" class="btn btn-secondary">Annuler</a>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$message && !$preview): ?>
        <div class="card mb-4">
            <div class="card-body">
                <a href="backup.php" class="btn btn-secondary">Retour</a>
            </div>
        </div>
    <?php endif; ?>

</main>
<?php require "includes/footer.php"; ?>
