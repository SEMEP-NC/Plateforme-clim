<?php
/*
|--------------------------------------------------------------------------
| IMPORT_CONFIG.PHP
|--------------------------------------------------------------------------
| Restaure une sauvegarde générée par export_config.php.
| Réservé aux administrateurs. La restauration se fait par sections,
| choisies par l'utilisateur (équipements/groupes, plannings, admin),
| dans une transaction : soit tout est appliqué, soit rien ne l'est.
*/

require 'auth.php';
require_admin();
require 'config/db.php';
require 'lib/audit.php';

$db = get_db();

$message = null;
$messageType = 'danger';

function insert_rows(PDO $db, string $table, array $rows) {
    if (empty($rows)) {
        return;
    }

    // Toutes les lignes d'une sauvegarde partagent le même jeu de colonnes
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $sections = $_POST['sections'] ?? [];
    $restoreEquipments = in_array('equipments', $sections);
    $restoreSchedules  = in_array('schedules', $sections);
    $restoreAdmin      = in_array('admin', $sections);

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
            try {
                $db->beginTransaction();
                $db->exec('SET FOREIGN_KEY_CHECKS=0');

                $restored = [];

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

                    // settings : mise à jour clé par clé (table de configuration simple)
                    foreach (($data['settings'] ?? []) as $row) {
                        $stmt = $db->prepare("
                            INSERT INTO settings (`key`, `value`)
                            VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
                        ");
                        $stmt->execute([$row['key'], $row['value']]);
                    }

                    // mail_accounts / mail_config : lignes uniques (id=1), mise à jour
                    foreach (($data['mail_accounts'] ?? []) as $row) {
                        $cols = array_keys($row);
                        $set = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", $cols));
                        $colList = implode(',', array_map(fn($c) => "`$c`", $cols));
                        $ph = implode(',', array_fill(0, count($cols), '?'));
                        $db->prepare("
                            INSERT INTO mail_accounts ($colList) VALUES ($ph)
                            ON DUPLICATE KEY UPDATE $set
                        ")->execute(array_values($row));
                    }

                    foreach (($data['mail_config'] ?? []) as $row) {
                        $cols = array_keys($row);
                        $set = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", $cols));
                        $colList = implode(',', array_map(fn($c) => "`$c`", $cols));
                        $ph = implode(',', array_fill(0, count($cols), '?'));
                        $db->prepare("
                            INSERT INTO mail_config ($colList) VALUES ($ph)
                            ON DUPLICATE KEY UPDATE $set
                        ")->execute(array_values($row));
                    }

                    // Utilisateurs : on ne restaure jamais les mots de passe.
                    // Les comptes existants voient uniquement leur rôle mis à jour.
                    // Les comptes absents de la base sont recréés avec un mot de
                    // passe temporaire aléatoire (à communiquer / réinitialiser).
                    $createdUsers = [];
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

                audit(
                    'IMPORT_CONFIG',
                    'Restauration de configuration : ' . implode(', ', $restored)
                );

                $message = "Restauration effectuée avec succès : " . implode(', ', $restored) . ".";
                if (!empty($createdUsers)) {
                    $message .= " Comptes utilisateurs créés — " . implode(' ; ', $createdUsers);
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

$page_title = "Restauration de configuration";
require "includes/header.php";
require "includes/user_menu.php";
?>
<main class="container flex-grow-1 mt-4">
    <div class="card mb-4">
        <div class="card-header">
            <strong>Résultat de la restauration</strong>
        </div>
        <div class="card-body">
            <?php if ($message): ?>
                <div class="alert alert-<?= htmlspecialchars($messageType) ?>">
                    <?= nl2br(htmlspecialchars($message)) ?>
                </div>
            <?php endif; ?>
            <a href="backup.php" class="btn btn-secondary">Retour</a>
        </div>
    </div>
</main>
<?php require "includes/footer.php"; ?>
