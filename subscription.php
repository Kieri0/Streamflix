<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (!empty($_SESSION['is_admin'])) { header('Location: admin/dashboard.php'); exit; }
require_once __DIR__ . '/php/db.php';

$uid         = $_SESSION['user_id'];
$performedBy = $_SESSION['email'] ?? 'SYSTEM';
$msg         = '';
$msgType     = '';

$plans = [
    'monthly' => ['name' => 'Monthly',  'price' => 15.00, 'days' => 30],
    '6months' => ['name' => '6 Months', 'price' => 49.00, 'days' => 180],
];

// Show message if redirected from watch.php because no active subscription
if (!empty($_GET['required'])) {
    $msg     = 'You need an active subscription to watch movies. Choose a plan below.';
    $msgType = 'info';
}

// ── CANCEL CURRENT SUBSCRIPTION ──────────────────────────────────────────────
// Deletes the active Subscription row and sets User.SubscriptionStatus = inactive.
// Uses BEGIN + COMMIT + ROLLBACK so both changes happen atomically.
// If DELETE succeeds but UPDATE fails (or vice versa), everything rolls back.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    $subId = (int) ($_POST['sub_id'] ?? 0);
    if ($subId > 0) {
        // CONCURRENCY CONTROL: beginTransaction turns autocommit OFF.
        // Nothing is saved until commitTransaction() is explicitly called.
        beginTransaction($conn);
        try {
            // LOCKING: FOR UPDATE — exclusive lock on this Subscription row.
            // No other session can read or modify it while this cancel is in progress.
            $verify = $conn->prepare(
                "SELECT SubscriptionID, PlanName FROM Subscription
                 WHERE SubscriptionID = ? AND UserID = ? FOR UPDATE"
            );
            $verify->bind_param("ii", $subId, $uid);
            $verify->execute();
            $subRow = $verify->get_result()->fetch_assoc();
            $verify->close();

            if (!$subRow) {
                // ROLLBACK — subscription not found or doesn't belong to this user
                rollbackTransaction($conn);
                $msg     = 'Subscription not found.';
                $msgType = 'error';
            } else {
                // Step 1 — Delete the Subscription row
                $del = $conn->prepare("DELETE FROM Subscription WHERE SubscriptionID = ?");
                $del->bind_param("i", $subId);
                if (!$del->execute()) throw new Exception($conn->error);
                $del->close();

                // Step 2 — Set user status to inactive
                $upd = $conn->prepare("UPDATE User SET SubscriptionStatus = 'inactive' WHERE UserID = ?");
                $upd->bind_param("i", $uid);
                if (!$upd->execute()) throw new Exception($conn->error);
                $upd->close();

                // COMMIT — both Step 1 and Step 2 are permanently saved together.
                // If this line is never reached, the catch block fires ROLLBACK instead.
                commitTransaction($conn);

                // TRANSACTION LOGGING — after commit so a log failure never undoes the cancel
                auditLog($conn, 'Subscription', 'DELETE', $subId, $performedBy,
                    "User cancelled subscription ID={$subId} | Plan={$subRow['PlanName']} | UserID={$uid}");

                $_SESSION['sub_status'] = 'inactive';
                $msg     = 'Your subscription has been cancelled. You can subscribe to a new plan below.';
                $msgType = 'success';
            }
        } catch (Exception $e) {
            // ROLLBACK — any error above undoes ALL changes (DELETE and UPDATE both reversed)
            rollbackTransaction($conn);
            $msg     = 'Could not cancel subscription. Please try again.';
            $msgType = 'error';
            error_log("Subscription cancel failed: " . $e->getMessage());
        }
    }
}

// ── SUBSCRIBE TO A PLAN ───────────────────────────────────────────────────────
// processSubscription() in db.php contains its own BEGIN + COMMIT + ROLLBACK,
// FOR UPDATE locking on the User row, and writes to AuditLog on success and failure.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['plan'])) {
    $plan = $_POST['plan'];
    if (isset($plans[$plan])) {
        $p = $plans[$plan];
        try {
            processSubscription($conn, $uid, $p['name'], $p['price'], $p['days'], $performedBy);
            $_SESSION['sub_status'] = 'active';
            $msg     = "Successfully subscribed to the {$p['name']} plan! Enjoy StreamFlix.";
            $msgType = 'success';
        } catch (Exception $e) {
            $msg     = 'Could not process subscription. Please try again.';
            $msgType = 'error';
        }
    }
}

