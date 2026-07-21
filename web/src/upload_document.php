<?php

require 'auth.php';
session_start();
require_login();

require 'config/db.php';
$db = get_db();

$user = $_SESSION['user'];

// Seulement admin
if ($user['role'] !== 'admin') {
    die("Accès refusé");
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category = trim($_POST['category']);
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== 0) {
        $message = "Erreur lors de l'envoi du fichier";
    } else {
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
        elseif ($file['size'] > 20 * 1024 * 1024) {
            $message = "Fichier trop volumineux (20 Mo maximum)";
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
<div class="container mt-4">
    <h2></i>Ajouter un document</h2>
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
<?php require "includes/footer.php"; ?>