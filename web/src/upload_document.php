<?php

require 'auth.php';
require_login();

require 'config/db.php';
$db = get_db();
require 'lib/audit.php';

$user = $_SESSION['user'];

// Seulement admin
if ($user['role'] !== 'admin') {
    die("Accès refusé");
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $file = $_FILES['file'] ?? null;
    if (!$file) {
        $message = "Aucun fichier reçu.";
    } else {
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $message = "Le fichier dépasse la taille maximale autorisée.";
                break;
            case UPLOAD_ERR_NO_FILE:
                $message = "Aucun fichier sélectionné.";
                break;
            default:
                $message = "Erreur lors du téléversement.";
        }
    }
    $title = mb_substr(trim($_POST['title'] ?? ''),0,100);
    $description = mb_substr(
        trim($_POST['description'] ?? ''),
        0,
        1000
    );
    $category = mb_substr(
        trim($_POST['category'] ?? ''),
        0,
        50
    );
    if (empty($message)) {
        $file = $_FILES['file'];
        $allowed_types = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];
        // Taille maximale 20 Mo
        if ($file['size'] > 20 * 1024 * 1024) {
            $message = "Fichier trop volumineux (20 Mo maximum).";
        }
        else {
            // Analyse réelle du fichier
            if (!is_uploaded_file($file['tmp_name'])) {
                $message = "Fichier uploadé invalide.";
            }
            else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                if (!array_key_exists($mime, $allowed_types)) {
                    $message = "Type de fichier non autorisé.";
                }
                else {
                    // Extension imposée par le serveur
                    $extension = $allowed_types[$mime];
                    $upload_dir = "/var/www/storage/documents/";
                    if (!is_dir($upload_dir)) {
                        if (!mkdir($upload_dir,0750,true)) {
                            $message = "Impossible de créer le dossier de stockage.";
                        }
                    }
                    if (empty($message)) {
                        // Nom aléatoire sécurisé
                        $filename = bin2hex(random_bytes(20))
                                . "."
                                . $extension;
                        $destination = $upload_dir . $filename;
                        if (move_uploaded_file(
                            $file['tmp_name'],
                            $destination
                        )) {
                            chmod($destination,0640);
                            $stmt=$db->prepare(
                            "
                            INSERT INTO documents
                            (
                                title,
                                description,
                                category,
                                filename,
                                original_name,
                                mime_type,
                                file_size,
                                uploaded_by
                            )
                            VALUES
                            (?,?,?,?,?,?,?,?)
                            "
                            );
                            $stmt->execute([
                                $title,
                                $description,
                                $category,
                                $filename,
                                mb_substr(
                                    basename($file['name']),
                                    0,
                                    255
                                ),
                                $mime,
                                $file['size'],
                                $user['id']
                            ]);
                            audit(
                                'ADD_DOCUMENT',
                                "Ajout du document '$title'"
                            );
                            header("Location: documents.php");
                            exit;
                        }
                        else {
                            $message = "Impossible de déplacer le fichier.";
                        }
                    }
                }
            }
        }
    }
}
$page_title = "Gestion documentaire";
require "includes/header.php";
require "includes/user_menu.php";
?>
<main class="container flex-grow-1 mt-4">
    <div class="card mb-4">
        <div class="card-header">
            <strong>Ajouter un document</strong>
        </div>
        <div class="card-body">
            <div class="container mt-4">
                <?php if($message): ?>
                    <div class="alert alert-info"><?=htmlspecialchars($message)?>
                </div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?=csrf_token()?>">
                    <div class="mb-3">
                        <label class="form-label">Titre</label>
                        <input class="form-control" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label>Description</label>
                        <textarea class="form-control" name="description"></textarea>
                    </div>
                    <div class="mb-3">
                        <label>Catégorie</label>
                        <select name="category" class="form-control">
                            <option>Notice</option>
                            <option>Procédure</option>
                            <option>Plan</option>
                            <option>Rapport</option>
                            <option>Autre</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label>Fichier</label>
                        <input type="file" class="form-control" name="file" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                    <button class="btn btn-primary"><i class="bi bi-upload"></i>Envoyer</button>
                    <a href="documents.php" class="btn btn-secondary">Retour</a>
                </form>
            </div>
        </div>
    </div>
</main>
<?php require "includes/footer.php"; ?>