<?php
require 'auth.php';
session_start();
require_login();

require "includes/header.php";
require "includes/user_menu.php";

require "db.php";


$sql = "
SELECT d.*, u.username
FROM documents d
LEFT JOIN users u ON d.uploaded_by=u.id
ORDER BY created_at DESC
";

$stmt=$pdo->query($sql);
$documents=$stmt->fetchAll();

?>


<div class="container mt-4">

<div class="d-flex justify-content-between mb-4">

<h2>
<i class="bi bi-folder2-open"></i>
Documents
</h2>


<?php if($_SESSION['user']['role']=='admin'): ?>

<a href="upload_document.php"
class="btn btn-primary">

<i class="bi bi-upload"></i>
Ajouter

</a>

<?php endif; ?>


</div>


<div class="row g-4">


<?php foreach($documents as $doc): ?>

<div class="col-md-4">

<div class="card shadow-sm h-100">

<div class="card-body">


<h5>
<?=htmlspecialchars($doc['title'])?>
</h5>


<p class="text-muted">
<?=htmlspecialchars($doc['description'])?>
</p>


<span class="badge bg-secondary">
<?=$doc['category']?>
</span>


<p class="mt-3 small">

Ajouté par :
<?=$doc['username']?>


<br>

Date :
<?=date('d/m/Y',strtotime($doc['created_at']))?>

</p>


<a class="btn btn-success"
href="download_document.php?id=<?=$doc['id']?>">

<i class="bi bi-download"></i>
Télécharger

</a>


</div>

</div>

</div>


<?php endforeach; ?>


</div>

</div>


<?php require "includes/footer.php"; ?>