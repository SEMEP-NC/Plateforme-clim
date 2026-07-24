<?php
require 'auth.php';
require_login();
require "config/db.php";
$page_title = "Gestion documentaire";
require "includes/header.php";
require "includes/user_menu.php";

$db = get_db();

$category = $_GET['category'] ?? '';

$sql = "
SELECT d.*, u.username
FROM documents d
LEFT JOIN users u ON d.uploaded_by=u.id
";

$params = [];

if ($category !== '') {
    $sql .= " WHERE d.category = ? ";
    $params[] = $category;
}

$sql .= " ORDER BY d.created_at DESC ";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

// Liste des catégories existantes
$stmt = $db->query("
    SELECT DISTINCT category
    FROM documents
    ORDER BY category
");

$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

?>
<style>
    .document-preview {
        height:180px;
        overflow:hidden;
        background:#f8f9fa;
        display:flex;
        align-items:center;
        justify-content:center;
    }

    .document-preview iframe {
        pointer-events:none;
    }
</style>
<main class="container flex-grow-1 mt-4">
    <div class="card mb-4">
        <div class="card-header">
            <strong>Documents</strong>
        </div>
        <div class="card-body">
            <div class="d-flex justify-content-between mb-4">
                <form method="get" class="d-flex gap-2">
                    <select name="category"
                            class="form-select"
                            onchange="this.form.submit()">
                        <option value="">Toutes les catégories</option>
                        <?php foreach($categories as $cat): ?>
                            <option value="<?=htmlspecialchars($cat)?>"<?php if($category==$cat) echo "selected"; ?>><?=htmlspecialchars($cat)?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if($category): ?>
                    <a href="documents.php" class="btn btn-outline-secondary">Réinitialiser</a>
                    <?php endif; ?>
                </form>
                <?php if($_SESSION['user']['role']=='admin'): ?>
                    <a href="upload_document.php" class="btn btn-primary"><i class="bi bi-upload"></i>Ajouter</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card mb-4">
        <div class="card-header">
            <strong>Documents</strong>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <?php foreach($documents as $doc): ?>
                    <div class="col-md-4">
                        <div class="card shadow-sm h-100">
                            <?php
                            $file = "documents/uploads/".$doc['filename'];
                            $extension = strtolower(
                                pathinfo($doc['filename'], PATHINFO_EXTENSION)
                            );
                            ?>
                            <!-- PREVISUALISATION -->
                            <div class="row">
                                <div class="col-md-7">
                                    <div class="card-body">
                                        <h5><?=htmlspecialchars($doc['title'])?></h5>
                                        <p class="text-muted">
                                            <?=htmlspecialchars($doc['description'])?>
                                        </p>
                                        <span class="badge bg-secondary">
                                            <?=$doc['category']?>
                                        </span>
                                        <p class="mt-3 small">Ajouté par :<?=$doc['username']?>
                                        <br>Date :<?=date('d/m/Y',strtotime($doc['created_at']))?>
                                        </p>
                                        <a class="btn btn-success" href="<?=$file?>" target="_blank">
                                        <i class="bi bi-eye"></i>Visualiser</a>
                                        <?php if($_SESSION['user']['role']=='admin'): ?>
                                            <a class="btn btn-danger"
                                            href="delete_documents.php?id=<?=$doc['id']?>"
                                            onclick="return confirm('Supprimer ce document ?')">
                                            <i class="bi bi-trash"></i>Supprimer</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-5">
                                    <div class="document-preview">
                                        <?php if($extension === 'pdf'): ?>
                                            <iframe
                                                src="<?=$file?>"
                                                width="100%"
                                                height="180"
                                                style="border:0">
                                            </iframe>
                                        <?php elseif(in_array($extension,['jpg','jpeg','png'])): ?>
                                            <img 
                                            src="<?=$file?>"
                                            class="img-fluid"
                                            style="
                                                height:180px;
                                                width:100%;
                                                object-fit:cover;
                                            ">
                                        <?php elseif(in_array($extension,['doc','docx'])): ?>
                                            <div class="text-center p-5">
                                                <i class="bi bi-file-earmark-word text-primary"
                                                style="font-size:60px">
                                                </i>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-center p-5">
                                                <i class="bi bi-file-earmark text-secondary"
                                                style="font-size:60px">
                                                </i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>                  
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</main>
<?php require "includes/footer.php"; ?>
