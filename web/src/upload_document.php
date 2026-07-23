<?php

require 'auth.php';
session_start();
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
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category = trim($_POST['category']);
    if (empty($message)) {
        $file = $_FILES['file'];
        $allowed = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];
        if (!in_array($file['type'], $allowed)) {
            $message = "Type de fichier non autorisé";
        }
        else {
            $upload_dir = "documents/uploads/";
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir,0755,true);
            }
            // Nom sécurisé
            $extension = pathinfo(
                $file['name'],
                PATHINFO_EXTENSION
            );
            $filename = uniqid("doc_").".".$extension;
            $destination = $upload_dir.$filename;
            if (move_uploaded_file(
                $file['tmp_name'],
                $destination
            )) {
                $sql="
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
                ";
                $stmt=$db->prepare($sql);
                $stmt->execute([

                    $title,
                    $description,
                    $category,
                    $filename,
                    $file['name'],
                    $file['type'],
                    $file['size'],
                    $user['id']

                ]);
                $message="Document ajouté avec succès";
                audit(
                        'ADD_DOCUMENT',
                        "Ajout du document '$title'");

                header("Location: documents.php");
                exit;
            }
            else {
                $message="Impossible de déplacer le fichier";
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
            <strong>Ajouter un documents</strong>
        </div>
        <div class="card-body">
            <div class="container mt-4">
                <?php if($message): ?>
                    <div class="alert alert-info"><?=htmlspecialchars($message)?>
                </div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data">
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
                        <input type="file" class="form-control" name="file" required>
                    </div>
                    <button class="btn btn-primary"><i class="bi bi-upload"></i>Envoyer</button>
                    <a href="documents.php" class="btn btn-secondary">Retour</a>
                </form>
            </div>
        </div>
    </div>
</main>
<?php require "includes/footer.php"; ?>