// ── FETCH CURRENT STATE ───────────────────────────────────────────────────────
$subStmt = $conn->prepare("SELECT * FROM Subscription WHERE UserID=? ORDER BY SubscriptionID DESC LIMIT 1");
$subStmt->bind_param("i", $uid); $subStmt->execute();
$currentSub = $subStmt->get_result()->fetch_assoc();

$uStmt = $conn->prepare("SELECT FullName, Email, SubscriptionStatus FROM User WHERE UserID=?");
$uStmt->bind_param("i", $uid); $uStmt->execute();
$userInfo = $uStmt->get_result()->fetch_assoc();

$featured = $conn->query("SELECT * FROM Movie WHERE ThumbnailPath IS NOT NULL AND ThumbnailPath != '' ORDER BY Rating DESC LIMIT 1")->fetch_assoc();
$recs     = $conn->query("SELECT MovieID, Title, ThumbnailPath FROM Movie WHERE ThumbnailPath IS NOT NULL ORDER BY RAND() LIMIT 6")->fetch_all(MYSQLI_ASSOC);

$isActive = ($userInfo['SubscriptionStatus'] ?? '') === 'active';

// Determine which plan key the user is currently on
$currentPlanKey = null;
if ($isActive && $currentSub) {
    foreach ($plans as $key => $p) {
        if (strtolower(str_replace(' ', '', $p['name'])) === strtolower(str_replace(' ', '', $currentSub['PlanName']))) {
            $currentPlanKey = $key;
            break;
        }
    }
}

