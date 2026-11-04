<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$isVisitor = user()['role'] === 'visitor';
$me        = $isVisitor ? my_adopter() : null;

$species = $_GET['species'] ?? '';
if (!in_array($species, ['Dog', 'Cat'], true)) $species = '';

// Reads the v_available_pets view (only pets with status 'Available').
// Visitors with a profile also get their personal match score from fn_match_score().
$where  = $species !== '' ? ' WHERE v.species = ?' : '';
$params = $species !== '' ? [$species] : [];
if ($me) {
    $sql = 'SELECT v.*, fn_match_score(v.pet_id, ?, ?, ?, ?, ?) AS score FROM v_available_pets v' . $where . ' ORDER BY score DESC, v.name';
    $params = array_merge([$me['housing_type'], $me['has_kids'], $me['has_other_pets'], $me['activity_level'], $me['preferred_species']], $params);
} else {
    $sql = 'SELECT v.* FROM v_available_pets v' . $where . ' ORDER BY v.name';
}
$pets = q($sql, $params)->fetchAll();

layout_start('Browse pets', 'These friends are waiting for a home.');
?>
<?php if ($isVisitor && !$me): ?>
  <div class="msg">Want to adopt? <a href="profile.php">Fill in your profile</a> to see match scores and apply.</div>
<?php endif; ?>
<form method="get" class="search">
  <label style="margin:0">Show</label>
  <select name="species" onchange="this.form.submit()">
    <option value="">All pets</option>
    <option <?= $species === 'Dog' ? 'selected' : '' ?>>Dog</option>
    <option <?= $species === 'Cat' ? 'selected' : '' ?>>Cat</option>
  </select>
  <noscript><button>Filter</button></noscript>
</form>

<div class="cards">
  <?php foreach ($pets as $p): ?>
  <div class="card">
    <h2><?= e($p['name']) ?> <span class="tag"><?= e($p['species']) ?></span></h2>
    <div><?= e($p['breed']) ?> &middot; <?= e($p['sex']) ?> &middot; <?= (int)$p['age_months'] ?> months</div>
    <div>Size: <?= e($p['size']) ?> &middot; Energy: <?= e($p['energy_level']) ?></div>
    <div><?= $p['is_vaccinated'] ? 'Vaccinated' : 'Not yet vaccinated' ?></div>
    <p><?= e($p['description']) ?></p>
    <?php if ($p['traits']): ?><div class="muted"><?= e($p['traits']) ?></div><?php endif; ?>
    <?php if ($me): ?>
      <p>Your match: <span class="bar"><span style="width:<?= (int)$p['score'] ?>%"></span></span> <?= (int)$p['score'] ?>%</p>
    <?php endif; ?>
    <?php if ($isVisitor): ?>
      <form method="post" action="apply.php" data-confirm="Apply to adopt <?= e($p['name']) ?>?">
        <?= csrf_field() ?><input type="hidden" name="pet_id" value="<?= (int)$p['pet_id'] ?>">
        <button>Apply to adopt</button>
      </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if (!$pets): ?><p class="muted">No available pets right now. Check back soon!</p><?php endif; ?>
</div>
<?php layout_end();
