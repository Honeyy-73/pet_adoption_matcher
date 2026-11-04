<?php
// Retro "pixel window" layout: title bar, blue banner, blue menu panel, status bar.

/** Draws the pixel cat as an SVG built from a character grid. */
function pixel_cat(int $px = 8): string {
    $grid = [
        '..O........O..',
        '.OWO......OWO.',
        '.OWWOOOOOOWWO.',
        'OWWWWWWWWWWWWO',
        'OWWWWWWWWWWWWO',
        'OWWKKWWWWKKWWO',
        'OWCWWWWWWWWCWO',
        'OWWWWWPPWWWWWO',
        'OWWWWWWWWWWWWO',
        '.OWWWWWWWWWWO.',
        '..OOOOOOOOOO..',
    ];
    $colors = ['O' => '#4a4e8a', 'W' => '#fdf6ee', 'K' => '#1a1a40', 'P' => '#e87a7a', 'C' => '#f4b6b6'];
    $svg = '<svg class="cat" width="' . (14 * $px) . '" height="' . (11 * $px) . '" viewBox="0 0 14 11" shape-rendering="crispEdges" aria-hidden="true">';
    foreach ($grid as $y => $row) {
        for ($x = 0; $x < 14; $x++) {
            $c = $row[$x];
            if (isset($colors[$c])) $svg .= '<rect x="' . $x . '" y="' . $y . '" width="1" height="1" fill="' . $colors[$c] . '"/>';
        }
    }
    return $svg . '</svg>';
}

function nav_items(string $role): array {
    $home = ['dashboard.php', 'Home'];
    $out  = ['logout.php', 'Logout'];
    return match ($role) {
        'admin' => [$home, ['users.php', 'Users'], ['reports.php', 'Reports & Audit'], ['backup.php', 'Backup'], $out],
        'staff'       => [$home, ['pets.php', 'Pets'], ['adopters.php', 'Adopters'], ['applications.php', 'Adoption'], $out],
        default       => [$home, ['browse.php', 'Browse pets'], ['matches.php', 'Find matches'], ['profile.php', 'My profile'], ['my_applications.php', 'My applications'], $out],
    };
}

function layout_start(string $title, string $banner = '', bool $nav = true): void {
    $u   = user();
    $cur = basename($_SERVER['SCRIPT_NAME']);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> - Pet Adoption Matcher</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=VT323&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="window">
  <div class="titlebar">
    <span class="title">&#9679; pet_adoption_matcher</span>
    <span class="winbtns"><i>&#8212;</i><i>&#9633;</i><i>&#10005;</i></span>
  </div>
  <div class="banner"><?= e($banner !== '' ? $banner : $title) ?></div>
  <div class="body">
    <?php if ($nav && $u): ?>
    <nav class="side">
      <div class="side-cat"><?= pixel_cat(5) ?></div>
      <?php foreach (nav_items($u['role']) as [$href, $label]): ?>
        <a href="<?= e($href) ?>" class="<?= $cur === $href ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <main class="content">
      <h1><?= e($title) ?></h1>
      <?= flash_render() ?>
<?php
}

function layout_end(): void {
    $u = user();
    $elapsed = isset($_SESSION['login_time']) ? time() - (int)$_SESSION['login_time'] : 0;
    ?>
    </main>
  </div>
  <div class="statusbar">
    <span><?= $u ? e($u['full_name']) . ' [' . e($u['role']) . ']' : 'not signed in' ?></span>
    <span id="clock" data-elapsed="<?= (int)$elapsed ?>">00:00:00</span>
  </div>
</div>
<script>
(function () {
  var el = document.getElementById('clock');
  var s = parseInt(el.dataset.elapsed, 10) || 0;
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function tick() {
    el.textContent = pad(Math.floor(s / 3600)) + ':' + pad(Math.floor(s / 60) % 60) + ':' + pad(s % 60);
    s++;
  }
  tick(); setInterval(tick, 1000);
  // confirmation prompt for destructive / final actions
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (ev) { if (!confirm(f.dataset.confirm)) ev.preventDefault(); });
  });
})();
</script>
</body>
</html>
<?php
}
