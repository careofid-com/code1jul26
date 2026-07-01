<?php /* filename: admin/coid_claims.php */
require_once __DIR__ . '/../include/security.php';
require_once __DIR__ . '/../include/render.php';
require_once __DIR__ . '/../include/db.php';
require_once __DIR__ . '/../include/authz.php';
require_once __DIR__ . '/../include/cleanup.php';

admin_session_start();
security_headers();
admin_require_login();
$me = admin_current();

// Require at least role "admin" (admin or superadmin)
if (role_rank($me['role']) < role_rank('admin')) {
    flash_add('err', 'You do not have permission to manage COID claims.');
    header('Location: /admin/index.php');
    exit;
}

$pdo = db();

/* -----------------------------------------------------------
 * Small helper: send email
 * ---------------------------------------------------------*/
function send_claim_email($toEmail, $subject, $body) {
    $toEmail = trim((string)$toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;

    $headers = "From: noreply@careofid.com\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

    return @mail($toEmail, $subject, $body, $headers);
}

/* -----------------------------------------------------------
 * Handle approve / reject actions (POST)
 * ---------------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf   = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $cid    = isset($_POST['claim_id']) ? (int)$_POST['claim_id'] : 0;
    $noteIn = trim((string)($_POST['note'] ?? ''));

    if (!csrf_verify($csrf)) {
        http_response_code(400);
        exit('Bad CSRF');
    }

    if ($cid <= 0) {
        flash_add('err', 'Invalid claim ID.');
        header('Location: /admin/coid_claims.php');
        exit;
    }

    // Reject note must be provided (as per your requirement)
    if ($action === 'reject') {
        if ($noteIn === '') {
            flash_add('err', 'Please enter a rejection message before rejecting (this will be emailed to the claimant).');
            header('Location: /admin/coid_claims.php');
            exit;
        }
    }

    // Trim note to DB limit (your schema: note varchar(500))
    if (strlen($noteIn) > 500) $noteIn = substr($noteIn, 0, 500);

    $emailToSend = null;
    $emailSubject = '';
    $emailBody = '';

    try {
        $pdo->beginTransaction();

        // Load claim + COID + claimant + current owner
        $st = $pdo->prepare('
            SELECT cc.id,
                   cc.coid_id,
                   cc.user_id,
                   cc.status,
                   cc.note,
                   c.coid         AS coid_slug,
                   c.user_id      AS current_owner_id,
                   u.email        AS claimant_email,
                   u.first_name   AS claimant_first_name,
                   u.last_name    AS claimant_last_name
            FROM coid_claims cc
            JOIN coids c ON c.id = cc.coid_id
            JOIN users u ON u.id = cc.user_id
            WHERE cc.id = ?
            FOR UPDATE
        ');
        $st->execute(array($cid));
        $claim = $st->fetch(PDO::FETCH_ASSOC);

        if (!$claim) {
            $pdo->rollBack();
            flash_add('err', 'Claim not found.');
            header('Location: /admin/coid_claims.php');
            exit;
        }

        if ($claim['status'] !== 'pending') {
            $pdo->rollBack();
            flash_add('err', 'Only pending claims can be modified.');
            header('Location: /admin/coid_claims.php');
            exit;
        }

        $claimId        = (int)$claim['id'];
        $coidId         = (int)$claim['coid_id'];
        $newOwnerUserId = (int)$claim['user_id'];          // claimant
        $oldOwnerUserId = (int)$claim['current_owner_id']; // placeholder or previous owner
        $coidSlug       = (string)$claim['coid_slug'];

        $claimantEmail = (string)$claim['claimant_email'];
        $claimantName  = trim((string)($claim['claimant_first_name'] ?? '') . ' ' . (string)($claim['claimant_last_name'] ?? ''));
        if ($claimantName === '') $claimantName = 'there';

        if ($action === 'approve') {
            // 1) Transfer ownership
            $stUpdCoid = $pdo->prepare('UPDATE coids SET user_id = ? WHERE id = ?');
            $stUpdCoid->execute(array($newOwnerUserId, $coidId));

            // 2) Approve claim (store note if provided, else keep existing)
            $stUpdClaim = $pdo->prepare('
                UPDATE coid_claims
                SET status = "approved",
                    processed_at = NOW(),
                    processed_by_admin_id = ?,
                    note = ?
                WHERE id = ?
            ');
            $noteToStore = ($noteIn !== '') ? $noteIn : ($claim['note'] ?? null);
            $stUpdClaim->execute(array($me['id'], $noteToStore, $claimId));

            // 3) Auto-reject other pending claims for same COID (mark processed, no email requested)
            $stRejectOthers = $pdo->prepare('
                UPDATE coid_claims
                SET status = "rejected",
                    processed_at = NOW(),
                    processed_by_admin_id = ?
                WHERE coid_id = ?
                  AND id <> ?
                  AND status = "pending"
            ');
            $stRejectOthers->execute(array($me['id'], $coidId, $claimId));

            // 4) Cleanup placeholder user if safe
            if ($oldOwnerUserId > 0 && $oldOwnerUserId !== $newOwnerUserId) {
                $stCountCoids = $pdo->prepare('SELECT COUNT(*) FROM coids WHERE user_id = ?');
                $stCountCoids->execute(array($oldOwnerUserId));
                $countCoids = (int)$stCountCoids->fetchColumn();

                if ($countCoids === 0) {
                    $stDel = $pdo->prepare('
                        DELETE FROM users
                        WHERE id = ?
                          AND (
                                email IS NULL
                             OR email = ""
                             OR LOWER(email) = "null"
                          )
                    ');
                    $stDel->execute(array($oldOwnerUserId));
                }
            }

            $pdo->commit();

            // Build approved email (fixed message)
            $emailToSend = $claimantEmail;
            $emailSubject = 'CareOfID — COID claim approved';
            $emailBody =
                "Hi {$claimantName},\n\n" .
                "Good news — your claim for COID /{$coidSlug} has been approved.\n\n" .
                "You can now manage this COID from your account on CareOfID.\n\n" .
                "— CareOfID Team\n";

            flash_add('ok', 'Claim approved. COID /' . htmlspecialchars($coidSlug, ENT_QUOTES, 'UTF-8') . ' is now owned by ' . htmlspecialchars($claimantEmail, ENT_QUOTES, 'UTF-8') . '.');

        } elseif ($action === 'reject') {
            // Reject claim, save note (required), mark processed
            $stUpdClaim = $pdo->prepare('
                UPDATE coid_claims
                SET status = "rejected",
                    processed_at = NOW(),
                    processed_by_admin_id = ?,
                    note = ?
                WHERE id = ?
            ');
            $stUpdClaim->execute(array($me['id'], $noteIn, $claimId));

            $pdo->commit();

            // Build rejected email with admin text
            $emailToSend = $claimantEmail;
            $emailSubject = 'CareOfID — COID claim rejected';
            $emailBody =
                "Hi {$claimantName},\n\n" .
                "Your claim for COID /{$coidSlug} was reviewed and rejected.\n\n" .
                "Message from Admin:\n" .
                $noteIn . "\n\n" .
                "If you believe this is an error, you can reply to this email or contact support.\n\n" .
                "— CareOfID Team\n";

            flash_add('ok', 'Claim rejected.');

        } else {
            $pdo->rollBack();
            flash_add('err', 'Unknown action.');
        }

        // Send email after DB commit
        if ($emailToSend) {
            $ok = send_claim_email($emailToSend, $emailSubject, $emailBody);
            if (!$ok) {
                flash_add('err', 'Note: Claim updated, but email could not be sent (mail() failed).');
            }
        }

        header('Location: /admin/coid_claims.php');
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_add('err', 'Could not update claim. Please try again.');
        header('Location: /admin/coid_claims.php');
        exit;
    }
}

/* -----------------------------------------------------------
 * List claims
 * ---------------------------------------------------------*/
page_head('Admin — COID claims');
page_nav();
page_flash();

echo '<div class="centercol">';
echo '<div class="card">';

// Back button
echo '<div style="margin-bottom:10px;">';
echo '<a href="/admin/index.php" style="display:inline-block;padding:8px 12px;border-radius:8px;border:1px solid #bbb;text-decoration:none;background:#f7f7f7;color:#111;">&larr; Back</a>';
echo '</div>';

echo '<h1>COID claims</h1>';
echo '<p class="muted">Review and approve or reject COID ownership claims submitted by users. Rejection requires a message (emailed to the claimant).</p>';

try {
    
    
    
$stList = $pdo->prepare('
    SELECT cc.id,
           cc.coid_id,
           cc.user_id,
           cc.status,
           cc.note,
           cc.created_at,
           c.coid        AS coid_slug,
           u.email       AS claimant_email,
           u.first_name  AS claimant_first_name,
           u.last_name   AS claimant_last_name
    FROM coid_claims cc
    JOIN coids  c ON c.id = cc.coid_id
    JOIN users  u ON u.id = cc.user_id
    WHERE cc.status = "pending"
    ORDER BY cc.created_at DESC, cc.id DESC
');

    
    
    
    $stList->execute();
    $rows = $stList->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rows = array();
}

if (!$rows) {
    echo '<p class="muted">No COID claims yet.</p>';
    echo '</div></div>';
    page_foot();
    exit;
}

echo '<div style="overflow-x:auto; margin-top:10px;">';
echo '<table border="0" cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px;">';
echo '<thead>';
echo '<tr style="border-bottom:1px solid #ddd;">';
echo '<th align="left">ID</th>';
echo '<th align="left">COID</th>';
echo '<th align="left">Claimant</th>';
echo '<th align="left">Status</th>';
echo '<th align="left">Note</th>';
echo '<th align="left">Created</th>';
echo '<th align="left">Actions</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

foreach ($rows as $r) {
    $id      = (int)$r['id'];
    $coid    = (string)$r['coid_slug'];
    $email   = (string)$r['claimant_email'];
    $name    = trim((string)($r['claimant_first_name'] ?: '') . ' ' . (string)($r['claimant_last_name'] ?: ''));
    $status  = (string)$r['status'];
    $note    = (string)($r['note'] ?? '');
    $created = (string)$r['created_at'];

    if ($name === '') $name = '(no name)';

    echo '<tr style="border-top:1px solid #f0f0f0;">';
    echo '<td>' . $id . '</td>';
    echo '<td><a href="/' . htmlspecialchars($coid, ENT_QUOTES, 'UTF-8') . '" target="_blank">/' . htmlspecialchars($coid, ENT_QUOTES, 'UTF-8') . '</a></td>';
    echo '<td>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '<br><span class="muted">' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</span></td>';
    echo '<td>' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars($created, ENT_QUOTES, 'UTF-8') . '</td>';

    echo '<td>';

    if ($status === 'pending') {
        // Approve (simple)
        echo '<form method="post" action="/admin/coid_claims.php" style="display:inline;margin-right:6px;">';
        echo '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="claim_id" value="' . $id . '">';
        echo '<input type="hidden" name="action" value="approve">';
        echo '<button type="submit">Approve</button>';
        echo '</form>';

        // Reject with required message (textarea)
        echo '<form method="post" action="/admin/coid_claims.php" style="display:inline;">';
        echo '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="claim_id" value="' . $id . '">';
        echo '<input type="hidden" name="action" value="reject">';
        echo '<div style="margin-top:6px;">';
        echo '<textarea name="note" rows="2" maxlength="500" placeholder="Rejection message (required)..." style="width:240px;"></textarea>';
        echo '</div>';
        echo '<div style="margin-top:6px;">';
        echo '<button type="submit">Reject + Email</button>';
        echo '</div>';
        echo '</form>';
    } else {
        echo '<span class="muted">No actions</span>';
    }

    echo '</td>';
    echo '</tr>';
}

echo '</tbody>';
echo '</table>';
echo '</div>'; // overflow

echo '</div>'; // card
echo '</div>'; // centercol

page_foot();
