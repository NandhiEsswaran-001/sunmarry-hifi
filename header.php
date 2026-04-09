<?php
require_once 'auth.php';
// Shared header with company logo. Place this file in the project and include it where needed.
$isLoginPage = basename($_SERVER['PHP_SELF'] ?? '') === 'login.php';
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary no-print">
    <div class="container">
        <?php if ($isLoginPage): ?>
            <div class="w-100 d-flex align-items-center">
                <a class="navbar-brand d-flex align-items-center" href="login.php">
                    <img src="assets/SunLogoHeart.png" alt="Company Logo" class="company-logo" style="max-height: 60px;">
                </a>
                <div class="flex-grow-1 text-center text-white">
                    <h2 class="mb-0 fw-bold">சன் மெட்ரிமோனி</h2>
                    <small>திருமணம் மற்றும் திருமணத்தைக் கொண்டு</small>
                </div>
                <div style="width: 80px;"></div>
            </div>
        <?php else: ?>
            <a class="navbar-brand d-flex align-items-center" href="home.php">
                <img src="SunLogo.png" alt="Company Logo" class="company-logo me-2">
               
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <?php if (isset($_SESSION['role']) && !isCustomerRole()): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="home.php">சுயவிவரம் உருவாக்கு</a>
                    </li>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['role'])): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="profiles.php">| சுயவிவரங்களை காண்</a>
                    </li>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['super_admin', 'admin'])): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="admin_dashboard.php">
                            | <?php echo ($_SESSION['role'] === 'admin') ? 'Admin Dashboard' : 'Super Admin Dashboard'; ?>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['role']) && !isCustomerRole()): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="recycle.php"> | நீக்கம் </a>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <?php if (isset($_SESSION['role']) && isCustomerRole()): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="#">
                            <?php
                            require_once 'db.php';
                            $user_id = $_SESSION['user_id'];
                            $stmt = $pdo->prepare("SELECT credits FROM users WHERE id = ?");
                            $stmt->execute([$user_id]);
                            $credits = $stmt->fetchColumn();
                            $limit = getCustomerCreditLimit();
                            $credits = $credits === null ? $limit : (int)$credits;
                            $credits = min($credits, $limit);
                            echo "Profiles: $credits/$limit";
                            ?>
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <span class="nav-link">
                            <i class="bi bi-person-circle"></i> 
                            <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?> 
                            <span class="badge bg-info">
                                <?php 
                                    $role = $_SESSION['role'] ?? '';
                                    $roleDisplay = match($role) {
                                        'super_admin' => 'Super Admin',
                                        'admin' => 'Admin',
                                        'manager' => 'Manager',
                                        'customer' => 'Customer',
                                        'special_customer' => 'Special Customer',
                                        default => 'User'
                                    };
                                    echo $roleDisplay;
                                ?>
                            </span>
                        </span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">வெளியேறு</a>
                    </li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</nav>
