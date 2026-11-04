<?php
// A Visitor applies to adopt a pet. Uses the same transaction (sp_submit_application) Staff use.
require __DIR__ . '/includes/bootstrap.php';
require_role(['visitor']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') go('browse.php');
csrf_check();

$me = my_adopter();
if (!$me) {
    flash('err', 'Please complete My profile first so we can match you and save your application.');
    go('profile.php');
}
$petId = (int)($_POST['pet_id'] ?? 0);
if ($petId <= 0) { flash('err', 'Please choose a pet.'); go('browse.php'); }

// The adopter is ALWAYS the logged-in visitor's own profile, never a value from the form.
$res = call_proc('CALL sp_submit_application(?, ?, ?, @p_code, @p_msg)', [$me['adopter_id'], $petId, user()['user_id']]);
if ((int)$res['code'] === 0) {
    flash('ok', 'Application sent! Staff will review it. You can follow it under My applications.');
    go('my_applications.php');
}
flash('err', $res['msg']);
go('browse.php');