$daysLeft = 0;
if ($currentSub && $currentSub['EndDate']) {
    $daysLeft = max(0, (int) ceil((strtotime($currentSub['EndDate']) - time()) / 86400));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription - StreamFlix</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* ── Modal ──────────────────────────────────────────────── */
        .modal-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,.78);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #1c1c1c;
            border: 1px solid #333;
            border-radius: 12px;
            padding: 36px 40px;
            max-width: 460px;
            width: 90%;
            text-align: center;
        }
        .modal-box h3 {
            font-size: 20px;
            font-weight: 800;
            color: #fff;
            margin-bottom: 12px;
        }
        .modal-box p {
            font-size: 14px;
            color: #aaa;
            line-height: 1.75;
            margin-bottom: 26px;
        }
        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn-confirm-cancel {
            background: #e50914;
            color: #fff;
            border: none;
            padding: 10px 26px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-confirm-cancel:hover { background: #c0070f; }
        .btn-keep-plan {
            background: transparent;
            color: #bbb;
            border: 1px solid #555;
            padding: 10px 26px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
        }
        .btn-keep-plan:hover { color: #fff; border-color: #999; }

        /* ── Plan card states ───────────────────────────────────── */
        .plan-card.is-current {
            border: 2px solid #2ecc71 !important;
        }
        .current-plan-tag {
            display: inline-block;
            background: #2ecc71;
            color: #000;
            font-size: 11px;
            font-weight: 800;
            padding: 3px 12px;
            border-radius: 20px;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: .8px;
        }
        .btn-cancel-current {
            width: 100%;
            padding: 10px 0;
            background: transparent;
            border: 1px solid #e50914;
            color: #e50914;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-cancel-current:hover { background: #e50914; color: #fff; }
        .btn-switch {
            width: 100%;
            padding: 10px 0;
            justify-content: center;
            background: #555;
            color: #fff;
            border: 1px solid #777;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-switch:hover { background: #666; }
    </style>
</head>
<body>
<?php include __DIR__ . '/php/navbar.php'; ?>

<?php if ($msg): ?>
<div class="alert-<?= $msgType ?>" style="margin:0;border-radius:0;padding:14px 52px;font-size:15px">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div class="sub-hero">
    <h1>PLANS AND <span>PRICING</span></h1>
    <?php if ($currentSub && $isActive): ?>
    <div class="sub-current-plan">
        <span>Current Plan:</span>
        <strong><?= htmlspecialchars($currentSub['PlanName']) ?></strong>
        <span class="plan-expires">
            Expires: <?= htmlspecialchars($currentSub['EndDate'] ?? 'N/A') ?>
            <?php if ($daysLeft > 0): ?>
            (<?= $daysLeft ?> day<?= $daysLeft !== 1 ? 's' : '' ?> remaining)
            <?php endif; ?>
        </span>
    </div>
    <?php endif; ?>
</div>

<div class="sub-featured">
    <?php if ($featured && $featured['ThumbnailPath']): ?>
    <div class="sub-featured-movie">
        <img src="uploads/thumbnails/<?= htmlspecialchars(basename($featured['ThumbnailPath'])) ?>" alt="">
        <div class="overlay">
            <p style="font-size:13px;color:var(--yellow);margin-bottom:5px">Featured Movie</p>
            <h3><?= htmlspecialchars($featured['Title']) ?></h3>
            <p style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-top:6px">
                <?= htmlspecialchars($featured['Synopsis'] ?? '') ?>
            </p>
            <a href="movie.php?id=<?= $featured['MovieID'] ?>" class="btn-primary" style="margin-top:14px;display:inline-flex">WATCH NOW</a>
        </div>
    </div>
    <?php endif; ?>

    <div class="sub-plans">

        <?php foreach ($plans as $planKey => $planData):
            $isThisCurrent = ($currentPlanKey === $planKey);
            $isOtherActive = ($isActive && !$isThisCurrent);
        ?>
        <div class="plan-card <?= $planKey === '6months' ? 'featured' : '' ?> <?= $isThisCurrent ? 'is-current' : '' ?>">

            <?php if ($planKey === '6months' && !$isThisCurrent): ?>
                <div class="plan-badge">Best Value</div>
            <?php endif; ?>

            <?php if ($isThisCurrent): ?>
                <div class="current-plan-tag">✓ Current Plan</div>
            <?php endif; ?>

            <h3><?= htmlspecialchars($planData['name']) ?></h3>
            <div class="plan-price">
                $<?= $planKey === 'monthly' ? '15' : '49' ?><span><?= $planKey === 'monthly' ? '/month' : '/6 mo' ?></span>
            </div>
            <hr class="plan-divider">
            <ul class="plan-features">
                <li><span class="check">OK</span> HD Movies Available</li>
                <li><span class="check">OK</span> Unlimited Movies</li>
                <li><span class="check">OK</span> Cancel Anytime</li>
                <?php if ($planKey === 'monthly'): ?>
                <li><span class="check">OK</span> 30 Days Access</li>
                <?php else: ?>
                <li><span class="check">OK</span> Save 45%</li>
                <?php endif; ?>
            </ul>

            <?php if ($isThisCurrent): ?>
                <!-- ── This is the user's active plan — show Cancel button ── -->
                <button type="button" class="btn-cancel-current"
                    onclick="openCancelModal(
                        <?= (int)$currentSub['SubscriptionID'] ?>,
                        '<?= htmlspecialchars($planData['name'], ENT_QUOTES) ?>'
                    )">
                    Cancel Plan
                </button>

            <?php elseif ($isOtherActive): ?>
                <!-- ── User is on a different plan — urge them to cancel first ── -->
                <button type="button" class="btn-switch"
                    onclick="openSwitchModal(
                        '<?= htmlspecialchars($planData['name'], ENT_QUOTES) ?>',
                        '<?= htmlspecialchars($currentSub['PlanName'], ENT_QUOTES) ?>',
                        <?= (int)$currentSub['SubscriptionID'] ?>
                    )">
                    Subscribe
                </button>

            <?php else: ?>
                <!-- ── No active subscription — normal subscribe ── -->
                <form method="POST">
                    <input type="hidden" name="plan" value="<?= $planKey ?>">
                    <button type="submit" class="btn-primary" style="width:100%;justify-content:center">
                        Subscribe Now
                    </button>
                </form>
            <?php endif; ?>

        </div>
        <?php endforeach; ?>

    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     CANCEL PLAN MODAL
     Shown when user clicks "Cancel Plan" on their current plan.
     Submits action=cancel → PHP runs BEGIN + COMMIT + ROLLBACK
     ════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="cancelModal">
    <div class="modal-box">
        <h3>Cancel Your Plan?</h3>
        <p>
            You are about to cancel your
            <strong style="color:#e50914" id="cancelPlanName"></strong> plan.<br><br>
            You will lose access to all movies immediately and your remaining days will be forfeited.
        </p>
        <div class="modal-actions">
            <form method="POST">
                <input type="hidden" name="action" value="cancel">
                <!-- sub_id is filled by JS before the modal opens -->
                <input type="hidden" name="sub_id" id="cancelSubId" value="">
                <button type="submit" class="btn-confirm-cancel">Yes, Cancel Plan</button>
            </form>
            <button type="button" class="btn-keep-plan" onclick="closeModal('cancelModal')">Keep My Plan</button>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     SWITCH PLAN MODAL
     Shown when user clicks Subscribe on a plan while on another.
     Explains they must cancel first. Cancel button submits
     action=cancel → PHP runs BEGIN + COMMIT + ROLLBACK,
     then they return to subscribe to the new plan.
     ════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="switchModal">
    <div class="modal-box">
        <h3>Switch Plan?</h3>
        <p id="switchModalText"></p>
        <div class="modal-actions">
            <form method="POST">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="sub_id" id="switchSubId" value="">
                <button type="submit" class="btn-confirm-cancel">Cancel Current &amp; Switch</button>
            </form>
            <button type="button" class="btn-keep-plan" onclick="closeModal('switchModal')">Keep Current Plan</button>
        </div>
    </div>
</div>

<?php
// Join User so we can cross-check the real SubscriptionStatus alongside EndDate.
// This ensures the table reflects the actual state — not just whether EndDate passed.
$histStmt = $conn->prepare(
    "SELECT s.*, u.SubscriptionStatus AS UserStatus
     FROM Subscription s
     JOIN User u ON s.UserID = u.UserID
     WHERE s.UserID = ?
     ORDER BY s.SubscriptionID DESC"
);
$histStmt->bind_param("i", $uid); $histStmt->execute();
$subHistory = $histStmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<?php if ($subHistory): ?>
<div style="padding:0 52px 36px">
    <h3 style="font-size:18px;font-weight:700;margin-bottom:16px">Subscription History</h3>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr><th>Plan</th><th>Price</th><th>Duration</th><th>Start Date</th><th>End Date</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($subHistory as $idx => $sh):
                    $isNewest  = ($idx === 0); // most recent row (DESC order)
                    $expired   = $sh['EndDate'] && strtotime($sh['EndDate']) < time();

                    if ($expired) {
                        // End date is in the past — definitively expired
                        $statusLabel = 'Expired';
                        $statusClass = 'badge-inactive';
                    } elseif ($isNewest && $sh['UserStatus'] === 'active') {
                        // Most recent row AND user is genuinely active — this is the live plan
                        $statusLabel = 'Active';
                        $statusClass = 'badge-active';
                    } else {
                        // End date hasn't passed but user is inactive (expired by system / admin)
                        $statusLabel = 'Inactive';
                        $statusClass = 'badge-inactive';
                    }
                ?>
                <tr>
                    <td style="font-weight:600"><?= htmlspecialchars($sh['PlanName']) ?></td>
                    <td>$<?= number_format($sh['Price'], 2) ?></td>
                    <td><?= $sh['Duration'] ?> days</td>
                    <td><?= htmlspecialchars($sh['StartDate'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($sh['EndDate'] ?? '-') ?></td>
                    <td><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($recs): ?>
<div class="recommended-row">
    <h3>Recommended For You</h3>
    <div class="recommended-strip">
        <?php foreach ($recs as $r): ?>
        <div class="rec-card" onclick="location.href='movie.php?id=<?= $r['MovieID'] ?>'">
            <?php if ($r['ThumbnailPath']): ?>
            <img src="uploads/thumbnails/<?= htmlspecialchars(basename($r['ThumbnailPath'])) ?>" alt="">
            <?php endif; ?>
            <span><?= htmlspecialchars($r['Title']) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
// ── Open cancel modal ────────────────────────────────────────────────────────
function openCancelModal(subId, planName) {
    document.getElementById('cancelSubId').value         = subId;
    document.getElementById('cancelPlanName').textContent = planName;
    document.getElementById('cancelModal').classList.add('active');
}

// ── Open switch modal ────────────────────────────────────────────────────────
function openSwitchModal(newPlan, currentPlan, subId) {
    document.getElementById('switchSubId').value = subId;
    document.getElementById('switchModalText').innerHTML =
        'You are currently on the <strong style="color:#e50914">' + currentPlan + '</strong> plan.<br><br>' +
        'To switch to <strong style="color:#fff">' + newPlan + '</strong>, your current plan must be ' +
        'cancelled first. After cancelling, return here to subscribe to the new plan.<br><br>' +
        '<span style="color:#f39c12;font-size:13px">⚠ Your remaining days will be forfeited.</span>';
    document.getElementById('switchModal').classList.add('active');
}

// ── Close any modal ──────────────────────────────────────────────────────────
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Close when clicking the dark overlay background
document.querySelectorAll('.modal-overlay').forEach(function(el) {
    el.addEventListener('click', function(e) {
        if (e.target === el) el.classList.remove('active');
    });
});
</script>
</body>
</html>
