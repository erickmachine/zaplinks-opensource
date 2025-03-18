<?php
   require_once __DIR__ . '/vendor/autoload.php';
    
   use MercadoPago\SDK;
   use MercadoPago\Payment;

ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'localhost';
$dbname = 'zaplin05_bdzaplinks';
$username = 'zaplin05_usuariozaplinks';
$password = 'KfH}^_1Y1B%5';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE IF NOT EXISTS vendors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        nome VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL UNIQUE,
        telefone VARCHAR(20),
        dados_pix VARCHAR(255) NOT NULL,
        rating DECIMAL(3,2) DEFAULT 0,
        total_sales INT DEFAULT 0,
        criado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ratings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        vendor_id INT NOT NULL,
        product_id INT NOT NULL,
        rating INT NOT NULL,
        comment TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES marketplace(id) ON DELETE CASCADE
    )");

    $columnExists = $pdo->query("SHOW COLUMNS FROM marketplace LIKE 'status'")->rowCount() > 0;

    if (!$columnExists) {
        $pdo->exec("ALTER TABLE marketplace ADD COLUMN status ENUM('pending', 'paid', 'delivered') DEFAULT 'pending'");
    }

    $columnExists = $pdo->query("SHOW COLUMNS FROM `groups` LIKE 'view_count'")->rowCount() > 0;

    if (!$columnExists) {
        $pdo->exec("ALTER TABLE `groups` ADD COLUMN view_count INT DEFAULT 0");
    }

    $columnExists = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'is_read'")->rowCount() > 0;
    if (!$columnExists) {
        $pdo->exec("ALTER TABLE notifications ADD COLUMN is_read BOOLEAN DEFAULT FALSE");
    }

} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'];
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ?page=login');
        exit();
    }
}

function requireAdmin() {
    if (!isAdmin()) {
        header('Location: ?page=index');
        exit();
    }
}

$page = $_GET['page'] ?? 'index';

function getUnreadNotificationsCount($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = FALSE");
    $stmt->execute([':user_id' => $user_id]);
    return $stmt->fetchColumn();
}

function renderSendNotification() {
    requireLogin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit();
    }

    $user_id = $_POST['user_id'] ?? null;
    $notification_type = $_POST['notification_type'] ?? '';
    $message = $_POST['message'] ?? '';
    $group_id = $_POST['group_id'] ?? null;
    $report_data = isset($_POST['report_data']) ? json_decode($_POST['report_data'], true) : null;

    if (!$user_id || !$message) {
        http_response_code(400);
        exit();
    }

    global $pdo;

    // Inserir a notificação no banco de dados
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (:user_id, :message, NOW())");
    $result = $stmt->execute([
        ':user_id' => $user_id,
        ':message' => $message
    ]);

    // Se for uma denúncia, salvar os detalhes em uma tabela de denúncias
    if ($notification_type === 'group_reported' && $report_data) {
        $stmt = $pdo->prepare("INSERT INTO reports (group_id, user_id, reason, description, created_at) 
                              VALUES (:group_id, :user_id, :reason, :description, NOW())");
        $stmt->execute([
            ':group_id' => $report_data['group_id'],
            ':user_id' => $_SESSION['user_id'],
            ':reason' => $report_data['reason'],
            ':description' => $report_data['description']
        ]);
    }

    // Retornar sucesso
    http_response_code(200);
    exit();
}

function renderGetNotifications() {
    requireLogin();

    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($notifications);
    exit();
}

function renderMarkNotificationRead() {
    requireLogin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit();
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $notification_id = $data['id'] ?? null;

    if (!$notification_id) {
        http_response_code(400);
        exit();
    }

    global $pdo;

    $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE id = :id AND user_id = :user_id");
    $stmt->execute([':id' => $notification_id, ':user_id' => $_SESSION['user_id']]);

    http_response_code(200);
    exit();
}

function renderMarkAllNotificationsRead() {
    requireLogin();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit();
    }

    global $pdo;

    $stmt = $pdo->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);

    http_response_code(200);
    exit();
}


function renderNotificationsIcon() {
    if (!isLoggedIn()) return;
    
    $unreadCount = getUnreadNotificationsCount($_SESSION['user_id']);
    $hasUnread = $unreadCount > 0;
    
    echo '<div class="notifications-container">';
    echo '<button id="notificationsButton" class="notifications-button">';
    echo '<i class="fas fa-bell"></i>';
    if ($hasUnread) {
        echo '<span class="notification-badge">' . $unreadCount . '</span>';
    }
    echo '</button>';
    echo '<div id="notificationsOverlay" class="notifications-overlay"></div>';
    echo '<div id="notificationsPanel" class="notifications-panel">';
    echo '<button id="closeNotifications" class="close-notifications">&times;</button>';
    echo '<div class="notifications-header">';
    echo '<h3>Notificações</h3>';
    echo '<button id="markAllReadButton" class="mark-all-read">Marcar todas como lidas</button>';
    echo '</div>';
    echo '<div id="notificationsList" class="notifications-list"></div>';
    echo '<div class="notifications-footer">';
    echo '<a href="#" class="view-all-notifications">Ver todas as notificações</a>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    
}
function renderHeader() {
    $currentPage = $_GET['page'] ?? 'index';
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <!-- Google Adsense -->
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1959276306465032"
     crossorigin="anonymous"></script>

  <!-- Google tag (gtag.js) --> <script async src="https://www.googletagmanager.com/gtag/js?id=AW-16883496110"></script> <script> window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag('js', new Date()); gtag('config', 'AW-16883496110'); </script>

     
    <link rel="icon" href="icone-maior.png" type="image/x-icon">

    <meta property="og:title" content="ZapLinks - Divulgações e Vendas">
    <meta property="og:description" content="Encontre os Melhores Grupos de WhatsApp ou Venda seus Serviços Digitais.">
    <meta property="og:image" content="zaplinks - anuncio1.png">
    <meta property="og:url" content="https://www.zaplinks.com.br">
    
    <title>ZapLinks - Encontre os Melhores Grupos de WhatsApp</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        <?php echo getCSS(); ?>
    </style>
    <script src="https://kit.fontawesome.com/your-fontawesome-kit.js" crossorigin="anonymous"></script>
</head>     
    <body>
    <header>
        <div class="container">
            <a href="?page=index" class="logo">
                <img src="logogif.gif" alt="ZapLinks." class="logo-image">
                <span class="logo-text"></span>
            </a>
            <nav>
    <div class="menu-toggle">
        <i class="fas fa-bars"></i>
    </div>
    <ul class="nav-menu">
        <?php if (isLoggedIn()): ?>
            <li><a href="?page=enviar-grupo" class="btn btn-primary <?= $currentPage === 'enviar-grupo' ? 'active' : '' ?>">Enviar Grupo</a></li>
            <li><a href="?page=meus-grupos" class="btn btn-primary <?= $currentPage === 'meus-grupos' ? 'active' : '' ?>">Meus Grupos</a></li>
            <li><a href="?page=marketplace" class="btn btn-primary <?= $currentPage === 'marketplace' ? 'active' : '' ?>">Marketplace</a></li>
            <li><?php renderNotificationsIcon(); ?></li>
            <li class="dropdown">
                <a href="#" class="btn btn-ghost dropdown-toggle">Opções <i class="fas fa-6chevron-down"></i></a>
                <ul class="dropdown-menu">
                    
                    <li><a href="?page=perfil" class="btn btn-outline">Perfil</a></li>
                    <li><a href="?page=vendor-register" class="btn btn-outline">Vender</a></li>
                    <li><a href="?page=termos" class="btn btn-outline">Termos</a></li>
                    <li><a href="?page=logout" class="btn btn-outline">Sair</a></li>
                </ul>
            </li>
        <?php else: ?>
            <li><a href="?page=login" class="btn btn-primary">Entrar</a></li>
            <li><a href="?page=register" class="btn btn-primary">Enviar Grupo</a></li>
            <li><a href="?page=marketplace" class="btn btn-primary">Vender</a></li>
            
        <?php endif; ?>
    </ul>
</nav>
        </div>
    </header>
    <?php
}

function renderFooter() {
    $currentYear = date('Y');
    ?>
    <footer class="site-footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section about">
                    <h3>Sobre o ZapLinks</h3>
                    <p>O ZapLinks é a plataforma para divulgação de grupos de WhatsApp e marketplace digital. Conectamos pessoas e negócios de forma simples e eficiente.</p>
                    <div class="contact">
                        <span><i class="fas fa-phone"></i> &nbsp; (92) 99965-2961</span>
                        <span><i class="fas fa-envelope"></i> &nbsp; contato@zaplinks.com.br</span>
                    </div>
                    <div class="socials">
                        <a href="#"><i class="fab fa-facebook"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                        <a href="#"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
                <div class="footer-section links">
                    <h3>Links Rápidos</h3>
                    <ul>
                        <li><a href="?page=index">Início</a></li>
                        <li><a href="?page=enviar-grupo">Enviar Grupo</a></li>
                        <li><a href="?page=marketplace">Marketplace</a></li>
                        <li><a href="?page=meus-grupos">Meus Grupos</a></li>
                        <li><a href="?page=contato">Contato</a></li>
                    </ul>
                </div>
                <div class="footer-section contact-form">
                    <h3>Contate-nos</h3>
                    <form action="index.php" method="post">
                        <input type="email" name="email" class="text-input contact-input" placeholder="Seu endereço de email...">
                        <textarea name="message" class="text-input contact-input" placeholder="Sua mensagem..."></textarea>
                        <button type="submit" class="btn btn-big contact-btn">
                            <i class="fas fa-envelope"></i>
                            Enviar
                        </button>
                    </form>
                </div>
            </div>
            <div class="footer-bottom">
                &copy; <?php echo $currentYear; ?> ZapLinks | Todos os direitos reservados
            </div>
        </div>
    </footer>

    <style>
    .site-footer {
        background: #303036;
        color: #d3d3d3;
        padding: 3rem 0;
    }

    .footer-content {
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
    }

    .footer-section {
        flex: 1;
        padding: 1.25rem;
        min-width: 300px;
    }

    .footer-section h3 {
        color: white;
        margin-bottom: 1rem;
    }

    .about .contact span {
        display: block;
        margin-bottom: 0.5rem;
    }

    .about .socials a {
        color: #d3d3d3;
        border: 1px solid #d3d3d3;
        width: 35px;
        height: 35px;
        display: inline-flex;
        justify-content: center;
        align-items: center;
        border-radius: 50%;
        margin-right: 0.5rem;
        transition: all 0.3s;
    }

    .about .socials a:hover {
        color: white;
        border-color: white;
    }

    .links ul {
        list-style-type: none;
        padding-left: 0;
    }

    .links ul li {
        margin-bottom: 0.5rem;
    }

    .links ul a {
        color: #d3d3d3;
        text-decoration: none;
        transition: all 0.3s;
    }

    .links ul a:hover {
        color: white;
        padding-left: 0.5rem;
    }

    .contact-form .contact-input {
        background: #272727;
        color: #bebdbd;
        border: none;
        margin-bottom: 0.5rem;
        padding: 0.5rem 1rem;
        width: 100%;
    }

    .contact-form .contact-input:focus {
        background: #1a1a1a;
    }

    .contact-form .contact-btn {
        float: right;
        background: #005255;
        color: white;
        border: none;
        padding: 0.5rem 1rem;
        cursor: pointer;
        transition: all 0.3s;
    }

    .contact-form .contact-btn:hover {
        background: #006669;
    }

    .footer-bottom {
        background: #343a40;
        color: #686868;
        text-align: center;
        padding: 1rem 0;
        margin-top: 2rem;
    }

    @media only screen and (max-width: 934px) {
        .footer-content {
            flex-direction: column;
        }

        .footer-section {
            margin-bottom: 1rem;
        }
    }
    </style>


    <script>
        document.querySelector('.menu-toggle').addEventListener('click', function() {
            document.querySelector('.nav-menu').classList.toggle('active');
        });
    </script>


    <script>
document.addEventListener('DOMContentLoaded', function() {
    const notificationsButton = document.getElementById('notificationsButton');
    const notificationsPanel = document.getElementById('notificationsPanel');
    const notificationsOverlay = document.getElementById('notificationsOverlay');
    const notificationsList = document.getElementById('notificationsList');
    const closeNotifications = document.getElementById('closeNotifications');
    const markAllReadButton = document.getElementById('markAllReadButton');

    if (notificationsButton && notificationsPanel && notificationsList) {
        notificationsButton.addEventListener('click', function(event) {
            event.stopPropagation();
            // Alterando para definir explicitamente o display como 'block' em vez de alternar
            if (notificationsPanel.style.display === 'block') {
                notificationsPanel.style.display = 'none';
                notificationsOverlay.style.display = 'none';
            } else {
                notificationsPanel.style.display = 'block';
                notificationsOverlay.style.display = 'block';
                fetchNotifications();
            }
        });

        // Fechar ao clicar no overlay
        if (notificationsOverlay) {
            notificationsOverlay.addEventListener('click', function() {
                notificationsPanel.style.display = 'none';
                notificationsOverlay.style.display = 'none';
            });
        }

        // Fechar ao clicar no botão de fechar
        if (closeNotifications) {
            closeNotifications.addEventListener('click', function() {
                notificationsPanel.style.display = 'none';
                notificationsOverlay.style.display = 'none';
            });
        }

        document.addEventListener('click', function(event) {
            if (!notificationsButton.contains(event.target) && 
                !notificationsPanel.contains(event.target) && 
                !notificationsOverlay.contains(event.target)) {
                notificationsPanel.style.display = 'none';
                notificationsOverlay.style.display = 'none';
            }
        });

        if (markAllReadButton) {
            markAllReadButton.addEventListener('click', function() {
                markAllNotificationsAsRead();
            });
        }
    }

    function fetchNotifications() {
        fetch('?page=get-notifications')
            .then(response => response.json())
            .then(notifications => {
                notificationsList.innerHTML = '';
                if (notifications.length === 0) {
                    notificationsList.innerHTML = '<div class="notification-item"><p class="notification-message">Nenhuma notificação no momento.</p></div>';
                } else {
                    notifications.forEach(notification => {
                        const notificationItem = createNotificationItem(notification);
                        notificationsList.appendChild(notificationItem);
                    });
                }
                updateNotificationBadge(notifications.filter(n => !n.is_read).length);
            })
            .catch(error => console.error('Error fetching notifications:', error));
    }

    function createNotificationItem(notification) {
        const li = document.createElement('div');
        li.className = `notification-item ${notification.is_read ? '' : 'unread'}`;
        
        const icon = document.createElement('div');
        icon.className = 'notification-icon';
        icon.innerHTML = '<i class="fas fa-info-circle"></i>';
        
        const content = document.createElement('div');
        content.className = 'notification-content';
        
        const message = document.createElement('p');
        message.className = 'notification-message';
        message.textContent = notification.message;
        
        const time = document.createElement('span');
        time.className = 'notification-time';
        time.textContent = formatDate(new Date(notification.created_at));
        
        content.appendChild(message);
        content.appendChild(time);
        
        li.appendChild(icon);
        li.appendChild(content);
        
        li.addEventListener('click', () => markAsRead(notification.id));
        
        return li;
    }

    function markAsRead(notificationId) {
        fetch('?page=mark-notification-read', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ id: notificationId }),
        })
        .then(() => fetchNotifications())
        .catch(error => console.error('Error marking notification as read:', error));
    }

    function markAllNotificationsAsRead() {
        fetch('?page=mark-all-notifications-read', {
            method: 'POST',
        })
        .then(() => fetchNotifications())
        .catch(error => console.error('Error marking all notifications as read:', error));
    }

    function updateNotificationBadge(count) {
        const badge = document.querySelector('.notification-badge');
        if (badge) {
            if (count > 0) {
                badge.textContent = count;
                badge.style.display = 'inline-block';
            } else {
                badge.style.display = 'none';
            }
        }
    }

    function formatDate(date) {
        const now = new Date();
        const diff = now - date;
        const seconds = Math.floor(diff / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        const days = Math.floor(hours / 24);

        if (days > 0) return `${days} dia${days > 1 ? 's' : ''} atrás`;
        if (hours > 0) return `${hours} hora${hours > 1 ? 's' : ''} atrás`;
        if (minutes > 0) return `${minutes} minuto${minutes > 1 ? 's' : ''} atrás`;
        return 'Agora mesmo';
    }
});

</script>


<!--Start of Tawk.to Script-->
<script type="text/javascript">
var Tawk_API=Tawk_API||{}, Tawk_LoadStart=new Date();
(function(){
var s1=document.createElement("script"),s0=document.getElementsByTagName("script")[0];
s1.async=true;
s1.src='https://embed.tawk.to/67c8d051e4fee819191852ca/1ilk6rful';
s1.charset='UTF-8';
s1.setAttribute('crossorigin','*');
s0.parentNode.insertBefore(s1,s0);
})();
</script>
<!--End of Tawk.to Script-->
    </body>
    </html>
    <?php
}


function renderGroupCard($group) {
    $isFeature = $group['feature'] ?? false;
    ?>
    <div class="group-card <?= $isFeature ? 'featured' : '' ?>">
        <div class="card-header">
            <img src="<?= htmlspecialchars($group['imagem']) ?>" alt="<?= htmlspecialchars($group['nome_grupo']) ?>" loading="lazy">
            <?php if($isFeature): ?>
                <span class="feature-badge">
                    <i class="fas fa-star"></i>
                    <span>Destaque</span>
                </span>
            <?php endif; ?>
        </div>
        <div class="card-content">
            <span class="category-badge"><?= htmlspecialchars($group['categoria']) ?></span>
            <h3><?= htmlspecialchars($group['nome_grupo']) ?></h3>
            <p class="description"><?= htmlspecialchars(substr($group['descrição'], 0, 100)) ?>...</p>
            <div class="card-stats">
                <div class="stat">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Clique em Regras!</span>
                </div>
                <div class="stat">
                    <i class="fas fa-calendar"></i>
                    <span><?= date('d/m/Y', strtotime($group['criado'])) ?></span>
                </div>
            </div>
            <div class="group-actions">
                <a href="?page=group_options&id=<?= $group['id'] ?>&group_name=<?= urlencode($group['nome_grupo']) ?>&group_link=<?= urlencode($group['grupo_link']) ?>&owner_id=<?= $group['usuario_id'] ?>" class="btn btn-primary group-enter-btn">
                    <i class="fab fa-whatsapp"></i> Entrar
                </a>
                <a href="?page=group&id=<?= $group['id'] ?>" class="btn btn-outline">
                    <i class="fas fa-info-circle"></i> Regras
                </a>
            </div>
        </div>
    </div>
    
    <style>
    .group-card {
        background: var(--card-bg);
        border-radius: var(--radius);
        overflow: hidden;
        box-shadow: var(--shadow);
        transition: all 0.3s ease;
        position: relative;
    }

    .group-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.1);
    }

    .group-card.featured {
        border: 2px solid #FFD700;
        animation: pulse 2s infinite;
    }

    @keyframes pulse {
        0% {
            box-shadow: 0 0 0 0 rgba(255, 215, 0, 0.7);
        }
        70% {
            box-shadow: 0 0 0 10px rgba(255, 215, 0, 0);
        }
        100% {
            box-shadow: 0 0 0 0 rgba(255, 215, 0, 0);
        }
    }

    .card-header {
        position: relative;
        overflow: hidden;
    }

    .card-header img {
        width: 100%;
        height: 200px;
        object-fit: cover;
        transition: transform 0.3s ease;
    }

    .group-card:hover .card-header img {
        transform: scale(1.05);
    }

    .feature-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        background-color: #FFD700;
        color: #000;
        padding: 0.25rem 0.5rem;
        border-radius: var(--radius);
        font-size: 0.8rem;
        display: flex;
        align-items: center;
        gap: 0.25rem;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        animation: bounce 1s infinite;
    }

    @keyframes bounce {
        0%, 100% {
            transform: translateY(0);
        }
        50% {
            transform: translateY(-5px);
        }
    }

    .card-content {
        padding: 1rem;
    }

    .category-badge {
        display: inline-block;
        background-color: var(--primary);
        color: white;
        padding: 0.25rem 0.5rem;
        border-radius: 20px;
        font-size: 0.8rem;
        margin-bottom: 0.5rem;
        transition: background-color 0.3s ease;
    }

    .group-card:hover .category-badge {
        background-color: var(--primary-hover);
    }

    .card-content h3 {
        margin: 0.5rem 0;
        font-size: 1.2rem;
        color: var(--foreground);
        transition: color 0.3s ease;
    }

    .group-card:hover .card-content h3 {
        color: var(--primary);
    }

    .description {
        color: var(--muted-foreground);
        font-size: 0.9rem;
        margin-bottom: 1rem;
    }

    .card-stats {
        display: flex;
        justify-content: space-between;
        padding-top: 0.5rem;
        border-top: 1px solid var(--border);
        margin-bottom: 1rem;
    }

    .stat {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--muted-foreground);
        font-size: 0.8rem;
        transition: color 0.3s ease;
    }

    .group-card:hover .stat {
        color: var(--foreground);
    }

    .stat i {
        color: var(--primary);
    }

    .group-actions {
        display: flex;
        justify-content: space-between;
        gap: 0.5rem;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        padding: 0.5rem 1rem;
        border-radius: var(--radius);
        font-size: 0.9rem;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.3s ease;
    }

    .btn-primary {
        background-color: var(--primary);
        color: white;
        border: none;
        cursor: pointer;
    }

    .btn-primary:hover {
        background-color: var(--primary-hover);
        transform: translateY(-2px);
    }

    .btn-outline {
        border: 1px solid var(--primary);
        color: var(--primary);
        background: transparent;
    }

    .btn-outline:hover {
        background-color: var(--primary);
        color: white;
        transform: translateY(-2px);
    }

    .featured::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: linear-gradient(
            to bottom right,
            rgba(255, 215, 0, 0.3) 0%,
            rgba(255, 215, 0, 0) 50%,
            rgba(255, 215, 0, 0.3) 100%
        );  
        animation: rotate 20s linear infinite;
        z-index: 1;
    }

    .featured > * {
        position: relative;
        z-index: 2;
    }

    @keyframes rotate {
        100% {
            transform: rotate(360deg);
        }
    }
    </style>
    <?php
}

function renderGroupOptions() {
    if (!isset($_GET['id'])) {
        echo "ID do grupo não fornecido.";
        return;
    }

    $group_id = intval($_GET['id']);
    $group_name = isset($_GET['group_name']) ? $_GET['group_name'] : '';
    $group_link = isset($_GET['group_link']) ? $_GET['group_link'] : '';
    $owner_id = isset($_GET['owner_id']) ? $_GET['owner_id'] : '';

    // Se os parâmetros não foram passados pela URL, buscar do banco de dados
    if (empty($group_name) || empty($group_link) || empty($owner_id)) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT * FROM `groups` WHERE id = :id");
        $stmt->execute([':id' => $group_id]);
        $group = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$group) {
            echo "Grupo não encontrado.";
            return;
        }

        $group_name = $group['nome_grupo'];
        $group_link = $group['grupo_link'];
        $owner_id = $group['usuario_id'];
    }

    renderHeader();
    ?>
    <main class="group-options-page">
        <div class="container">
            <div class="group-options-card">
                <div class="card-header">
                    <h3 id="modalGroupName"><?= htmlspecialchars($group_name) ?></h3>
                    <a href="?page=index" class="group-modal-close">&times;</a>
                </div>
                <div class="card-body">
                    <div class="group-option-buttons">
                        <a href="<?= htmlspecialchars($group_link) ?>" id="enterGroupLink" class="group-option-btn enter-group" target="_blank" onclick="countView(<?= $group_id ?>, 'enter')">
                            <div class="option-icon">
                                <i class="fab fa-whatsapp"></i>
                            </div>
                            <div class="option-text">
                                <h4>Entrar no Grupo</h4>
                                <p>Abrir o link do grupo no WhatsApp</p>
                            </div>
                        </a>
                        
                        <button id="reportGroupBtn" class="group-option-btn report-group" onclick="showReportForm()">
                            <div class="option-icon">
                                <i class="fas fa-flag"></i>
                            </div>
                            <div class="option-text">
                                <h4>Denunciar Grupo</h4>
                                <p>Reportar conteúdo inadequado</p>
                            </div>
                        </button>
                        
                        <button id="reportLinkBtn" class="group-option-btn report-link" onclick="reportBrokenLink(<?= $group_id ?>, '<?= htmlspecialchars(addslashes($group_name)) ?>', <?= $owner_id ?>)">
                            <div class="option-icon">
                                <i class="fas fa-link-slash"></i>
                            </div>
                            <div class="option-text">
                                <h4>Link Quebrado</h4>
                                <p>Notificar que o link não funciona</p>
                            </div>
                        </button>
                    </div>
                </div>
                <div class="group-modal-footer">
                    <p class="modal-footer-text">ZapLinks - Conectando pessoas através de Grupos</p>
                </div>
            </div>
            
            <!-- Report form modal -->
            <div id="reportFormModal" class="report-form-modal" style="display: none;">
                <div class="report-modal-content">
                    <div class="report-modal-header">
                        <h3>Denunciar Grupo</h3>
                        <button class="report-modal-close" onclick="hideReportForm()">&times;</button>
                    </div>
                    <div class="report-modal-body">
                        <form id="reportForm" onsubmit="submitReport(event)">
                            <input type="hidden" id="reportGroupId" name="group_id" value="<?= $group_id ?>">
                            <input type="hidden" id="reportGroupName" name="group_name" value="<?= htmlspecialchars($group_name) ?>">
                            <input type="hidden" id="reportOwnerId" name="owner_id" value="<?= $owner_id ?>">
                            <div class="form-group">
                                <label for="reportReason">Motivo da denúncia:</label>
                                <select id="reportReason" name="reason" required>
                                    <option value="">Selecione um motivo</option>
                                    <option value="inappropriate">Conteúdo inadequado</option>
                                    <option value="spam">Spam ou propaganda</option>
                                    <option value="fake">Informações falsas</option>
                                    <option value="violence">Violência ou ameaças</option>
                                    <option value="illegal">Conteúdo ilegal</option>
                                    <option value="scam">Golpe ou fraude</option>
                                    <option value="broken_link">Link não funciona</option>
                                    <option value="other">Outro motivo</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="reportDescription">Descrição:</label>
                                <textarea id="reportDescription" name="description" rows="4" placeholder="Descreva o problema em detalhes..." required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Enviar Denúncia</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Success notification -->
            <div id="successNotification" class="notification" style="display: none;">
                <div class="notification-content success">
                    <i class="fas fa-check-circle"></i>
                    <span id="successMessage"></span>
                </div>
            </div>
        </div>
    </main>

    <style>
    /* Group Options Page Styles */
    .group-options-page {
        padding: 3rem 0;
        background-color: #f8f9fa;
        min-height: 80vh;
    }

    .group-options-card {
        background-color: #fff;
        border-radius: 16px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        max-width: 600px;
        margin: 0 auto;
        animation: fadeIn 0.3s ease;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    .card-header {
        padding: 1.5rem;
        background-color: #f8f9fa;
        border-bottom: 1px solid #e9ecef;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .card-header h3 {
        margin: 0;
        color: #343a40;
        font-size: 1.25rem;
    }

    .group-modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        color: #6c757d;
        cursor: pointer;
        transition: color 0.2s;
        text-decoration: none;
    }

    .group-modal-close:hover {
        color: #dc3545;
    }

    .card-body {
        padding: 1.5rem;
    }

    .group-option-buttons {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .group-option-btn {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1rem;
        border-radius: 12px;
        border: 1px solid #e9ecef;
        background-color: #fff;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        color: inherit;
        text-align: left;
        width: 100%;
    }

    .group-option-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }

    .enter-group {
        border-color: #25D366;
    }

    .enter-group:hover {
        background-color: #f0fff4;
    }

    .report-group {
        border-color: #dc3545;
    }

    .report-group:hover {
        background-color: #fff5f5;
    }

    .report-link {
        border-color: #ffc107;
    }

    .report-link:hover {
        background-color: #fffbeb;
    }

    .option-icon {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }

    .enter-group .option-icon {
        background-color: #25D366;
        color: white;
    }

    .report-group .option-icon {
        background-color: #dc3545;
        color: white;
    }

    .report-link .option-icon {
        background-color: #ffc107;
        color: white;
    }

    .option-text {
        flex: 1;
    }

    .option-text h4 {
        margin: 0 0 0.25rem 0;
        font-size: 1rem;
    }

    .option-text p {
        margin: 0;
        font-size: 0.85rem;
        color: #6c757d;
    }

    .group-modal-footer {
        padding: 1rem 1.5rem;
        background-color: #f8f9fa;
        border-top: 1px solid #e9ecef;
        text-align: center;
    }

    .modal-footer-text {
        color: #6c757d;
        font-size: 0.9rem;
        margin: 0;
    }

    /* Report Form Modal Styles */
    .report-form-modal {
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(5px);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .report-modal-content {
        background-color: #fff;
        width: 90%;
        max-width: 500px;
        border-radius: 16px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        animation: popIn 0.4s ease;
        overflow: hidden;
    }

    @keyframes popIn {
        from { transform: scale(0.9); opacity: 0; }
        to { transform: scale(1); opacity: 1; }
    }

    .report-modal-header {
        padding: 1.5rem;
        background-color: #f8f9fa;
        border-bottom: 1px solid #e9ecef;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .report-modal-header h3 {
        margin: 0;
        color: #343a40;
        font-size: 1.25rem;
    }

    .report-modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        color: #6c757d;
        cursor: pointer;
        transition: color 0.2s;
    }

    .report-modal-close:hover {
        color: #dc3545;
    }

    .report-modal-body {
        padding: 1.5rem;
    }

    /* Form Styles */
    .form-group {
        margin-bottom: 1.25rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 500;
        color: #343a40;
    }

    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid #ced4da;
        border-radius: 8px;
        font-size: 1rem;
    }

    .form-group select:focus,
    .form-group textarea:focus {
        border-color: #80bdff;
        outline: 0;
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
    }

    /* Notification Styles */
    .notification {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 1100;
        max-width: 350px;
    }

    .notification-content {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 1rem 1.5rem;
        border-radius: 8px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
        animation: slideInRight 0.4s ease, fadeOut 0.4s ease 3.6s;
    }

    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }

    @keyframes fadeOut {
        from { opacity: 1; }
        to { opacity: 0; }
    }

    .notification-content.success {
        background-color: #d4edda;
        color: #155724;
        border-left: 4px solid #28a745;
    }

    .notification-content i {
        font-size: 1.25rem;
    }

    /* Responsive Adjustments */
    @media (max-width: 768px) {
        .group-options-card,
        .report-modal-content {
            width: 95%;
        }
    }

    @media (max-width: 576px) {
        .option-text h4 {
            font-size: 0.95rem;
        }
        
        .option-text p {
            font-size: 0.8rem;
        }
    }
    </style>

    <script>
    // Function to count view
    function countView(groupId, viewType) {
        fetch('?page=count-view', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                group_id: groupId,
                view_type: viewType // 'enter' or 'rules'
            })
        })
        .catch(error => {
            console.error('Error counting view:', error);
        });
    }
    
    // Function to show report form
    function showReportForm() {
        document.getElementById('reportFormModal').style.display = 'flex';
    }
    
    // Function to hide report form
    function hideReportForm() {
        document.getElementById('reportFormModal').style.display = 'none';
    }
    
    // Function to report broken link
    function reportBrokenLink(groupId, groupName, ownerId) {
        // Create a form to submit the notification
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '?page=send-notification';
        form.style.display = 'none';
        
        // Add the necessary fields
        const userIdField = document.createElement('input');
        userIdField.type = 'hidden';
        userIdField.name = 'user_id';
        userIdField.value = ownerId;
        
        const typeField = document.createElement('input');
        typeField.type = 'hidden';
        typeField.name = 'notification_type';
        typeField.value = 'broken_link';
        
        const messageField = document.createElement('input');
        messageField.type = 'hidden';
        messageField.name = 'message';
        messageField.value = `O link do seu grupo "${groupName}" está desatualizado e precisa ser atualizado para não ser retirado da página!`;
        
        const groupIdField = document.createElement('input');
        groupIdField.type = 'hidden';
        groupIdField.name = 'group_id';
        groupIdField.value = groupId;
        
        // Append fields to form
        form.appendChild(userIdField);
        form.appendChild(typeField);
        form.appendChild(messageField);
        form.appendChild(groupIdField);
        
        // Append form to body and submit
        document.body.appendChild(form);
        
        // Create a hidden iframe to prevent page navigation
        const iframe = document.createElement('iframe');
        iframe.name = 'hidden_iframe';
        iframe.style.display = 'none';
        document.body.appendChild(iframe);
        
        // Set form target to the hidden iframe
        form.target = 'hidden_iframe';
        
        // Submit the form
        form.submit();
        
        // Show success notification
        document.getElementById('successMessage').textContent = 'Notificação enviada ao administrador do grupo sobre o link quebrado.';
        document.getElementById('successNotification').style.display = 'block';
        
        // Hide notification after 4 seconds
        setTimeout(() => {
            document.getElementById('successNotification').style.display = 'none';
        }, 4000);
        
        // Clean up
        setTimeout(() => {
            document.body.removeChild(form);
            document.body.removeChild(iframe);
        }, 1000);
    }
    
    // Function to submit report
    function submitReport(event) {
        event.preventDefault();
        
        // Get form data
        const groupId = document.getElementById('reportGroupId').value;
        const groupName = document.getElementById('reportGroupName').value;
        const ownerId = document.getElementById('reportOwnerId').value;
        const reasonSelect = document.getElementById('reportReason');
        const reason = reasonSelect.value;
        const reasonText = reasonSelect.options[reasonSelect.selectedIndex].text;
        const description = document.getElementById('reportDescription').value;
        
        // Create a form to submit the notification
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '?page=send-notification';
        form.style.display = 'none';
        
        // Add the necessary fields
        const userIdField = document.createElement('input');
        userIdField.type = 'hidden';
        userIdField.name = 'user_id';
        userIdField.value = ownerId;
        
        const typeField = document.createElement('input');
        typeField.type = 'hidden';
        typeField.name = 'notification_type';
        typeField.value = 'group_reported';
        
        const messageField = document.createElement('input');
        messageField.type = 'hidden';
        messageField.name = 'message';
        messageField.value = `Seu grupo "${groupName}" foi denunciado por "${reasonText}" e o usuário informou: "${description}" entre em contato para esclarecer sobre a denúncia.`;
        
        const groupIdField = document.createElement('input');
        groupIdField.type = 'hidden';
        groupIdField.name = 'group_id';
        groupIdField.value = groupId;
        
        const reportDataField = document.createElement('input');
        reportDataField.type = 'hidden';
        reportDataField.name = 'report_data';
        reportDataField.value = JSON.stringify({
            group_id: groupId,
            reason: reason,
            description: description
        });
        
        // Append fields to form
        form.appendChild(userIdField);
        form.appendChild(typeField);
        form.appendChild(messageField);
        form.appendChild(groupIdField);
        form.appendChild(reportDataField);
        
        // Append form to body
        document.body.appendChild(form);
        
        // Create a hidden iframe to prevent page navigation
        const iframe = document.createElement('iframe');
        iframe.name = 'hidden_iframe';
        iframe.style.display = 'none';
        document.body.appendChild(iframe);
        
        // Set form target to the hidden iframe
        form.target = 'hidden_iframe';
        
        // Submit the form
        form.submit();
        
        // Reset form and hide modal
        document.getElementById('reportForm').reset();
        hideReportForm();
        
        // Show success notification
        document.getElementById('successMessage').textContent = 'Denúncia enviada com sucesso! Obrigado por ajudar a manter a comunidade segura.';
        document.getElementById('successNotification').style.display = 'block';
        
        // Hide notification after 4 seconds
        setTimeout(() => {
            document.getElementById('successNotification').style.display = 'none';
        }, 4000);
        
        // Clean up
        setTimeout(() => {
            document.body.removeChild(form);
            document.body.removeChild(iframe);
        }, 1000);
    }
    
    // Close report form when clicking outside
    window.addEventListener('click', function(e) {
        const reportFormModal = document.getElementById('reportFormModal');
        if (e.target === reportFormModal) {
            hideReportForm();
        }
    });
    </script>
    <?php
    renderFooter();
}



function renderIndex() {
    global $pdo;

    $page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
    $perPage = 12;
    $offset = ($page - 1) * $perPage;

    $search = isset($_GET['search']) ? $_GET['search'] : '';
    $category = isset($_GET['category']) ? $_GET['category'] : '';

    $where = "WHERE aprovação = 1";
    $params = [];

    if ($search) {
        $where .= " AND (nome_grupo LIKE :search OR descrição LIKE :search)";
        $params[':search'] = "%$search%";
    }

    if ($category) {
        $where .= " AND categoria = :category";
        $params[':category'] = $category;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `groups` $where");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $total = $stmt->fetchColumn();

    $totalPages = ceil($total / $perPage);

    $stmt = $pdo->prepare("SELECT * FROM `groups` $where ORDER BY feature DESC, criado DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $groups = $stmt->fetchAll();

    // Fetch 3 featured products from marketplace
    $stmt = $pdo->prepare("SELECT m.*, v.nome as vendor_name, v.rating as vendor_rating 
                           FROM marketplace m 
                           LEFT JOIN vendors v ON m.usuario_id = v.user_id 
                           WHERE m.aprovado = 1 
                           ORDER BY m.impulsionado DESC, categoria DESC 
                           LIMIT 3");
    $stmt->execute();
    $featuredProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $categories = [
        ['AMIZADE', 'users'],
        ['NAMORO', 'heart'],
        ['STATUS', 'image'],
        ['LINKS', 'link'],
        ['VAGAS DE EMPREGOS', 'briefcase'],
        ['ENTRETENIMENTO', 'tv'],
        ['APOSTAS', 'dice'],
        ['JOGOS', 'gamepad'],
        ['MÚSICA', 'music'],
        ['VENDAS', 'shopping-cart'],
        ['FILMES', 'film'],
        ['ANIME', 'star'],
        ['REDE SOCIAL', 'share-2'],
        ['POLÍTICA', 'alert-triangle'],
        ['SHITPOST', 'pou'],
        ['OUTROS', 'zap'],
        ['LIVROS', 'book'],
        ['SAÚDE E BEM-ESTAR', 'heart-rate'],
        ['TECNOLOGIA', 'laptop'],
        ['VIAGENS', 'plane'],
        ['CULTURA', 'globe'],
        ['HUMOR', 'smile'],
        ['DESIGN E ARTE', 'palette'],
        ['EDUCAÇÃO', 'graduation-cap'],
        ['FOTOGRAFIA', 'camera'],
        ['EVENTOS', 'calendar'],
        ['DICAS E TRUQUES', 'lightbulb'],
        ['VOLUNTARIADO', 'hands-helping'],
    ];

    renderHeader();
    ?>
    <main>
        <div class="container">
            <div class="search-section">
                <h2>Marketplace e Divulgação</h2>
                <form action="?page=index" method="GET" class="search-form">
                    <input type="hidden" name="page" value="index">
                    <div class="search-input-wrapper">
                        <input type="text" name="search" placeholder="Buscar grupos" value="<?= htmlspecialchars($search) ?>">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </form>
                <div class="categories-wrapper">
                    <h3>Categorias</h3>
                    <div class="categories-slider" id="categoriesSlider">
                        <?php foreach ($categories as $cat): ?>
                            <div class="category-item <?= $category == $cat[0] ? 'active' : '' ?>" onclick="selectCategory('<?= $cat[0] ?>')">
                                <i class="fas fa-<?= $cat[1] ?>"></i>
                                <span><?= $cat[0] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <h1>Grupos de WhatsApp</h1>
            

            <div class="groups-grid">
                <?php foreach ($groups as $group): ?>
                    <?php renderGroupCard($group); ?>
                <?php endforeach; ?>
            </div>

            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=index&p=<?= $i ?>&search=<?= urlencode($search) ?>&category=<?= urlencode($category) ?>" 
                    class="<?= $i == $page ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>

            <!-- Featured Marketplace Products Section -->
            <?php if (!empty($featuredProducts)): ?>
            <div class="featured-marketplace-section">
                <div class="section-header">
                    <h2>Produtos em Destaque</h2>
                    <a href="?page=marketplace" class="view-all-link">Ver todos <i class="fas fa-arrow-right"></i></a>
                </div>
                <div class="featured-products-grid">
                    <?php foreach ($featuredProducts as $product): ?>
                        <div class="featured-product-card <?= $product['impulsionado'] ? 'featured' : '' ?>">
                            <?php if ($product['impulsionado']): ?>
                                <span class="featured-badge">
                                    <i class="fas fa-star"></i> Destaque
                                </span>
                            <?php endif; ?>
                            <div class="product-image">
                                <img src="<?= htmlspecialchars($product['imagem']) ?>" alt="<?= htmlspecialchars($product['nome_produto']) ?>" loading="lazy">
                            </div>
                            <div class="product-info">
                                <h3 class="product-title"><?= htmlspecialchars($product['nome_produto']) ?></h3>
                                <p class="product-category"><?= htmlspecialchars($product['categoria']) ?></p>
                                <div class="product-meta">
                                    <span class="product-price">R$ <?= number_format($product['preco'], 2, ',', '.') ?></span>
                                    <span class="product-rating">
                                        <?php
                                        $rating = round($product['vendor_rating'] ?? 5);
                                        for ($i = 1; $i <= 5; $i++) {
                                            echo $i <= $rating ? '<i class="fas fa-star"></i>' : '<i class="far fa-star"></i>';
                                        }
                                        ?>
                                    </span>
                                </div>
                                <p class="product-vendor">Vendedor: <?= htmlspecialchars($product['vendor_name'] ?? 'ZapLinks') ?></p>
                            </div>
                            <div class="product-actions">
                                <a href="?page=produto&id=<?= $product['id'] ?>" class="btn btn-primary btn-details">Ver Detalhes</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="marketplace-cta">
                    <h3>Encontre mais produtos e serviços digitais no nosso Marketplace</h3>
                    <a href="?page=marketplace" class="btn btn-primary btn-large">Explorar Marketplace</a>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </main>

    <style>
    .search-section {
        background-color: var(--card-bg);
        padding: 2rem;
        border-radius: var(--radius);
        margin-bottom: 2rem;
        box-shadow: var(--shadow);
    }

    .search-form {
        margin-bottom: 1rem;
    }

    .search-input-wrapper {
        display: flex;
        gap: 0.5rem;
    }

    .search-input-wrapper input {
        flex-grow: 1;
        padding: 0.5rem 1rem;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        font-size: 1rem;
    }

    .search-input-wrapper button {
        padding: 0.5rem 1rem;
        border-radius: var(--radius);
    }

    .categories-wrapper {
        margin-top: 1rem;
        position: relative;
    }

    .categories-wrapper h3 {
        margin-bottom: 0.5rem;
        font-size: 1.2rem;
        color: var(--primary);
    }

    .categories-slider {
        display: flex;
        overflow-x: auto;
        gap: 1rem;
        padding-bottom: 1rem;
        scroll-behavior: smooth;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;  /* Firefox */
        -ms-overflow-style: none;  /* Internet Explorer 10+ */
    }

    .categories-slider::-webkit-scrollbar { 
        display: none;  /* WebKit */
    }

    .category-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-width: 100px;
        height: 100px;
        padding: 0.5rem;
        background-color: var(--card-bg);
        border: 2px solid var(--border);
        border-radius: var(--radius);
        cursor: pointer;
        transition: all 0.3s ease;
        user-select: none;
    }

    .category-item:hover, .category-item.active {
        border-color: var(--primary);
        background-color: rgba(37, 211, 102, 0.1);
        transform: translateY(-2px);
    }

    .category-item i {
        font-size: 2rem;
        margin-bottom: 0.5rem;
        color: var(--primary);
    }

    .category-item span {
        font-size: 0.8rem;
        text-align: center;
        word-break: break-word;
    }

    /* Featured Marketplace Products Section */
    .featured-marketplace-section {
        margin-top: 3rem;
        padding: 2rem;
        background-color: var(--card-bg);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
    }

    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .section-header h2 {
        color: var(--primary);
        margin: 0;
    }

    .view-all-link {
        color: var(--primary);
        text-decoration: none;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        transition: all 0.3s ease;
    }

    .view-all-link:hover {
        transform: translateX(5px);
    }

    .featured-products-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .featured-product-card {
        background-color: #ffffff;
        border-radius: var(--radius);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        overflow: hidden;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        position: relative;
        display: flex;
        flex-direction: column;
        height: 100%;
    }

    .featured-product-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
    }

    .featured-product-card.featured {
        border: 2px solid var(--primary);
    }

    .featured-badge {
        position: absolute;
        top: 1rem;
        right: 1rem;
        background-color: var(--primary);
        color: #ffffff;
        padding: 0.25rem 0.5rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: bold;
        z-index: 1;
        display: flex;
        align-items: center;
        gap: 0.25rem;
    }

    .product-image {
        height: 200px;
        overflow: hidden;
    }

    .product-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s ease;
    }

    .featured-product-card:hover .product-image img {
        transform: scale(1.05);
    }

    .product-info {
        padding: 1.5rem;
        flex-grow: 1;
        display: flex;
        flex-direction: column;
    }

    .product-title {
        font-size: 1.2rem;
        margin-bottom: 0.5rem;
        color: var(--foreground);
    }

    .product-category {
        font-size: 0.9rem;
        color: var(--muted-foreground);
        margin-bottom: 1rem;
    }

    .product-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
        margin-top: auto;
    }

    .product-price {
        font-size: 1.2rem;
        font-weight: bold;
        color: var(--primary);
    }

    .product-rating {
        color: #ffc107;
    }

    .product-vendor {
        font-size: 0.9rem;
        color: var(--muted-foreground);
        margin-bottom: 1rem;
    }

    .product-actions {
        padding: 0 1.5rem 1.5rem;
    }

    .btn-details {
        width: 100%;
        text-align: center;
    }

    .marketplace-cta {
        text-align: center;
        padding: 2rem;
        background-color: #f8f9fa;
        border-radius: var(--radius);
        margin-top: 1rem;
    }

    .marketplace-cta h3 {
        margin-bottom: 1.5rem;
        color: var(--foreground);
    }

    .btn-large {
        padding: 0.75rem 1.5rem;
        font-size: 1.1rem;
    }

    @media (max-width: 768px) {
        .search-section {
            padding: 1rem;
        }

        .search-input-wrapper {
            flex-direction: column;
        }

        .search-input-wrapper button {
            width: 100%;
        }

        .category-item {
            min-width: 80px;
            height: 80px;
        }

        .category-item i {
            font-size: 1.5rem;
        }

        .category-item span {
            font-size: 0.7rem;
        }

        .featured-products-grid {
            grid-template-columns: 1fr;
        }

        .featured-marketplace-section {
            padding: 1rem;
        }
    }

    @media (min-width: 769px) and (max-width: 1024px) {
        .featured-products-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const slider = document.getElementById('categoriesSlider');
        let isDown = false;
        let startX;
        let scrollLeft;

        slider.addEventListener('mousedown', (e) => {
            isDown = true;
            slider.classList.add('active');
            startX = e.pageX - slider.offsetLeft;
            scrollLeft = slider.scrollLeft;
        });

        slider.addEventListener('mouseleave', () => {
            isDown = false;
            slider.classList.remove('active');
        });

        slider.addEventListener('mouseup', () => {
            isDown = false;
            slider.classList.remove('active');
        });

        slider.addEventListener('mousemove', (e) => {
            if (!isDown) return;
            e.preventDefault();
            const x = e.pageX - slider.offsetLeft;
            const walk = (x - startX) * 2;
            slider.scrollLeft = scrollLeft - walk;
        });

        // Touch events for mobile
        slider.addEventListener('touchstart', (e) => {
            isDown = true;
            slider.classList.add('active');
            startX = e.touches[0].pageX - slider.offsetLeft;
            scrollLeft = slider.scrollLeft;
        });

        slider.addEventListener('touchend', () => {
            isDown = false;
            slider.classList.remove('active');
        });

        slider.addEventListener('touchmove', (e) => {
            if (!isDown) return;
            e.preventDefault();
            const x = e.touches[0].pageX - slider.offsetLeft;
            const walk = (x - startX) * 2;
            slider.scrollLeft = scrollLeft - walk;
        });
    });

    function selectCategory(category) {
        window.location.href = `?page=index&category=${encodeURIComponent(category)}`;
    }
    </script>
    <?php
    renderFooter();
}

function renderMarketplace() {
    requireLogin();
    global $pdo;

    $search = isset($_GET['search']) ? $_GET['search'] : '';
    $category = isset($_GET['category']) ? $_GET['category'] : '';
    $sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';

    $where = "WHERE aprovado = 1";
    $params = [];

    if ($search) {
        $where .= " AND (nome_produto LIKE :search OR descricao LIKE :search)";
        $params[':search'] = "%$search%";
    }

    if ($category) {
        $where .= " AND categoria = :category";
        $params[':category'] = $category;
    }

    $orderBy = "ORDER BY ";
    switch ($sort) {
        case 'price_asc':
            $orderBy .= "preco ASC";
            break;
        case 'price_desc':
            $orderBy .= "preco DESC";
            break;
        case 'popular':
            $orderBy .= "view_count DESC";
            break;
        default:
            $orderBy .= "criado DESC";
    }

    $stmt = $pdo->prepare("SELECT m.*, v.nome as vendor_name, v.rating as vendor_rating 
                           FROM marketplace m 
                           LEFT JOIN vendors v ON m.usuario_id = v.user_id 
                           $where 
                           $orderBy");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $categories = ['Assinaturas e Premium', 'Bot WhatsApp', 'Grupos WhatsApp', 'Contas', 'Design Gráfico', 'Free Fire', 'Valorant', 'Fortnite', 'Minecraft', 'Rede Social', 'Cursos e Treinamentos', 'ZapLinks', 'Números WhatsApp', 'Seguidores e Curtidas', 'Sofware e Licenças', 'Serviços digitais', 'Discord', 'E-mails'];

    renderHeader();
    ?>
    <main class="marketplace">
        <div class="container">
            <header class="marketplace-header">
                <h1>Marketplace ZapLinks</h1>
                <p>Encontre os melhores produtos e serviços digitais</p>
            </header>

            <div class="marketplace-controls">
                <form action="?page=marketplace" method="GET" class="search-form">
                    <input type="hidden" name="page" value="marketplace">
                    <div class="search-input-wrapper">
                        <input type="text" name="search" placeholder="Buscar produtos..." value="<?= htmlspecialchars($search) ?>">
                        <button type="submit" class="btn-search">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                    <select name="category" class="category-select">
                        <option value="">Todas as categorias</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat ?>" <?= $category == $cat ? 'selected' : '' ?>><?= $cat ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="sort" class="sort-select">
                        <option value="newest" <?= $sort == 'newest' ? 'selected' : '' ?>>Mais recentes</option>
                        <option value="price_asc" <?= $sort == 'price_asc' ? 'selected' : '' ?>>Menor preço</option>
                        <option value="price_desc" <?= $sort == 'price_desc' ? 'selected' : '' ?>>Maior preço</option>
                        <option value="popular" <?= $sort == 'popular' ? 'selected' : '' ?>>Mais populares</option>
                    </select>
                </form>
            </div>

            <?php if (empty($products)): ?>
                <div class="no-results">
                    <i class="fas fa-search"></i>
                    <p>Nenhum produto encontrado. Tente uma nova busca ou explore outras categorias.</p>
                </div>
            <?php else: ?>
                <div class="products-grid">
                    <?php foreach ($products as $product): ?>
                        <div class="product-card <?= $product['impulsionado'] ? 'featured' : '' ?>">
                            <?php if ($product['impulsionado']): ?>
                                <span class="featured-badge">
                                    <i class="fas fa-star"></i> Destaque
                                </span>
                            <?php endif; ?>
                            <div class="product-image">
                                <img src="<?= htmlspecialchars($product['imagem']) ?>" alt="<?= htmlspecialchars($product['nome_produto']) ?>" loading="lazy">
                            </div>
                            <div class="product-info">
                                <h3 class="product-title"><?= htmlspecialchars($product['nome_produto']) ?></h3>
                                <p class="product-category"><?= htmlspecialchars($product['categoria']) ?></p>
                                <div class="product-meta">
                                    <span class="product-price">R$ <?= number_format($product['preco'], 2, ',', '.') ?></span>
                                    <span class="product-rating">
                                        <?php
                                        $rating = round($product['vendor_rating']);
                                        for ($i = 1; $i <= 5; $i++) {
                                            echo $i <= $rating ? '<i class="fas fa-star"></i>' : '<i class="far fa-star"></i>';
                                        }
                                        ?>
                                    </span>
                                </div>
                                <p class="product-vendor">Vendedor: <?= htmlspecialchars($product['vendor_name']) ?></p>
                            </div>
                            <div class="product-actions">
                                <a href="?page=produto&id=<?= $product['id'] ?>" class="btn btn-primary btn-details">Ver Detalhes</a>
                                <button class="btn btn-secondary btn-wishlist" title="Adicionar aos favoritos">
                                    <i class="far fa-heart"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (isLoggedIn()): ?>
                <div class="marketplace-cta">
                    <h2>Tem algo para vender?</h2>
                    <p>Anuncie seus produtos ou serviços no Marketplace ZapLinks!</p>
                    <a href="?page=cadastrar-produto" class="btn btn-primary btn-large">Anunciar Produto</a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <style>
    .marketplace {
        background-color: #f8f9fa;
        padding: 3rem 0;
    }

    .marketplace-header {
        text-align: center;
        margin-bottom: 2rem;
    }

    .marketplace-header h1 {
        font-size: 2.5rem;
        color: var(--primary);
        margin-bottom: 0.5rem;
    }

    .marketplace-header p {
        font-size: 1.1rem;
        color: var(--muted-foreground);
    }

    .marketplace-controls {
        background-color: #ffffff;
        padding: 1.5rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        margin-bottom: 2rem;
    }

    .search-form {
        display: flex;
        gap: 1rem;
        align-items: center;
        flex-wrap: wrap;
    }

    .search-input-wrapper {
        flex: 1;
        min-width: 200px;
        position: relative;
    }

    .search-input-wrapper input {
        width: 100%;
        padding: 0.75rem 1rem;
        padding-right: 3rem;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        font-size: 1rem;
    }

    .btn-search {
        position: absolute;
        right: 0.5rem;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: var(--primary);
        font-size: 1.2rem;
        cursor: pointer;
    }

    .category-select,
    .sort-select {
        padding: 0.75rem 1rem;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        font-size: 1rem;
        background-color: #ffffff;
        min-width: 150px;
    }

    .products-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 2rem;
        margin-bottom: 3rem;
    }

    .product-card {
        background-color: #ffffff;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        position: relative;
    }

    .product-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
    }

    .product-card.featured {
        border: 2px solid var(--primary);
    }

    .featured-badge {
        position: absolute;
        top: 1rem;
        right: 1rem;
        background-color: var(--primary);
        color: #ffffff;
        padding: 0.25rem 0.5rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: bold;
    }

    .product-image {
        height: 200px;
        overflow: hidden;
    }

    .product-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s ease;
    }

    .product-card:hover .product-image img {
        transform: scale(1.05);
    }

    .product-info {
        padding: 1.5rem;
    }

    .product-title {
        font-size: 1.2rem;
        margin-bottom: 0.5rem;
        color: var(--foreground);
    }

    .product-category {
        font-size: 0.9rem;
        color: var(--muted-foreground);
        margin-bottom: 1rem;
    }

    .product-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
    }

    .product-price {
        font-size: 1.2rem;
        font-weight: bold;
        color: var(--primary);
    }

    .product-rating {
        color: #ffc107;
    }

    .product-vendor {
        font-size: 0.9rem;
        color: var(--muted-foreground);
    }

    .product-actions {
        padding: 1rem 1.5rem;
        background-color: #f8f9fa;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .btn-details {
        flex: 1;
        text-align: center;
    }

    .btn-wishlist {
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        margin-left: 1rem;
    }

    .marketplace-cta {
        background-color: #ffffff;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 3rem;
        text-align: center;
    }

    .marketplace-cta h2 {
        font-size: 2rem;
        color: var(--foreground);
        margin-bottom: 1rem;
    }

    .marketplace-cta p {
        font-size: 1.1rem;
        color: var(--muted-foreground);
        margin-bottom: 2rem;
    }

    .btn-large {
        padding: 1rem 2rem;
        font-size: 1.1rem;
    }

    .no-results {
        text-align: center;
        padding: 3rem;
        background-color: #ffffff;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
    }

    .no-results i {
        font-size: 3rem;
        color: var(--muted-foreground);
        margin-bottom: 1rem;
    }

    .no-results p {
        font-size: 1.1rem;
        color: var(--muted-foreground);
    }

    @media (max-width: 768px) {
        .search-form {
            flex-direction: column;
        }

        .search-input-wrapper,
        .category-select,
        .sort-select {
            width: 100%;
        }

        .products-grid {
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        }
    }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const wishlistButtons = document.querySelectorAll('.btn-wishlist');
        wishlistButtons.forEach(button => {
            button.addEventListener('click', function() {
                this.classList.toggle('active');
                const icon = this.querySelector('i');
                if (this.classList.contains('active')) {
                    icon.classList.remove('far');
                    icon.classList.add('fas');
                } else {
                    icon.classList.remove('fas');
                    icon.classList.add('far');
                }
            });
        });
    });
    </script>
    <?php
    renderFooter();
}
function getCSS() {
    return '
    :root {
        --primary: #25D366;
        --primary-hover: #128C7E;
        --secondary: #34B7F1;
        --background: #f0f2f5;
        --foreground: #333;
        --border: #ddd;
        --card-bg: #ffffff;
        --radius: 12px;
        --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        --transition: all 0.3s ease;
        --muted-foreground: #777;
    }

    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    body {
        font-family: "Segoe UI", Arial, sans-serif;
        line-height: 1.6;
        color: var(--foreground);
        background-image: linear-gradient(to bottom right, rgba(255, 255, 255, 0.9), rgba(240, 240, 240, 1));
        backdrop-filter: blur(5px);
        margin: 0;
        padding: 0;
    }

    .container {
        width: 100%;
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 20px;
    }

    header {
        background: linear-gradient(to bottom, rgba(255, 255, 255, 0.8), rgba(255, 255, 255, 0.9));
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        padding: 1rem 0;
        position: sticky;
        top: 0;
        z-index: 1000;
        transition: background-color 0.3s ease, box-shadow 0.3s ease;
    }

    header.scrolled {
        background-color: rgba(255, 255, 255, 0.95);
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
    }

    header .container {
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: transform 0.3s ease;
    }

    header.scrolled .container {
        transform: translateY(-2px);
    }

    header h1 {
        font-size: 1.5rem;
        color: var(--primary);
        margin: 0;
        transition: color 0.3s ease;
    }

    header.scrolled h1 {
        color: var(--secondary);
    }

    .btn-gold {
    background-color: #FFD700;
    color: #000;
}

.btn-gold:hover {
    background-color: #FFC600;
}

.impulso-info {
    font-style: italic;
    color: #666;
    margin-top: 10px;
}

    header nav a {
        text-decoration: none;
        color: var(--foreground);
        padding: 0.5rem 1rem;
        border-radius: var(--radius);
        transition: background-color 0.3s ease, color 0.3s ease;
    }

    header nav a:hover {
        background-color: var(--primary);
        color: #fff;
        transform: scale(1.05);
    }

    .logo {
        display: flex;
        align-items: center;
        text-decoration: none;
        color: var(--primary);
        font-size: 1.5rem;
        font-weight: bold;
    }

    .logo-image {
        height: 100px; 
        margin-right: 10px;
    }

    .logo-text {
        font-size: 1.5rem;
        font-weight: bold;
    }

    nav {
        display: flex;
        align-items: center;
    }

    .menu-toggle {
        display: none;
        font-size: 1.5rem;
        cursor: pointer;
    }

    .nav-menu {
        display: flex;
        list-style-type: none;
        margin: 0;
        padding: 0;
    }

    .nav-menu li {
        margin-left: 1rem;
    }

    main {
        padding: 2rem 0;
    }

    h1, h2 {
        margin-bottom: 1rem;
        color: var(--primary);
    }

    .btn {
        display: inline-block;
        padding: 0.5rem 1rem;
        background-color: var(--primary);
        color: #fff;
        text-decoration: none;
        border-radius: var(--radius);
        transition: var(--transition);
        border: none;
        cursor: pointer;
        font-size: 1rem;
    }

    .btn:hover {
        background-color: var(--primary-hover);
        transform: translateY(-2px);
    }

    .btn-ghost {
        background-color: transparent;
        color: var(--primary);
    }

    .btn-ghost:hover {
        background-color: rgba(37, 211, 102, 0.1);
    }

    .btn-outline {
        background-color: transparent;
        color: var(--primary);
        border: 2px solid var(--primary);
    }

    .btn-outline:hover {
        background-color: var(--primary);
        color: #fff;
    }

    .form {
        background-color: var(--card-bg);
        padding: 2rem;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
    }

    .form-group {
        margin-bottom: 1rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 600;
    }

    .form-group input,
    .form-group textarea,
    .form-group select {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        font-size: 1rem;
        transition: var(--transition);
    }

    .form-group input:focus,
    .form-group textarea:focus,
    .form-group select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 2px rgba(37, 211, 102, 0.2);
    }

    .error {
        color: #dc3545;
        margin-bottom: 1rem;
        padding: 0.5rem;
        background-color: #f8d7da;
        border-radius: var(--radius);
    }

    .success {
        color: #28a745;
        margin-bottom: 1rem;
        padding: 0.5rem;
        background-color: #d4edda;
        border-radius: var(--radius);
    }

   .groups-grid,
    .marketplace-grid,
    .admin-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 1.5rem;
        padding: 1rem;
    }

    .group-card,
    .marketplace-card,
    .admin-card {
        background-color: var(--card-bg);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        position: relative;
        border: 1px solid transparent;
    }

    .group-card:hover,
    .marketplace-card:hover,
    .admin-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        border-color: var(--primary);
    }

    .group-card img,
    .marketplace-card img,
    .admin-card img {
        width: 100%;
        height: 180px;
        object-fit: cover;
        transition: transform 0.3s ease;
    }

    .group-card:hover img,
    .marketplace-card:hover img,
    .admin-card:hover img {
        transform: scale(1.05);
    }

    .group-content,
    .marketplace-content,
    .admin-content {
        padding: 1.25rem;
        text-align: center;
    }

    .group-content h3,
    .marketplace-content h3,
    .admin-content h3 {
        margin: 0.5rem 0;
        font-size: 1.25rem;
        color: var(--text-color);
    }

    .group-content p,
    .marketplace-content p,
    .admin-content p {
        color: var(--text-muted);
        font-size: 0.9rem;
        line-height: 1.5;
    }

    .group-card:hover .group-content,
    .marketplace-card:hover .marketplace-content,
    .admin-card:hover .admin-content {
        background-color: rgba(255, 255, 255, 0.1);
    }

    .category-badge {
        display: inline-block;
        background-color: var(--secondary);
        color: #fff;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        margin-bottom: 0.75rem;
    }

    .pagination {
        display: flex;
        justify-content: center;
        margin-top: 2rem;
    }

    .pagination a {
        display: inline-block;
        padding: 0.5rem 1rem;
        margin: 0 0.25rem;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        text-decoration: none;
        color: var(--foreground);
        transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        font-weight: 500;
    }

    .pagination a:hover,
    .pagination a.active {
        background-color: var(--primary);
        color: #fff;
        border-color: var(--primary);
    }

    .pagination a.disabled {
        color: var(--disabled);
        border-color: var(--disabled);
        pointer-events: none;
        opacity: 0.6;
    }

    .pagination a:first-child,
    .pagination a:last-child {
        font-weight: bold;
    }

    .pagination a.current {
        background-color: var(--highlight);
        color: #fff;
        border-color: var(--highlight);
        font-weight: bold;
    }

    footer {
        background-color: var(--card-bg);
        text-align: center;
        padding: 1.5rem 0;
        margin-top: 2rem;
        box-shadow: 0 -2px 4px rgba(0,0,0,0.1);
    }

    .search-section {
        background-color: var(--card-bg);
        padding: 2rem;
        border-radius: var(--radius);
        margin-bottom: 2rem;
        box-shadow: var(--shadow);
        border: 1px solid rgba(0, 0, 0, 0.1);
    }

    .search-form {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        align-items: center;
    }

    .search-form input[type="text"],
    .search-form select {
        flex: 1;
        min-width: 200px;
        padding: 0.75rem;
        border: 1px solid rgba(0, 0, 0, 0.2);
        border-radius: var(--radius);
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }

    .search-form input[type="text"]:focus,
    .search-form select:focus {
        border-color: var(--primary);
        box-shadow: 0 0 5px rgba(0, 123, 255, 0.5);
    }

    .search-form button {
        padding: 0.75rem 1.5rem;
        background-color: var(--primary);
        color: #fff;
        border: none;
        border-radius: var(--radius);
        cursor: pointer;
        transition: background-color 0.3s ease;
    }

    .category-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 1rem;
    }

    .category-button {
        background-color: var(--card-bg);
        color: var(--primary);
        border: 1px solid var(--primary);
        padding: 0.5rem 1rem;
        border-radius: var(--radius);
        cursor: pointer;
        transition: var(--transition);
    }

    .category-button:hover,
    .category-button.active {
        background-color: var(--primary);
        color: var(--card-bg);
    }

    .featured-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        background-color: #FFD700;
        color: #fff;
        padding: 0.25rem 0.5rem;
        border-radius: var(--radius);
        font-size: 0.8rem;
        display: flex;
        align-items: center;
        gap: 0.25rem;
    }

    .group-actions,
    .admin-actions {
        display: flex;
        justify-content: space-between;
        margin-top: 1rem;
    }

    .product-details {
        background-color: var(--card-bg);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 2rem;
        margin-top: 2rem;
    }

    .product-image {
        max-width: 100%;
        height: auto;
        border-radius: var(--radius);
        margin-bottom: 1rem;
    }

    .profile-info,
    .vendor-info {
        background-color: var(--card-bg);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 2rem;
        margin-bottom: 2rem;
    }

    .developer-info {
        background-color: var(--card-bg);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        padding: 2rem;
        margin-bottom: 2rem;
        opacity: 0;
        transform: translateY(20px);
        animation: fadeInUp 0.5s ease-out forwards;
    }

    @keyframes fadeInUp {
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .developer-image {
        max-width: 200px;
        border-radius: 50%;
        margin-bottom: 1rem;
    }

    @media (max-width: 768px) {
        .menu-toggle {
            display: block;
        }

        .nav-menu {
            display: none;
            flex-direction: column;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background-color: var(--card-bg);
            box-shadow: var(--shadow);
            padding: 1rem;
        }

        .nav-menu.active {
            display: flex;
        }

        .nav-menu li {
            margin: 0.5rem 0;
        }

        header .container {
            flex-wrap: wrap;
        }

        .search-form {
            flex-direction: column;
        }

        .search-form input[type="text"],
        .search-form select,
        .search-form button {
            width: 100%;
        }

        .groups-grid,
        .marketplace-grid,
        .admin-grid {
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        }
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .fade-in {
        animation: fadeIn 0.5s ease-out;
    }

    .featured-group {
        position: relative;
        overflow: hidden;
    }

    .featured-group::before {
        content: "";
        position: absolute;
        top: -50%;
        left: -50%;
        width: 200%;
        height: 200%;
        background: linear-gradient(
            to bottom right,
            rgba(255, 215, 0, 0.3) 0%,
            rgba(255, 215, 0, 0) 50%,
            rgba(255, 215, 0, 0.3) 100%
        );
        animation: shine 3s infinite linear;
        pointer-events: none;
    }

    @keyframes shine {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    .cart-icon {
        margin-left: 5px;
    }

    nav .active {
        font-weight: bold;
        text-decoration: underline;
    }

    .stars {
        color: #ffd700;
        display: inline-flex;
        gap: 2px;
    }

    .stars .filled {
        color: #ffd700;
    }

    .stars i:not(.filled) {
        color: #ccc;
    }

    .rating-card {
        background: var(--card-bg);
        border-radius: var(--radius);
        padding: 1.5rem;
        margin-bottom: 1rem;
        box-shadow: var(--shadow);
    }

    .rating-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }

    .rating-comment {
        margin-bottom: 1rem;
        line-height: 1.6;
    }

    .rating-date {
        color: var(--muted-foreground);
    }

     .rating-stars {
        display: flex;
        flex-direction: row-reverse;
        gap: 0.25rem;
    }
    .rating-stars input {
        display: none; 
    }

    .rating-stars label {
        cursor: pointer;
        font-size: 1.5rem; 
        color: #ccc; 
    }

    .rating-stars label:hover,
    .rating-stars label:hover ~ label,
    .rating-stars input:checked ~ label {
        color: #ffd700; 
    }

    .rating-notice {
        background: var(--muted);
        padding: 1rem;
        border-radius: var(--radius);
        text-align: center;
        margin-top: 1rem;
    }

    .btn-whatsapp {
        background-color: #25D366;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        justify-content: center;
        margin-top: 1rem;
    }

    .btn-whatsapp:hover {
        background-color: #128C7E;
    }

    .notifications-icon {
        position: relative;
        display: inline-block;
    }

    .notification-badge {
        position: absolute;
        top: -5px;
        right: -5px;
        background-color: #ff4b4b;
        color: white;
        border-radius: 50%;
        padding: 2px 6px;
        font-size: 12px;
        font-weight: bold;
    }

    .notifications-dropdown {
        position: absolute;
        top: 100%;
        right: 0;
        background-color: #ffffff;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        width: 300px;
        max-height: 400px;
        overflow-y: auto;
        z-index: 1000;
        display: none;
    }

    .notifications-dropdown h3 {
        padding: 15px;
        margin: 0;
        border-bottom: 1px solid #e0e0e0;
        font-size: 16px;
        color: #333;
    }

    .notifications-dropdown ul {
        list-style-type: none;
        padding: 0;
        margin: 0;
    }

    .notifications-dropdown li {
        padding: 12px 15px;
        border-bottom: 1px solid #f0f0f0;
        transition: background-color 0.3s ease;
    }

    .notifications-dropdown li:last-child {
        border-bottom: none;
    }

    .notifications-dropdown li:hover {
        background-color: #f8f8f8;
    }

    .notification-message {
        color: #333;
        font-size: 14px;
        line-height: 1.4;
    }

    .notification-time {
        display: block;
        font-size: 12px;
        color: #888;
        margin-top: 5px;
    }

    .dropdown {
        position: relative;
    }

    .dropdown-menu {
        display: none;
        position: absolute;
        top: 100%; 
        left: 0;
        background-color: white;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        z-index: 1000; 
    }

    .dropdown:hover .dropdown-menu {
        display: block; 
    }

    .dropdown-menu li {
        padding: 0.5rem 1rem; 
    }

    .dropdown-menu li a {
        text-decoration: none; 
        color: var(--foreground); 
    }

    .dropdown-menu li a:hover {
        background-color: var(--primary);
        color: white; 
    }

    @media (max-width: 768px) {
        .notifications-dropdown {
            width: 100%;
            max-width: 300px;
            right: -75px;
        }
    }

    @media (min-width: 769px) {
       .mobile-only {
           display: none;
       }
    }

    @media (max-width: 768px) {
       .nav-menu {
           flex-direction: column;
       }

       .nav-menu li {
           margin: 0.5rem 0;
       }

       .mobile-only {
           display: block;
       }
    }

    .image-upload-container {
        position: relative;
        width: 100%;
        height: 200px;
        border: 2px dashed var(--border);
        border-radius: var(--radius);
        display: flex;
        justify-content: center;
        align-items: center;
        overflow: hidden;
    }

    .image-upload-input {
        position: absolute;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
        z-index: 2;
    }

    .image-upload-label {
        display: flex;
        flex-direction: column;
        align-items: center;
        color: var(--muted-foreground);
    }

    .image-upload-label i {
        font-size: 3rem;
        margin-bottom: 1rem;
    }

    .image-preview {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-size: cover;
        background-position: center;
        display: none;
    }

    .image-preview.highlight {
        border-color: var(--primary);
    }

    .marketplace-card.impulsionado {
        border: 2px solid var(--primary);
        box-shadow: 0 0 10px rgba(37, 211, 102, 0.3);
    }

    .impulsionado-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        background-color: var(--primary);
        color: white;
        padding: 5px 10px;
        border-radius: var(--radius);
        font-size: 0.8rem;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .impulsionado-badge i {
        font-size: 1rem;
    }
        .notifications-container {
    position: relative;
    display: inline-block;
}

.notifications-button {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 1.2rem;
    color: var(--foreground);
    position: relative;
    padding: 0.5rem;
    transition: color 0.3s ease;
}

.notifications-button:hover {
    color: var(--primary);
}

.notification-badge {
    position: absolute;
    top: 0;
    right: 0;
    background-color: var(--primary);
    color: white;
    border-radius: 50%;
    padding: 0.2rem 0.4rem;
    font-size: 0.7rem;
    font-weight: bold;
}

.notifications-panel {
    display: none;
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 90%;
    max-width: 400px;
    height: 80%;
    max-height: 600px;
    background-color: var(--card-bg);
    border-radius: var(--radius);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    z-index: 1000;
    overflow-y: auto;
}

.notifications-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    background-color: var(--card-bg);
    z-index: 2;
}

.notifications-header h3 {
    margin: 0;
    font-size: 1rem;
    color: var(--foreground);
    font-weight: 600;
}

.mark-all-read {
    background: none;
    border: none;
    color: var(--primary);
    cursor: pointer;
    font-size: 0.8rem;
    transition: opacity 0.3s ease;
}

.mark-all-read:hover {
    opacity: 0.8;
}

.notifications-list {
    padding: 0;
}

.notification-item {
    display: flex;
    padding: 1rem;
    border-bottom: 1px solid var(--border);
    transition: background-color 0.3s ease;
}

.notification-item:hover {
    background-color: rgba(0, 0, 0, 0.05);
}

.notification-content {
    flex-grow: 1;
}

.notification-message {
    margin: 0 0 0.5rem 0;
    font-size: 0.9rem;
    color: var(--foreground);
    line-height: 1.4;
}

.notification-time {
    font-size: 0.8rem;
    color: var(--muted-foreground);
}

.notification-icon {
    margin-right: 1rem;
    font-size: 1.2rem;
    color: var(--primary);
}

.notifications-footer {
    padding: 1rem;
    text-align: center;
    border-top: 1px solid var(--border);
    position: sticky;
    bottom: 0;
    background-color: var(--card-bg);
}

.view-all-notifications {
    color: var(--primary);
    text-decoration: none;
    font-size: 0.9rem;
    transition: opacity 0.3s ease;
}

.view-all-notifications:hover {
    opacity: 0.8;
}

.unread {
    background-color: rgba(37, 211, 102, 0.1);
}

.notifications-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 999;
}

.close-notifications {
    position: absolute;
    top: 10px;
    right: 10px;
    background: none;
    border: none;
    font-size: 1.5rem;
    color: var(--foreground);
    cursor: pointer;
}

@media (min-width: 768px) {
    .notifications-panel {
        position: absolute;
        top: 100%;
        left: auto;
        right: 0;
        transform: none;
        width: 320px;
        height: auto;
        max-height: 400px;
    }

    .notifications-overlay {
        display: none !important;
    }

    .close-notifications {
        display: none;
    }

    ';
}

function renderEnviarGrupo() {
    requireLogin();

    global $pdo;

    $success = false;
    $errors = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nome_grupo = $_POST['nome_grupo'];
        $categoria = $_POST['categoria'];
        $descrição = $_POST['descrição']; 
        $grupo_link = $_POST['grupo_link'];
        $imagem = $_FILES['imagem'];
        $terms_accepted = isset($_POST['terms_accepted']) ? $_POST['terms_accepted'] : '';

        // Validação dos campos
        if (empty($nome_grupo)) {
            $errors[] = "O nome do grupo é obrigatório.";
        }

        if (empty($categoria)) {
            $errors[] = "A categoria é obrigatória.";
        }

        if (empty($descrição)) {
            $errors[] = "A descrição é obrigatória.";
        } elseif (strlen($descrição) < 100) {
            $errors[] = "A descrição deve ter pelo menos 100 caracteres.";
        }

        if (empty($grupo_link)) {
            $errors[] = "O link do grupo é obrigatório.";
        } elseif (!filter_var($grupo_link, FILTER_VALIDATE_URL)) {
            $errors[] = "O link do grupo é inválido.";
        }

        if (empty($terms_accepted)) {
            $errors[] = "Você precisa aceitar os termos de uso para continuar.";
        }

        if ($imagem['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Erro no upload da imagem.";
        } else {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            if (!in_array($imagem['type'], $allowed_types)) {
                $errors[] = "Tipo de arquivo não permitido. Use apenas JPG, PNG ou GIF.";
            }
        }

        // Se não houver erros, prosseguir com o upload e inserção no banco de dados
        if (empty($errors)) {
            $upload_dir = 'uploads/';
            $filename = uniqid() . '_' . basename($imagem['name']);
            $upload_path = $upload_dir . $filename;

            if (move_uploaded_file($imagem['tmp_name'], $upload_path)) {
                global $pdo;
                // Inserir os dados no banco de dados
                $stmt = $pdo->prepare("INSERT INTO `groups` (`usuario_id`, `nome_grupo`, `categoria`, `descrição`, `grupo_link`, `imagem`)
                                VALUES (:usuario_id, :nome_grupo, :categoria, :descricao, :grupo_link, :imagem)");

                $stmt->execute([
                    ':usuario_id' => $_SESSION['user_id'],
                    ':nome_grupo' => $nome_grupo,
                    ':categoria' => $categoria,
                    ':descricao' => $descrição,
                    ':grupo_link' => $grupo_link,
                    ':imagem' => $upload_path
                ]);
                
                $success = true;
            } else {
                $errors[] = "Erro ao salvar a imagem.";
            }
        }
    }

    renderHeader();
    ?>
    <main>
        <div class="container">
            <h1>Enviar Grupo</h1>
            <?php if (!empty($errors)): ?>
                <div class="error">
                    <?php foreach ($errors as $error): ?>
                        <p><?= htmlspecialchars($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <!-- Card de confirmação -->
                <div class="success-card">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="success-content">
                        <h2>Grupo Enviado com Sucesso!</h2>
                        <p class="success-message">Seu grupo foi cadastrado e está em análise. Em breve estará disponível na plataforma.</p>
                        
                        <div class="success-details">   
                            <div class="success-detail-item">
                                <i class="fas fa-clock"></i>
                                <div>
                                    <h3>Análise em Andamento</h3>
                                    <p>Nossa equipe está verificando seu grupo para garantir que ele atenda às nossas diretrizes.</p>
                                </div>
                            </div>
                            
                            <div class="success-detail-item">
                                <i class="fas fa-rocket"></i>
                                <div>
                                    <h3>Impulsione seu Grupo</h3>
                                    <p>Aumente a visibilidade do seu grupo com nosso serviço de impulsionamento por apenas R$ 5,00.</p>
                                </div>
                            </div>
                            
                            <div class="success-detail-item">
                                <i class="fas fa-shield-alt"></i>
                                <div>
                                    <h3>Termos de Uso</h3>
                                    <p>Lembre-se que seu grupo deve seguir nossos termos de uso para permanecer na plataforma.</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="success-actions">
                            <a href="?page=meus-grupos" class="btn btn-primary">Ver Meus Grupos</a>
                            <a href="?page=enviar-grupo" class="btn btn-outline">Enviar Outro Grupo</a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <form action="?page=enviar-grupo" method="POST" enctype="multipart/form-data" class="form" id="groupForm">
                    <div class="form-group">
                        <label for="nome_grupo">Nome do Grupo</label>
                        <input type="text" id="nome_grupo" name="nome_grupo" required>
                    </div>
                    <div class="form-group">
                        <label for="categoria">Categoria</label>
                        <input type="hidden" id="categoria" name="categoria" required>
                        <div class="category-grid">
                            <?php
                            $categories = [
                                ['AMIZADE', 'users'],
                                ['NAMORO', 'heart'],
                                ['STATUS', 'image'],
                                ['POLÍTICA', 'alert-triangle'],
                                ['VAGAS DE EMPREGOS', 'briefcase'],
                                ['ENTRETENIMENTO', 'tv'],
                                ['APOSTAS', 'dice'],
                                ['JOGOS', 'gamepad'],
                                ['MÚSICA', 'music'],
                                ['VENDAS', 'shopping-cart'],
                                ['FILMES', 'film'],
                                ['ANIME', 'star'],
                                ['REDE SOCIAL', 'share-2'],
                                ['LINKS', 'link'],
                                ['SHITPOST', 'link'],
                                ['OUTROS', 'zap'],
                                ['LIVROS', 'book'],
                                ['SAÚDE E BEM-ESTAR', 'heart-rate'],
                                ['TECNOLOGIA', 'laptop'],
                                ['VIAGENS', 'plane'],
                                ['CULTURA', 'globe'],
                                ['HUMOR', 'smile'],
                                ['DESIGN E ARTE', 'palette'],
                                ['EDUCAÇÃO', 'graduation-cap'],
                                ['FOTOGRAFIA', 'camera'],
                                ['EVENTOS', 'calendar'],
                                ['DICAS E TRUQUES', 'lightbulb'],
                                ['VOLUNTARIADO', 'hands-helping'],
                            ];
                            
                            foreach ($categories as $cat):
                                list($category, $icon) = $cat;
                            ?>
                                <div class="category-item" data-category="<?= htmlspecialchars($category) ?>" onclick="selectCategory(this)">
                                    <i class="fas fa-<?= $icon ?>"></i>
                                    <span><?= htmlspecialchars($category) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="descrição">Descrição <span class="char-counter" id="charCounter">0/100 caracteres (mínimo)</span></label>
                        <textarea id="descrição" name="descrição" required minlength="100"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="grupo_link">Link do Grupo</label>
                        <input type="url" id="grupo_link" name="grupo_link" required>
                    </div>
                    <div class="form-group">
                        <label for="imagem">Imagem do Grupo</label>
                        <div class="image-upload-container">
                            <input type="file" id="imagem" name="imagem" accept="image/*" required class="image-upload-input">
                            <label for="imagem" class="image-upload-label">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <span>Escolha uma imagem ou Gif</span>
                            </label>
                            <div id="image-preview" class="image-preview"></div>
                        </div>
                    </div>
                    <div class="form-group terms-checkbox">
                        <label for="terms_accepted" class="terms-label">
                            <input type="checkbox" id="terms_accepted" name="terms_accepted" value="1" required>
                            <span>Li e aceito os <a href="?page=termos" target="_blank">Termos de Uso</a> para envio de grupos</span>
                        </label>
                    </div>
                    <button type="submit" class="btn btn-primary" id="submitButton">Enviar Grupo</button>
                </form>
            <?php endif; ?>
        </div>
    </main>

    <style>
    .category-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 1rem;
        margin-top: 1rem;
    }

    .category-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-width: 100px;
        height: 100px;
        padding: 0.5rem;
        background-color: var(--card-bg);
        border: 2px solid var(--border);
        border-radius: var(--radius);
        cursor: pointer;
        transition: all 0.3s ease;
        user-select: none;
    }

    .category-item:hover {
        border-color: var(--primary);
        background-color: rgba(37, 211, 102, 0.1);
        transform: translateY(-2px);
    }

    .category-item.selected {
        background-color: var(--primary);
        border-color: var(--primary);
        color: white;
    }

    .category-item i {
        font-size: 2rem;
        margin-bottom: 0.5rem;
        color: var(--primary);
    }

    .category-item.selected i {
        color: white;
    }

    .category-item span {
        font-size: 0.9rem;
        text-align: center;
        word-break: break-word;
    }

    .terms-checkbox {
        margin: 1.5rem 0;
        padding: 1rem;
        background-color: rgba(37, 211, 102, 0.1);
        border-radius: var(--radius);
        border-left: 4px solid var(--primary);
    }

    .terms-label {
        display: flex;
        align-items: flex-start;
        gap: 0.5rem;
        cursor: pointer;
    }

    .terms-label input {
        margin-top: 0.25rem;
    }

    .terms-label a {
        color: var(--primary);
        font-weight: bold;
        text-decoration: underline;
    }

    /* Melhorias na caixa de upload de imagem */
    .image-upload-container {
        position: relative;
        width: 100%;
        height: 300px; /* Aumentado de 200px para 300px */
        border: 3px dashed var(--border);
        border-radius: var(--radius);
        display: flex;
        justify-content: center;
        align-items: center;
        overflow: hidden;
        background-color: #f9f9f9;
        transition: all 0.3s ease;
    }

    .image-upload-container:hover {
        border-color: var(--primary);
        background-color: rgba(37, 211, 102, 0.05);
    }

    .image-upload-input {
        position: absolute;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
        z-index: 2;
    }

    .image-upload-label {
        display: flex;
        flex-direction: column;
        align-items: center;
        color: var(--muted-foreground);
        text-align: center;
        padding: 2rem;
    }

    .image-upload-label i {
        font-size: 4rem; /* Aumentado de 3rem para 4rem */
        margin-bottom: 1.5rem;
        color: var(--primary);
        opacity: 0.7;
    }

    .image-upload-label span {
        font-size: 1.1rem;
        max-width: 80%;
        line-height: 1.5;
    }

    .image-preview {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-size: cover;
        background-position: center;
        display: none;
        z-index: 1;
    }

    .image-preview.highlight {
        border-color: var(--primary);
    }

    /* Contador de caracteres */
    .char-counter {
        font-size: 0.8rem;
        color: var(--muted-foreground);
        margin-left: 0.5rem;
        font-weight: normal;
    }

    /* Card de sucesso */
    .success-card {
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        border-radius: var(--radius);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        margin: 2rem 0;
        position: relative;
        border: 1px solid rgba(37, 211, 102, 0.2);
        animation: slideIn 0.5s ease-out;
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .success-icon {
        position: absolute;
        top: -30px;
        left: 50%;
        transform: translateX(-50%);
        width: 80px;
        height: 80px;
        background: var(--primary);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 2.5rem;
        box-shadow: 0 5px 15px rgba(37, 211, 102, 0.3);
        border: 5px solid white;
    }

    .success-content {
        padding: 4rem 2rem 2rem;
        text-align: center;
    }

    .success-content h2 {
        color: var(--primary);
        margin-bottom: 1rem;
        font-size: 1.8rem;
    }

    .success-message {
        color: var(--foreground);
        font-size: 1.1rem;
        margin-bottom: 2rem;
    }

    .success-details {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }

    .success-detail-item {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
        text-align: left;
        padding: 1.5rem;
        background-color: #ffffff;
        border-radius: var(--radius);
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .success-detail-item:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
    }

    .success-detail-item i {
        font-size: 2rem;
        color: var(--primary);
    }

    .success-detail-item h3 {
        margin: 0 0 0.5rem;
        font-size: 1.2rem;
        color: var(--foreground);
    }

    .success-detail-item p {
        margin: 0;
        color: var(--muted-foreground);
        font-size: 0.9rem;
        line-height: 1.5;
    }

    .success-actions {
        display: flex;
        justify-content: center;
        gap: 1rem;
        margin-top: 2rem;
    }

    .success-actions .btn {
        padding: 0.75rem 1.5rem;
        font-weight: 600;
    }

    /* Animação de fade-in para as categorias */
    .category-item {
        opacity: 0;
        animation: fadeIn 0.3s ease forwards;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Adicionar delay na animação para cada item */
    <?php for($i = 0; $i < count($categories); $i++): ?>
    .category-item:nth-child(<?= $i + 1 ?>) {
        animation-delay: <?= $i * 0.05 ?>s;
    }
    <?php endfor; ?>

    /* Responsividade */
    @media (max-width: 768px) {
        .success-details {
            grid-template-columns: 1fr;
        }

        .success-actions {
            flex-direction: column;
        }

        .success-actions .btn {
            width: 100%;
        }

        .image-upload-container {
            height: 200px;
        }

        .image-upload-label i {
            font-size: 3rem;
        }
    }
    </style>

    <script>
    function selectCategory(element) {
        // Remove selected class from all categories
        document.querySelectorAll('.category-item').forEach(item => {
            item.classList.remove('selected');
        });
        
        // Add selected class to clicked category
        element.classList.add('selected');
        
        // Update hidden input value
        document.getElementById('categoria').value = element.dataset.category;
    }

    document.addEventListener('DOMContentLoaded', function() {
        const imageInput = document.getElementById('imagem');
        const imagePreview = document.getElementById('image-preview');
        const imageLabel = document.querySelector('.image-upload-label span');
        const termsCheckbox = document.getElementById('terms_accepted');
        const submitButton = document.getElementById('submitButton');
        const groupForm = document.getElementById('groupForm');
        const descricaoTextarea = document.getElementById('descrição');
        const charCounter = document.getElementById('charCounter');

        // Contador de caracteres
        if (descricaoTextarea && charCounter) {
            descricaoTextarea.addEventListener('input', function() {
                const currentLength = this.value.length;
                const minLength = 100;
                charCounter.textContent = `${currentLength}/${minLength} caracteres (mínimo)`;
                
                if (currentLength < minLength) {
                    charCounter.style.color = '#dc3545';
                } else {
                    charCounter.style.color = '#28a745';
                }
            });
        }

        // Preview de imagem
        if (imageInput) {
            imageInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        imagePreview.style.backgroundImage = `url(${e.target.result})`;
                        imagePreview.style.display = 'block';
                        imageLabel.textContent = file.name;
                    }
                    reader.readAsDataURL(file);
                }
            });
        }

        // Form validation
        if (groupForm) {
            groupForm.addEventListener('submit', function(e) {
                let isValid = true;
                
                // Validar categoria
                if (!document.getElementById('categoria').value) {
                    alert('Por favor, selecione uma categoria.');
                    isValid = false;
                }
                
                // Validar descrição
                if (descricaoTextarea.value.length < 100) {
                    alert('A descrição deve ter pelo menos 100 caracteres.');
                    isValid = false;
                }
                
                // Validar termos
                if (!termsCheckbox.checked) {
                    alert('Você precisa aceitar os termos de uso para continuar.');
                    isValid = false;
                }
                
                if (!isValid) {
                    e.preventDefault();
                    return false;
                }
                
                return true;
            });
        }

        // Drag and drop para imagens
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            document.querySelector('.image-upload-container').addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            document.querySelector('.image-upload-container').addEventListener(eventName, highlight, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            document.querySelector('.image-upload-container').addEventListener(eventName, unhighlight, false);
        });

        function highlight(e) {
            document.querySelector('.image-upload-container').classList.add('highlight');
        }

        function unhighlight(e) {
            document.querySelector('.image-upload-container').classList.remove('highlight');
        }

        document.querySelector('.image-upload-container').addEventListener('drop', handleDrop, false);

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const file = dt.files[0];
            
            if (file && imageInput) {
                // Criar um novo objeto DataTransfer
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                
                // Atribuir os arquivos ao input
                imageInput.files = dataTransfer.files;
                
                // Mostrar preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    imagePreview.style.backgroundImage = `url(${e.target.result})`;
                    imagePreview.style.display = 'block';
                    imageLabel.textContent = file.name;
                }
                reader.readAsDataURL(file);
            }
        }
    });
    </script>
    <?php
    renderFooter();
}



function renderTermos() {
    renderHeader();
    ?>
    <main class="terms-page">
        <div class="container">
            <div class="terms-container">
                <div class="terms-header">
                    <h1>Termos de Uso para Envio de Grupos</h1>
                    <p class="terms-subtitle">Leia atentamente antes de enviar seu grupo para o ZapLinks</p>
                </div>
                
                <div class="terms-content-wrapper">
                    <div class="terms-image-container">
                        <!-- Substitua o src pela URL da sua imagem quando estiver pronto -->
                        <img src="/zaplinks - termos.png" alt="Ilustração dos Termos de Uso" class="terms-image">
                    </div>
                    
                    <div class="terms-content">
                        <div class="terms-section">
                            <h2><i class="fas fa-check-circle"></i> Regras Gerais</h2>
                            <ul>
                                <li>O grupo deve respeitar as leis brasileiras e internacionais.</li>
                                <li>É proibido o compartilhamento de conteúdo ilegal, incluindo, mas não limitado a: pornografia infantil, conteúdo que promova violência, discriminação, ódio ou terrorismo.</li>
                                <li>O nome e a descrição do grupo devem refletir com precisão o conteúdo e o propósito do grupo.</li>
                                <li>O link do grupo deve ser válido e funcional no momento do envio.</li>
                                <li>A imagem do grupo não deve conter conteúdo ofensivo, nudez ou violência explícita.</li>
                            </ul>
                        </div>
                        
                        <div class="terms-section">
                            <h2><i class="fas fa-shield-alt"></i> Responsabilidades</h2>
                            <ul>
                                <li>O usuário que envia o grupo é responsável pelo conteúdo compartilhado dentro do grupo.</li>
                                <li>O ZapLinks não se responsabiliza por atividades realizadas dentro dos grupos listados na plataforma.</li>
                                <li>O usuário concorda em remover o grupo da plataforma caso ele viole qualquer um dos termos de uso.</li>
                                <li>O ZapLinks reserva-se o direito de remover qualquer grupo que viole estes termos sem aviso prévio.</li>
                            </ul>
                        </div>
                        
                        <div class="terms-section">
                            <h2><i class="fas fa-exclamation-triangle"></i> Conteúdo Proibido</h2>
                            <ul>
                                <li>Pornografia ou conteúdo sexual explícito sem a devida classificação etária.</li>
                                <li>Promoção de atividades ilegais, como venda de drogas, armas ou produtos contrabandeados.</li>
                                <li>Conteúdo que viole direitos autorais ou propriedade intelectual.</li>
                                <li>Discurso de ódio, bullying, assédio ou qualquer forma de discriminação.</li>
                                <li>Informações pessoais de terceiros sem consentimento.</li>
                                <li>Spam, phishing ou distribuição de malware.</li>
                            </ul>
                        </div>
                        
                        <div class="terms-section">
                            <h2><i class="fas fa-gavel"></i> Penalidades</h2>
                            <ul>
                                <li>Grupos que violem estes termos serão removidos da plataforma.</li>
                                <li>Usuários que repetidamente violem os termos podem ter suas contas suspensas ou banidas.</li>
                                <li>Em casos graves, o ZapLinks pode reportar atividades ilegais às autoridades competentes.</li>
                            </ul>
                        </div>
                        
                        <div class="terms-section">
                            <h2><i class="fas fa-sync-alt"></i> Atualizações dos Termos</h2>
                            <p>O ZapLinks reserva-se o direito de modificar estes termos a qualquer momento. As alterações entrarão em vigor imediatamente após sua publicação na plataforma. É responsabilidade do usuário verificar periodicamente os termos de uso para estar ciente de quaisquer alterações.</p>
                        </div>
                    </div>
                </div>
                
                <div class="terms-agreement">
                    <p>Ao clicar em "Estou de Acordo", você confirma que leu, entendeu e concorda com todos os termos e condições acima.</p>
                    <a href="?page=enviar-grupo" class="btn btn-primary btn-agree">
                        <i class="fas fa-check"></i> Estou de Acordo
                    </a>
                </div>
            </div>
        </div>
    </main>

    <style>
    .terms-page {
        background-color: #f8f9fa;
        padding: 2rem 0;
    }

    .terms-container {
        background-color: #ffffff;
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        overflow: hidden;
        max-width: 1200px;
        margin: 0 auto;
    }

    .terms-header {
        background: linear-gradient(135deg, var(--primary) 0%, #128C7E 100%);
        color: white;
        padding: 2rem;
        text-align: center;
    }

    .terms-header h1 {
        margin: 0;
        font-size: 2rem;
        color: white;
    }

    .terms-subtitle {
        margin-top: 0.5rem;
        font-size: 1rem;
        opacity: 0.9;
    }

    .terms-content-wrapper {
        display: flex;
        align-items: flex-start;
        padding: 2rem;
    }

    .terms-image-container {
        flex: 0 0 300px;
        margin-right: 2rem;
    }

    .terms-image {
        width: 100%;
        height: auto;
        border-radius: var(--radius);
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
    }

    .terms-content {
        flex: 1;
    }

    .terms-section {
        margin-bottom: 2rem;
    }

    .terms-section h2 {
        color: var(--primary);
        font-size: 1.5rem;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .terms-section ul {
        list-style-type: none;
        padding-left: 1.5rem;
    }

    .terms-section li {
        position: relative;
        padding-left: 1.5rem;
        margin-bottom: 0.75rem;
        line-height: 1.6;
    }

    .terms-section li::before {
        content: "•";
        position: absolute;
        left: 0;
        color: var(--primary);
        font-weight: bold;
    }

    .terms-agreement {
        background-color: #f8f9fa;
        padding: 2rem;
        text-align: center;
        border-top: 1px solid #eee;
    }

    .terms-agreement p {
        margin-bottom: 1.5rem;
        font-size: 1.1rem;
    }

    .btn-agree {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 1rem 2rem;
        font-size: 1.1rem;
        font-weight: 600;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .btn-agree:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    /* Animações */
    .terms-header {
        animation: slideDown 0.5s ease-out;
    }

    .terms-section {
        opacity: 0;
        animation: fadeIn 0.5s ease-out forwards;
    }

    .terms-section:nth-child(1) { animation-delay: 0.1s; }
    .terms-section:nth-child(2) { animation-delay: 0.2s; }
    .terms-section:nth-child(3) { animation-delay: 0.3s; }
    .terms-section:nth-child(4) { animation-delay: 0.4s; }
    .terms-section:nth-child(5) { animation-delay: 0.5s; }

    .terms-agreement {
        animation: slideUp 0.5s ease-out;
    }

    @keyframes slideDown {
        from { transform: translateY(-20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    @keyframes slideUp {
        from { transform: translateY(20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    @media (max-width: 1024px) {
        .terms-content-wrapper {
            flex-direction: column;
        }

        .terms-image-container {
            margin-right: 0;
            margin-bottom: 2rem;
            max-width: 300px;
            margin-left: auto;
            margin-right: auto;
        }
    }

    @media (max-width: 768px) {
        .terms-header h1 {
            font-size: 1.5rem;
        }

        .terms-content {
            padding: 1.5rem;
        }

        .terms-section h2 {
            font-size: 1.25rem;
        }
    }
    </style>
    <?php
    renderFooter();
}
function renderLogin() {
    global $pdo;

    $errors = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $errors[] = "Por favor, preencha todos os campos.";
        } else {
            $stmt = $pdo->prepare("SELECT id, username, password, is_admin FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['is_admin'] = $user['is_admin'];
                header("Location: ?page=meus-grupos");
                exit;
            } else {
                $errors[] = "Nome de usuário ou senha inválidos.";
            }
        }
    }

    renderHeader();
    ?>
    <main class="auth-page">
        <div class="auth-container">
            <div class="auth-image">
                <img src="/login.gif" alt="Login" class="auth-background">
                <div class="auth-overlay"></div>
            </div>
            <div class="auth-form-container">
                <h1>Bem-vindo de volta!</h1>
                <p class="auth-subtitle">Entre na sua conta ZapLinks</p>
                <?php if (!empty($errors)): ?>
                    <div class="error-messages">
                        <?php foreach ($errors as $error): ?>
                            <p><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form action="?page=login" method="POST" class="auth-form">
                    <div class="form-group">
                        <label for="username">Nome de Usuário</label>
                        <div class="input-icon-wrapper">
                            <i class="fas fa-user"></i>
                            <input type="text" id="username" name="username" required placeholder="Seu nome de usuário">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="password">Senha</label>
                        <div class="input-icon-wrapper">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="password" name="password" required placeholder="Sua senha">
                        </div>
                    </div>
                    <div class="form-options">
                        <label class="remember-me">
                            <input type="checkbox" name="remember" id="remember">
                            <span>Lembrar-me</span>
                        </label>
                        <a href="?page=forgot-password" class="forgot-password">Esqueceu a senha?</a>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sign-in-alt"></i> Entrar
                    </button>
                </form>
                <p class="auth-link">Não tem uma conta? <a href="?page=register">Cadastre-se</a></p>
            </div>
        </div>
    </main>

    <style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');

    .auth-page {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: #f0f2f5;
        padding: 20px;
        font-family: 'Poppins', sans-serif;
    }

    .auth-container {
        display: flex;
        width: 100%;
        max-width: 1000px;
        background-color: #ffffff;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    }

    .auth-image {
        flex: 1;
        position: relative;
        overflow: hidden;
        min-height: 300px;
    }

    .auth-background {
        width: 100%;
        height: 100%;
        object-fit: cover;
        object-position: center;
        position: absolute;
        top: 0;
        left: 0;
        transition: transform 0.3s ease;
    }

    .auth-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(45deg, rgba(76, 175, 80, 0.6), rgba(33, 150, 243, 0.6));
        z-index: 1;
    }

    .auth-form-container {
        flex: 1;
        padding: 50px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        background-color: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(10px);
        position: relative;
        z-index: 2;
    }

    .auth-form-container h1 {
        margin-bottom: 10px;
        color: var(--primary);
        font-size: 2.5rem;
        font-weight: 700;
        text-align: center;
    }

    .auth-subtitle {
        text-align: center;
        color: var(--muted-foreground);
        margin-bottom: 30px;
        font-size: 1.1rem;
    }

    .auth-form {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .form-group label {
        font-weight: 500;
        color: var(--foreground);
        font-size: 0.9rem;
    }

    .input-icon-wrapper {
        position: relative;
    }

    .input-icon-wrapper i {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--muted-foreground);
    }

    .form-group input {
        padding: 12px 12px 12px 40px;
        border: 1px solid var(--border);
        border-radius: 10px;
        font-size: 1rem;
        background-color: rgba(255, 255, 255, 0.8);
        transition: all 0.3s ease;
    }

    .form-group input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 2px rgba(var(--primary-rgb), 0.2);
    }

    .form-options {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.9rem;
    }

    .remember-me {
        display: flex;
        align-items: center;
        gap: 5px;
        cursor: pointer;
    }

    .forgot-password {
        color: var(--primary);
        text-decoration: none;
        font-weight: 500;
        transition: color 0.3s ease;
    }

    .forgot-password:hover {
        color: var(--primary-dark);
    }

    .btn-primary {
        background-color: var(--primary);
        color: white;
        padding: 12px;
        border: none;
        border-radius: 10px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }

    .btn-primary:hover {
        background-color: var(--primary-dark);
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(var(--primary-rgb), 0.3);
    }

    .auth-link {
        margin-top: 20px;
        text-align: center;
        color: var(--muted-foreground);
        font-size: 0.9rem;
    }

    .auth-link a {
        color: var(--primary);
        text-decoration: none;
        font-weight: 600;
        transition: color 0.3s ease;
    }

    .auth-link a:hover {
        color: var(--primary-dark);
    }

    .error-messages {
        background-color: #f8d7da;
        color: #721c24;
        padding: 15px;
        border-radius: 10px;
        margin-bottom: 20px;
    }

    .error-messages p {
        margin: 5px 0;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    @media (max-width: 768px) {
        .auth-container {
            flex-direction: column;
        }

        .auth-image {
            height: 200px;
        }

        .auth-form-container {
            padding: 30px 20px;
        }
    }

    @media (max-width: 480px) {
        .auth-image {
            height: 150px;
        }

        .auth-form-container h1 {
            font-size: 2rem;
        }

        .auth-subtitle {
            font-size: 1rem;
        }

        .form-group input {
            padding: 10px 10px 10px 35px;
        }

        .form-options {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
    }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const authImage = document.querySelector('.auth-background');

        // Efeito parallax suave na imagem de fundo
        document.addEventListener('mousemove', function(e) {
            const moveX = (e.clientX - window.innerWidth / 2) * 0.01;
            const moveY = (e.clientY - window.innerHeight / 2) * 0.01;
            authImage.style.transform = `translate(${moveX}px, ${moveY}px)`;
        });
    });
    </script>
    <?php
    renderFooter();
}



function renderRegister() {
    global $pdo;

    $errors = [];
    $success = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = $_POST['username'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validações
        if (empty($username)) {
            $errors[] = "O nome de usuário é obrigatório.";
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "E-mail inválido.";
        }

        if (strlen($password) < 6) {
            $errors[] = "A senha deve ter pelo menos 6 caracteres.";
        }

        if ($password !== $confirm_password) {
            $errors[] = "As senhas não coincidem.";
        }

        // Verificar se o nome de usuário já está cadastrado
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn()) {
            $errors[] = "Este nome de usuário já está em uso.";
        }

        // Verificar se o e-mail já está cadastrado
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) {
            $errors[] = "Este e-mail já está cadastrado.";
        }

        if (empty($errors)) {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, is_admin, created_at) VALUES (?, ?, ?, 0, NOW())");
            if ($stmt->execute([$username, $email, $password_hash])) {
                $success = true;
            } else {
                $errors[] = "Erro ao cadastrar. Por favor, tente novamente.";
            }
        }
    }

    renderHeader(); 
    ?>
    <main class="auth-page">
        <div class="auth-container">
            <div class="auth-image">
                <img src="/register.gif" alt="Registro" class="auth-background">
                <div class="auth-overlay"></div>
            </div>
            <div class="auth-form-container">
                <h1>Criar Usuário </h1>
                <p class="auth-subtitle">Junte-se à comunidade ZapLinks </p>
                <?php if ($success): ?>
                    <div class="success-message">
                        <i class="fas fa-check-circle"></i>
                        Conta criada com sucesso!
                        <a href="?page=login" class="btn btn-primary login-button">
                      <i class="fas fa-arrow-right"></i> Ir para o Login
                    </a>   
                                                                             
                    </div>
                <?php else: ?>
                    <?php if (!empty($errors)): ?>
                        <div class="error-messages">
                            <?php foreach ($errors as $error): ?>
                                <p><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form action="?page=register" method="POST" class="auth-form">
                        <div class="form-group">
                            <label for="username">Nome de Usuário</label>
                            <div class="input-icon-wrapper">
                                <i class="fas fa-user"></i>
                                <input type="text" id="username" name="username" required placeholder="Escolha um nome de usuário">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="email">E-mail</label>
                            <div class="input-icon-wrapper">
                                <i class="fas fa-envelope"></i>
                                <input type="email" id="email" name="email" required placeholder="seu@email.com">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="password">Senha</label>
                            <div class="input-icon-wrapper">
                                <i class="fas fa-lock"></i>
                                <input type="password" id="password" name="password" required minlength="6" placeholder="Mínimo 6 caracteres">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirmar Senha</label>
                            <div class="input-icon-wrapper">
                                <i class="fas fa-lock"></i>
                                <input type="password" id="confirm_password" name="confirm_password" required minlength="6" placeholder="Repita sua senha">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-user-plus"></i> Criar Conta
                        </button>
                    </form>
                    <p class="auth-link">Já tem uma conta? <a href="?page=login">Faça login</a></p>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');

    .auth-page {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: #f0f2f5;
        padding: 20px;
        font-family: 'Poppins', sans-serif;
    }

    .auth-container {
        display: flex;
        width: 100%;
        max-width: 1000px;
        background-color: #ffffff;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    }

    .auth-image {
        flex: 1;
        position: relative;
        overflow: hidden;
        min-height: 300px;
    }

    .auth-background {
        width: 100%;
        height: 100%;
        object-fit: cover;
        object-position: center;
        position: absolute;
        top: 0;
        left: 0;
        transition: transform 0.3s ease;
    }

    .auth-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(45deg, rgba(76, 175, 80, 0.6), rgba(33, 150, 243, 0.6));
        z-index: 1;
    }

    .auth-form-container {
        flex: 1;
        padding: 50px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        background-color: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(10px);
        position: relative;
        z-index: 2;
    }

    .auth-form-container h1 {
        margin-bottom: 10px;
        color: var(--primary);
        font-size: 2.5rem;
        font-weight: 700;
        text-align: center;
    }

    .auth-subtitle {
        text-align: center;
        color: var(--muted-foreground);
        margin-bottom: 30px;
        font-size: 1.1rem;
    }

    .auth-form {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .form-group label {
        font-weight: 500;
        color: var(--foreground);
        font-size: 0.9rem;
    }

    .input-icon-wrapper {
        position: relative;
    }

    .input-icon-wrapper i {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--muted-foreground);
    }

    .form-group input {
        padding: 12px 12px 12px 40px;
        border: 1px solid var(--border);
        border-radius: 10px;
        font-size: 1rem;
        background-color: rgba(255, 255, 255, 0.8);
        transition: all 0.3s ease;
    }

    .form-group input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 2px rgba(var(--primary-rgb), 0.2);
    }

    .btn-primary {
        background-color: var(--primary);
        color: white;
        padding: 12px;
        border: none;
        border-radius: 10px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }

    .btn-primary:hover {
        background-color: var(--primary-dark);
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(var(--primary-rgb), 0.3);
    }

    .auth-link {
        margin-top: 20px;
        text-align: center;
        color: var(--muted-foreground);
        font-size: 0.9rem;
    }

    .auth-link a {
        color: var(--primary);
        text-decoration: none;
        font-weight: 600;
        transition: color 0.3s ease;
    }

    .auth-link a:hover {
        color: var(--primary-dark);
    }

    .success-message,
    .error-messages {
        padding: 15px;
        border-radius: 10px;
        margin-bottom: 20px;
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }

    .login-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 10px 20px;
    border-radius: 20px;
    font-weight: 600;
    transition: all 0.3s ease;
    margin-top: 20px;
}

.btn-secondary {
    background-color: var(--secondary);
    color: white;
}

.btn-secondary:hover {
    background-color: var(--secondary-dark);
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(var(--secondary-rgb), 0.3);
}

.auth-link {
    margin-top: 20px;
    text-align: center;
    color: var(--muted-foreground);
    font-size: 0.9rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
}

    .success-message {
        background-color: #d4edda;
        color: #155724;
    }

    .error-messages {
        background-color: #f8d7da;
        color: #721c24;
    }

    .error-messages p {
        margin: 5px 0;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    @media (max-width: 768px) {
        .auth-container {
            flex-direction: column;
        }

        .auth-image {
            height: 200px;
        }

        .auth-form-container {
            padding: 30px 20px;
        }
    }

    @media (max-width: 480px) {
        .auth-image {
            height: 150px;
        }

        .auth-form-container h1 {
            font-size: 2rem;
        }

        .auth-subtitle {
            font-size: 1rem;
        }

        .form-group input {
            padding: 10px 10px 10px 35px;
        }
    }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('.auth-form');
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const authImage = document.querySelector('.auth-background');

        form.addEventListener('submit', function(e) {
            if (passwordInput.value !== confirmPasswordInput.value) {
                e.preventDefault();
                alert('As senhas não coincidem. Por favor, verifique e tente novamente.');
            }
        });

        // Efeito parallax suave na imagem de fundo
        document.addEventListener('mousemove', function(e) {
            const moveX = (e.clientX - window.innerWidth / 2) * 0.01;
            const moveY = (e.clientY - window.innerHeight / 2) * 0.01;
            authImage.style.transform = `translate(${moveX}px, ${moveY}px)`;
        });
    });
    </script>
    <?php
    renderFooter();
}


function renderLogout() {
    session_destroy();
    header('Location: ?page=index');
    exit();
}

function renderEditarGrupo() {
    requireLogin();

    global $pdo;

    $group_id = $_GET['id'] ?? null;

    if (!$group_id) {
        header('Location: ?page=meus-grupos');
        exit();
    }

    $stmt = $pdo->prepare("SELECT * FROM `groups` WHERE `id` = :id AND `usuario_id` = :usuario_id");
    $stmt->execute([':id' => $group_id, ':usuario_id' => $_SESSION['user_id']]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        header('Location: ?page=meus-grupos');
        exit();
    }

    $errors = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nome_grupo = trim($_POST['nome_grupo']);
        $categoria = trim($_POST['categoria']);
        $descricao = trim($_POST['descricao']);
        $grupo_link = trim($_POST['grupo_link']);

        if (empty($nome_grupo)) {
            $errors[] = "O nome do grupo é obrigatório.";
        }

        if (empty($categoria)) {
            $errors[] = "A categoria é obrigatória.";
        }

        if (empty($descricao)) {
            $errors[] = "A descrição é obrigatória.";
        }

        if (empty($grupo_link)) {
            $errors[] = "O link do grupo é obrigatório.";
        } elseif (!filter_var($grupo_link, FILTER_VALIDATE_URL)) {
            $errors[] = "O link do grupo é inválido.";
        }

        if ($_FILES['imagem']['error'] !== UPLOAD_ERR_NO_FILE) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $file_type = finfo_file($finfo, $_FILES['imagem']['tmp_name']);
            finfo_close($finfo);
            if (!in_array($file_type, $allowed_types)) {
                $errors[] = "Tipo de arquivo não permitido. Use apenas JPG, PNG ou GIF.";
            }
        }

        if (empty($errors)) {
            $update_data = [
                ':id' => $group_id,
                ':nome_grupo' => $nome_grupo,
                ':categoria' => $categoria,
                ':descricao' => $descricao,
                ':grupo_link' => $grupo_link
            ];

            $update_sql = "UPDATE `groups` SET `nome_grupo` = :nome_grupo, `categoria` = :categoria, `descrição` = :descricao, `grupo_link` = :grupo_link";

            if ($_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/';
                $filename = uniqid() . '_' . basename($_FILES['imagem']['name']);
                $upload_path = $upload_dir . $filename;

                if (move_uploaded_file($_FILES['imagem']['tmp_name'], $upload_path)) {
                    $update_sql .= ", `imagem` = :imagem";
                    $update_data[':imagem'] = $upload_path;
                } else {
                    $errors[] = "Erro ao salvar a nova imagem.";
                }
            }

            $update_sql .= " WHERE `id` = :id";

            if (empty($errors)) {
                try {
                    $stmt = $pdo->prepare($update_sql);
                    $stmt->execute($update_data);

                    header('Location: ?page=meus-grupos');
                    exit();
                } catch(PDOException $e) {
                    $errors[] = "Erro ao atualizar o grupo: " . $e->getMessage();
                }
            }
        }
    }

    renderHeader();
    ?>

    <main>
        <div class="container">
            <h1>Editar Grupo</h1>
            <?php if (!empty($errors)): ?>
                <div class="error">
                    <?php foreach ($errors as $error): ?>
                        <p><?= htmlspecialchars($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form action="?page=editar-grupo&id=<?= htmlspecialchars($group_id) ?>" method="POST" enctype="multipart/form-data" class="form">
                <div class="form-group">
                    <label for="nome_grupo">Nome do Grupo</label>
                    <input type="text" id="nome_grupo" name="nome_grupo" value="<?= htmlspecialchars($group['nome_grupo']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="categoria">Categoria</label>
                    <select id="categoria" name="categoria" required>
                        <option value="">Selecione uma categoria</option>
                        <?php
                     $categories = [
                        'AMIZADE',
                        'NAMORO',
                        'STATUS',
                        'POLÍTICA',
                        'VAGAS DE EMPREGOS',
                        'ENTRETENIMENTO',
                        'APOSTAS',
                        'JOGOS',
                        'MÚSICA',
                        'VENDAS',
                        'FILMES',
                        'ANIME',
                        'REDE SOCIAL',
                        'SHITPOST',
                        'FIGURINHAS',
                        'OUTROS',
                        'LIVROS',
                        'SAÚDE E BEM-ESTAR',
                        'TECNOLOGIA',
                        'VIAGENS',
                        'CULTURA',
                        'HUMOR',
                        'DESIGN E ARTE',
                        'EDUCAÇÃO',
                        'FOTOGRAFIA',
                        'EVENTOS',
                        'DICAS E TRUQUES',
                        'VOLUNTARIADO',
                    ];
                        foreach ($categories as $cat):
                        ?>
                            <option value="<?= htmlspecialchars($cat) ?>" <?= $group['categoria'] === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="descricao">Descrição</label>
                    <textarea id="descricao" name="descricao" required><?= htmlspecialchars($group['descrição']) ?></textarea>
                </div>
                <div class="form-group">
                    <label for="grupo_link">Link do Grupo</label>
                    <input type="url" id="grupo_link" name="grupo_link" value="<?= htmlspecialchars($group['grupo_link']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="imagem">Nova Imagem do Grupo (opcional)</label>
                    <input type="file" id="imagem" name="imagem" accept="image/*">
                </div>
                <button type="submit" class="btn btn-primary">Atualizar Grupo</button>
            </form>
        </div>
    </main>
    
    <?php
    renderFooter();
}



function renderAdminPanel() {
    requireLogin();
    requireAdmin();

    global $pdo;

    // Handle notification submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_notification'])) {
        $title = $_POST['notification_title'] ?? '';
        $message = $_POST['notification_message'] ?? '';
        
        if (!empty($title) && !empty($message)) {
            // Get all user IDs
            $stmt = $pdo->query("SELECT id FROM users");
            $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Insert notification for each user
            $insertStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, created_at, read_status) 
                                        VALUES (:user_id, :title, :message, NOW(), 0)");
            
            $successCount = 0;
            foreach ($users as $userId) {
                $result = $insertStmt->execute([
                    ':user_id' => $userId,
                    ':title' => $title,
                    ':message' => $message
                ]);
                
                if ($result) {
                    $successCount++;
                }
            }
            
            $notificationMessage = "Notificação enviada com sucesso para {$successCount} usuários.";
            $notificationType = "success";
        } else {
            $notificationMessage = "Por favor, preencha todos os campos da notificação.";
            $notificationType = "error";
        }
    }

    // Get pending groups
    $stmt = $pdo->query("SELECT g.*, u.username as owner_name, u.email as owner_email 
                         FROM `groups` g 
                         JOIN users u ON g.usuario_id = u.id 
                         WHERE g.aprovação = 'pendente' 
                         ORDER BY g.criado DESC");
    $pendingGroups = $stmt->fetchAll();

    // Get statistics
    $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalGroups = $pdo->query("SELECT COUNT(*) FROM `groups`")->fetchColumn();
    $totalProducts = $pdo->query("SELECT COUNT(*) FROM marketplace")->fetchColumn();
    $pendingGroupsCount = $pdo->query("SELECT COUNT(*) FROM `groups` WHERE aprovação = 'pendente'")->fetchColumn();
    $approvedGroupsCount = $pdo->query("SELECT COUNT(*) FROM `groups` WHERE aprovação = 'aprovado'")->fetchColumn();
    $rejectedGroupsCount = $pdo->query("SELECT COUNT(*) FROM `groups` WHERE aprovação = 'rejeitado'")->fetchColumn();

    renderHeader();
    ?>
    <main class="admin-panel">
        <div class="admin-container">
            <div class="admin-header">
                <h1>Painel de Administração</h1>
                <p class="admin-subtitle">Gerencie usuários, grupos e notificações</p>
            </div>
            
            <?php if (isset($notificationMessage)): ?>
            <div class="alert alert-<?= $notificationType ?>">
                <?= $notificationMessage ?>
            </div>
            <?php endif; ?>
            
            <div class="admin-dashboard">
                <div class="admin-stats">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Usuários</h3>
                            <p class="stat-value"><?= $totalUsers ?></p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-layer-group"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Grupos</h3>
                            <p class="stat-value"><?= $totalGroups ?></p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Produtos</h3>
                            <p class="stat-value"><?= $totalProducts ?></p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-content">
                            <h3>Grupos Pendentes</h3>
                            <p class="stat-value"><?= $pendingGroupsCount ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="admin-content">
                    <div class="admin-columns">
                        <!-- Pending Groups Section -->
                        <div class="admin-column">
                            <div class="admin-card pending-groups-card">
                                <div class="card-header">
                                    <h2>Grupos Pendentes</h2>
                                    <span class="badge"><?= $pendingGroupsCount ?></span>
                                </div>
                                
                                <?php if (count($pendingGroups) > 0): ?>
                                <div class="pending-groups-list">
                                    <?php foreach ($pendingGroups as $group): ?>
                                    <div class="pending-group-item">
                                        <div class="group-preview">
                                            <div class="group-image">
                                                <img src="<?= htmlspecialchars($group['imagem']) ?>" alt="<?= htmlspecialchars($group['nome_grupo']) ?>">
                                            </div>
                                            <div class="group-info">
                                                <h3><?= htmlspecialchars($group['nome_grupo']) ?></h3>
                                                <p class="group-category"><?= htmlspecialchars($group['categoria']) ?></p>
                                                <p class="group-owner">
                                                    <i class="fas fa-user"></i> <?= htmlspecialchars($group['owner_name']) ?>
                                                </p>
                                                <p class="group-date">
                                                    <i class="fas fa-calendar-alt"></i> <?= date('d/m/Y H:i', strtotime($group['criado'])) ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="group-actions">
                                            <a href="?page=group&id=<?= $group['id'] ?>" class="details-button">
                                                <i class="fas fa-search"></i> Ver Detalhes
                                            </a>
                                            <div class="approval-buttons">
                                                <a href="?page=aprovar-grupo&id=<?= $group['id'] ?>" class="approve-button">
                                                    <i class="fas fa-check"></i> Aprovar
                                                </a>
                                                <a href="?page=rejeitar-grupo&id=<?= $group['id'] ?>" class="reject-button">
                                                    <i class="fas fa-times"></i> Rejeitar
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <?php if (count($pendingGroups) > 5): ?>
                                <div class="view-all-container">
                                    <a href="?page=pending-groups" class="view-all-button">
                                        Ver Todos os Grupos Pendentes
                                    </a>
                                </div>
                                <?php endif; ?>
                                
                                <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <p>Não há grupos pendentes para aprovação.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="admin-card group-stats-card">
                                <div class="card-header">
                                    <h2>Estatísticas de Grupos</h2>
                                </div>
                                <div class="group-stats">
                                    <div class="stat-item">
                                        <div class="stat-label">Aprovados</div>
                                        <div class="stat-bar">
                                            <div class="stat-fill approved" style="width: <?= ($totalGroups > 0) ? ($approvedGroupsCount / $totalGroups * 100) : 0 ?>%"></div>
                                        </div>
                                        <div class="stat-value"><?= $approvedGroupsCount ?></div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-label">Pendentes</div>
                                        <div class="stat-bar">
                                            <div class="stat-fill pending" style="width: <?= ($totalGroups > 0) ? ($pendingGroupsCount / $totalGroups * 100) : 0 ?>%"></div>
                                        </div>
                                        <div class="stat-value"><?= $pendingGroupsCount ?></div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-label">Rejeitados</div>
                                        <div class="stat-bar">
                                            <div class="stat-fill rejected" style="width: <?= ($totalGroups > 0) ? ($rejectedGroupsCount / $totalGroups * 100) : 0 ?>%"></div>
                                        </div>
                                        <div class="stat-value"><?= $rejectedGroupsCount ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Notification Section -->
                        <div class="admin-column">
                            <div class="admin-card notification-card">
                                <div class="card-header">
                                    <h2>Enviar Notificação para Todos</h2>
                                </div>
                                <form action="?page=admin" method="POST" class="notification-form">
                                    <div class="form-group">
                                        <label for="notification_title">Título da Notificação</label>
                                        <input type="text" id="notification_title" name="notification_title" class="form-control" placeholder="Digite o título da notificação" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="notification_message">Mensagem</label>
                                        <textarea id="notification_message" name="notification_message" class="form-control" rows="5" placeholder="Digite a mensagem da notificação" required></textarea>
                                    </div>
                                    <div class="notification-preview">
                                        <h4>Prévia da Notificação</h4>
                                        <div class="preview-container">
                                            <div class="preview-title" id="preview_title">Título da Notificação</div>
                                            <div class="preview-message" id="preview_message">Mensagem da notificação aparecerá aqui...</div>
                                            <div class="preview-date">Agora mesmo</div>
                                        </div>
                                    </div>
                                    <button type="submit" name="send_notification" class="send-notification-button">
                                        <i class="fas fa-paper-plane"></i> Enviar Notificação
                                    </button>
                                </form>
                            </div>
                            
                            <div class="admin-card quick-actions-card">
                                <div class="card-header">
                                    <h2>Ações Rápidas</h2>
                                </div>
                                <div class="quick-actions">
                                    <a href="?page=users" class="quick-action-button">
                                        <i class="fas fa-users"></i>
                                        <span>Gerenciar Usuários</span>
                                    </a>
                                    <a href="?page=all-groups" class="quick-action-button">
                                        <i class="fas fa-layer-group"></i>
                                        <span>Todos os Grupos</span>
                                    </a>
                                    <a href="?page=marketplace-admin" class="quick-action-button">
                                        <i class="fas fa-shopping-cart"></i>
                                        <span>Marketplace</span>
                                    </a>
                                    <a href="?page=reports" class="quick-action-button">
                                        <i class="fas fa-chart-bar"></i>
                                        <span>Relatórios</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <style>
    .admin-panel {
        background-color: #f8f9fa;
        min-height: 100vh;
        padding: 2rem 0;
    }
    
    .admin-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 1rem;
    }
    
    .admin-header {
        text-align: center;
        margin-bottom: 2rem;
    }
    
    .admin-header h1 {
        font-size: 2.5rem;
        color: var(--primary);
        margin-bottom: 0.5rem;
    }
    
    .admin-subtitle {
        color: #6c757d;
        font-size: 1.1rem;
    }
    
    .alert {
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        text-align: center;
    }
    
    .alert-success {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    
    .alert-error {
        background-color: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    .admin-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.5rem;
        margin-bottom: 2rem;
    }
    
    .stat-card {
        background-color: white;
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        padding: 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    .stat-icon {
        width: 50px;
        height: 50px;
        border-radius: 12px;
        background-color: var(--primary);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
    }
    
    .stat-content h3 {
        margin: 0;
        font-size: 1rem;
        color: #6c757d;
    }
    
    .stat-value {
        font-size: 1.75rem;
        font-weight: bold;
        color: #343a40;
        margin: 0;
    }
    
    .admin-columns {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2rem;
    }
    
    .admin-card {
        background-color: white;
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        overflow: hidden;
        margin-bottom: 2rem;
    }
    
    .card-header {
        background-color: #f8f9fa;
        padding: 1.25rem;
        border-bottom: 1px solid #e9ecef;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .card-header h2 {
        margin: 0;
        font-size: 1.25rem;
        color: #343a40;
    }
    
    .badge {
        background-color: var(--primary);
        color: white;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.9rem;
        font-weight: bold;
    }
    
    .pending-groups-list {
        padding: 1.5rem;
    }
    
    .pending-group-item {
        border: 1px solid #e9ecef;
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    .pending-group-item:last-child {
        margin-bottom: 0;
    }
    
    .group-preview {
        display: flex;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    
    .group-image {
        width: 80px;
        height: 80px;
        border-radius: 8px;
        overflow: hidden;
        flex-shrink: 0;
    }
    
    .group-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .group-info {
        flex: 1;
    }
    
    .group-info h3 {
        margin: 0 0 0.5rem 0;
        font-size: 1.1rem;
        color: #343a40;
    }
    
    .group-category {
        display: inline-block;
        background-color: #e9ecef;
        color: #495057;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.8rem;
        margin-bottom: 0.5rem;
    }
    
    .group-owner, .group-date {
        font-size: 0.85rem;
        color: #6c757d;
        margin: 0.25rem 0;
    }
    
    .group-owner i, .group-date i {
        margin-right: 0.5rem;
    }
    
    .group-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .details-button {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background-color: white;
        color: var(--primary);
        border: 2px solid var(--primary);
        border-radius: 6px;
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    
    .details-button:hover {
        background-color: var(--primary);
        color: white;
    }
    
    .approval-buttons {
        display: flex;
        gap: 0.5rem;
    }
    
    .approve-button, .reject-button {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        border-radius: 6px;
        font-size: 0.9rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    
    .approve-button {
        background-color: #28a745;
        color: white;
    }
    
    .approve-button:hover {
        background-color: #218838;
    }
    
    .reject-button {
        background-color: #dc3545;
        color: white;
    }
    
    .reject-button:hover {
        background-color: #c82333;
    }
    
    .view-all-container {
        text-align: center;
        padding: 1rem 1.5rem 1.5rem;
    }
    
    .view-all-button {
        display: inline-block;
        background-color: #f8f9fa;
        color: #495057;
        padding: 0.75rem 1.5rem;
        border-radius: 6px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    
    .view-all-button:hover {
        background-color: #e9ecef;
    }
    
    .empty-state {
        padding: 3rem 1.5rem;
        text-align: center;
        color: #6c757d;
    }
    
    .empty-state i {
        font-size: 3rem;
        color: #28a745;
        margin-bottom: 1rem;
    }
    
    .empty-state p {
        font-size: 1.1rem;
        margin: 0;
    }
    
    .group-stats {
        padding: 1.5rem;
    }
    
    .stat-item {
        display: flex;
        align-items: center;
        margin-bottom: 1rem;
    }
    
    .stat-item:last-child {
        margin-bottom: 0;
    }
    
    .stat-label {
        width: 100px;
        font-size: 0.9rem;
        color: #495057;
    }
    
    .stat-bar {
        flex: 1;
        height: 8px;
        background-color: #e9ecef;
        border-radius: 4px;
        overflow: hidden;
        margin: 0 1rem;
    }
    
    .stat-fill {
        height: 100%;
        border-radius: 4px;
    }
    
    .stat-fill.approved {
        background-color: #28a745;
    }
    
    .stat-fill.pending {
        background-color: #ffc107;
    }
    
    .stat-fill.rejected {
        background-color: #dc3545;
    }
    
    .notification-form {
        padding: 1.5rem;
    }
    
    .form-group {
        margin-bottom: 1.5rem;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 600;
        color: #495057;
    }
    
    .form-control {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid #ced4da;
        border-radius: 6px;
        font-size: 1rem;
    }
    
    .form-control:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(var(--primary-rgb), 0.25);
    }
    
    textarea.form-control {
        resize: vertical;
    }
    
    .notification-preview {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }
    
    .notification-preview h4 {
        margin: 0 0 1rem 0;
        font-size: 1rem;
        color: #6c757d;
    }
    
    .preview-container {
        background-color: white;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        padding: 1rem;
    }
    
    .preview-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: #343a40;
        margin-bottom: 0.5rem;
    }
    
    .preview-message {
        font-size: 0.9rem;
        color: #6c757d;
        margin-bottom: 0.5rem;
    }
    
    .preview-date {
        font-size: 0.8rem;
        color: #adb5bd;
        text-align: right;
    }
    
    .send-notification-button {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        width: 100%;
        padding: 1rem;
        background-color: var(--primary);
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 1.1rem;
        font-weight: bold;
        cursor: pointer;
        transition: background-color 0.3s ease;
    }
    
    .send-notification-button:hover {
        background-color: var(--primary-hover);
    }
    
    .quick-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        padding: 1.5rem;
    }
    
    .quick-action-button {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        background-color: #f8f9fa;
        color: #495057;
        padding: 1.5rem;
        border-radius: 8px;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    
    .quick-action-button:hover {
        background-color: var(--primary);
        color: white;
        transform: translateY(-2px);
    }
    
    .quick-action-button i {
        font-size: 1.5rem;
    }
    
    .quick-action-button span {
        font-size: 0.9rem;
        font-weight: 600;
        text-align: center;
    }
    
    @media (max-width: 992px) {
        .admin-stats {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .admin-columns {
            grid-template-columns: 1fr;
        }
    }
    
    @media (max-width: 576px) {
        .admin-stats {
            grid-template-columns: 1fr;
        }
        
        .group-actions {
            flex-direction: column;
            gap: 1rem;
        }
        
        .details-button, .approval-buttons {
            width: 100%;
        }
        
        .approval-buttons {
            justify-content: space-between;
        }
        
        .approve-button, .reject-button {
            flex: 1;
            justify-content: center;
        }
    }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Preview notification as user types
        const titleInput = document.getElementById('notification_title');
        const messageInput = document.getElementById('notification_message');
        const previewTitle = document.getElementById('preview_title');
        const previewMessage = document.getElementById('preview_message');
        
        if (titleInput && previewTitle) {
            titleInput.addEventListener('input', function() {
                previewTitle.textContent = this.value || 'Título da Notificação';
            });
        }
        
        if (messageInput && previewMessage) {
            messageInput.addEventListener('input', function() {
                previewMessage.textContent = this.value || 'Mensagem da notificação aparecerá aqui...';
            });
        }
    });
    </script>
    <?php
    renderFooter();
}

// Funções de renderização para as páginas de administração


function renderUserManagement() {
    requireLogin();
    requireAdmin();
    
    global $pdo;
    
    // Obter todos os usuários
    $stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
    $users = $stmt->fetchAll();
    
    renderHeader();
    ?>
    <main class="admin-page">
        <div class="admin-container">
            <div class="admin-header">
                <h1>Gerenciamento de Usuários</h1>
                <a href="?page=admin" class="back-button"><i class="fas fa-arrow-left"></i> Voltar ao Painel</a>
            </div>
            
            <div class="admin-card">
                <div class="card-header">
                    <h2>Usuários Registrados</h2>
                    <span class="badge"><?= count($users) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nome de Usuário</th>
                                <th>Email</th>
                                <th>Data de Registro</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= $user['id'] ?></td>
                                <td><?= htmlspecialchars($user['username']) ?></td>
                                <td><?= htmlspecialchars($user['email']) ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($user['created_at'])) ?></td>
                                <td>
                                    <span class="status-badge <?= $user['is_admin'] ? 'admin' : 'user' ?>">
                                        <?= $user['is_admin'] ? 'Admin' : 'Usuário' ?>
                                    </span>
                                </td>
                                <td class="actions">
                                    <a href="?page=edit-user&id=<?= $user['id'] ?>" class="action-button edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <a href="?page=delete-user&id=<?= $user['id'] ?>" class="action-button delete" onclick="return confirm('Tem certeza que deseja excluir este usuário?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
    
    <style>
    .admin-page {
        background-color: #f8f9fa;
        min-height: 100vh;
        padding: 2rem 0;
    }
    
    .admin-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 1rem;
    }
    
    .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
    }
    
    .admin-header h1 {
        font-size: 2rem;
        color: var(--primary);
        margin: 0;
    }
    
    .back-button {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background-color: #f8f9fa;
        color: #495057;
        padding: 0.5rem 1rem;
        border-radius: 6px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    
    .back-button:hover {
        background-color: #e9ecef;
    }
    
    .admin-card {
        background-color: white;
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        overflow: hidden;
        margin-bottom: 2rem;
    }
    
    .table-responsive {
        overflow-x: auto;
    }
    
    .admin-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .admin-table th, .admin-table td {
        padding: 1rem;
        text-align: left;
        border-bottom: 1px solid #e9ecef;
    }
    
    .admin-table th {
        background-color: #f8f9fa;
        font-weight: 600;
        color: #495057;
    }
    
    .admin-table tbody tr:hover {
        background-color: #f8f9fa;
    }
    
    .status-badge {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: bold;
    }
    
    .status-badge.admin {
        background-color: #dc3545;
        color: white;
    }
    
    .status-badge.user {
        background-color: #28a745;
        color: white;
    }
    
    .actions {
        display: flex;
        gap: 0.5rem;
    }
    
    .action-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 4px;
        color: white;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    
    .action-button.edit {
        background-color: #17a2b8;
    }
    
    .action-button.edit:hover {
        background-color: #138496;
    }
    
    .action-button.delete {
        background-color: #dc3545;
    }
    
    .action-button.delete:hover {
        background-color: #c82333;
    }
    </style>
    <?php
    renderFooter();
}

function renderAllGroups() {
    requireLogin();
    requireAdmin();
    
    global $pdo;
    
    // Obter todos os grupos
    $stmt = $pdo->query("SELECT g.*, u.username as owner_name 
                         FROM `groups` g 
                         JOIN users u ON g.usuario_id = u.id 
                         ORDER BY g.criado DESC");
    $groups = $stmt->fetchAll();
    
    renderHeader();
    ?>
    <main class="admin-page">
        <div class="admin-container">
            <div class="admin-header">
                <h1>Todos os Grupos</h1>
                <a href="?page=admin" class="back-button"><i class="fas fa-arrow-left"></i> Voltar ao Painel</a>
            </div>
            
            <div class="admin-card">
                <div class="card-header">
                    <h2>Grupos Registrados</h2>
                    <span class="badge"><?= count($groups) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Imagem</th>
                                <th>Nome</th>
                                <th>Categoria</th>
                                <th>Proprietário</th>
                                <th>Status</th>
                                <th>Visualizações</th>
                                <th>Data</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groups as $group): ?>
                            <tr>
                                <td><?= $group['id'] ?></td>
                                <td>
                                    <div class="group-thumbnail">
                                        <img src="<?= htmlspecialchars($group['imagem']) ?>" alt="<?= htmlspecialchars($group['nome_grupo']) ?>">
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($group['nome_grupo']) ?></td>
                                <td><?= htmlspecialchars($group['categoria']) ?></td>
                                <td><?= htmlspecialchars($group['owner_name']) ?></td>
                                <td>
                                    <span class="status-badge <?= $group['aprovação'] ?>">
                                        <?= ucfirst($group['aprovação']) ?>
                                    </span>
                                </td>
                                <td><?= number_format($group['view_count']) ?></td>
                                <td><?= date('d/m/Y', strtotime($group['criado'])) ?></td>
                                <td class="actions">
                                    <a href="?page=group&id=<?= $group['id'] ?>" class="action-button view">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($group['aprovação'] == 'pendente'): ?>
                                    <a href="?page=aprovar-grupo&id=<?= $group['id'] ?>" class="action-button approve">
                                        <i class="fas fa-check"></i>
                                    </a>
                                    <a href="?page=rejeitar-grupo&id=<?= $group['id'] ?>" class="action-button reject">
                                        <i class="fas fa-times"></i>
                                    </a>
                                    <?php endif; ?>
                                    <a href="?page=delete-group&id=<?= $group['id'] ?>" class="action-button delete" onclick="return confirm('Tem certeza que deseja excluir este grupo?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
    
    <style>
    .admin-page {
        background-color: #f8f9fa;
        min-height: 100vh;
        padding: 2rem 0;
    }
    
    .admin-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 1rem;
    }
    
    .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
    }
    
    .admin-header h1 {
        font-size: 2rem;
        color: var(--primary);
        margin: 0;
    }
    
    .back-button {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background-color: #f8f9fa;
        color: #495057;
        padding: 0.5rem 1rem;
        border-radius: 6px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    
    .back-button:hover {
        background-color: #e9ecef;
    }
    
    .admin-card {
        background-color: white;
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        overflow: hidden;
        margin-bottom: 2rem;
    }
    
    .table-responsive {
        overflow-x: auto;
    }
    
    .admin-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .admin-table th, .admin-table td {
        padding: 1rem;
        text-align: left;
        border-bottom: 1px solid #e9ecef;
    }
    
    .admin-table th {
        background-color: #f8f9fa;
        font-weight: 600;
        color: #495057;
    }
    
    .admin-table tbody tr:hover {
        background-color: #f8f9fa;
    }
    
    .group-thumbnail {
        width: 50px;
        height: 50px;
        border-radius: 4px;
        overflow: hidden;
    }
    
    .group-thumbnail img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .status-badge {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: bold;
    }
    
    .status-badge.aprovado {
        background-color: #28a745;
        color: white;
    }
    
    .status-badge.pendente {
        background-color: #ffc107;
        color: #212529;
    }
    
    .status-badge.rejeitado {
        background-color: #dc3545;
        color: white;
    }
    
    .actions {
        display: flex;
        gap: 0.5rem;
    }
    
    .action-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 4px;
        color: white;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    
    .action-button.view {
        background-color: var(--primary);
    }
    
    .action-button.view:hover {
        background-color: var(--primary-hover);
    }
    
    .action-button.approve {
        background-color: #28a745;
    }
    
    .action-button.approve:hover {
        background-color: #218838;
    }
    
    .action-button.reject {
        background-color: #dc3545;
    }
    
    .action-button.reject:hover {
        background-color: #c82333;
    }
    
    .action-button.delete {
        background-color: #dc3545;
    }
    
    .action-button.delete:hover {
        background-color: #c82333;
    }
    </style>
    <?php
    renderFooter();
}

function renderMarketplaceAdmin() {
    requireLogin();
    requireAdmin();
    
    global $pdo;
    
    // Obter todos os produtos
    $stmt = $pdo->query("SELECT m.*, u.username as seller_name 
                         FROM marketplace m 
                         JOIN users u ON m.seller_id = u.id 
                         ORDER BY m.created_at DESC");
    $products = $stmt->fetchAll();
    
    renderHeader();
    ?>
    <main class="admin-page">
        <div class="admin-container">
            <div class="admin-header">
                <h1>Administração do Marketplace</h1>
                <a href="?page=admin" class="back-button"><i class="fas fa-arrow-left"></i> Voltar ao Painel</a>
            </div>
            
            <div class="admin-card">
                <div class="card-header">
                    <h2>Produtos Cadastrados</h2>
                    <span class="badge"><?= count($products) ?></span>
                </div>
                
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Imagem</th>
                                <th>Nome</th>
                                <th>Preço</th>
                                <th>Vendedor</th>
                                <th>Status</th>
                                <th>Data</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                            <tr>
                                <td><?= $product['id'] ?></td>
                                <td>
                                    <div class="product-thumbnail">
                                        <img src="<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?>">
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($product['name']) ?></td>
                                <td>R$ <?= number_format($product['price'], 2, ',', '.') ?></td>
                                <td><?= htmlspecialchars($product['seller_name']) ?></td>
                                <td>
                                    <span class="status-badge <?= $product['status'] ?>">
                                        <?= ucfirst($product['status']) ?>
                                    </span>
                                </td>
                                <td><?= date('d/m/Y', strtotime($product['created_at'])) ?></td>
                                <td class="actions">
                                    <a href="?page=produto&id=<?= $product['id'] ?>" class="action-button view">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($product['status'] == 'pendente'): ?>
                                    <a href="?page=aprovar-produto&id=<?= $product['id'] ?>" class="action-button approve">
                                        <i class="fas fa-check"></i>
                                    </a>
                                    <a href="?page=rejeitar-produto&id=<?= $product['id'] ?>" class="action-button reject">
                                        <i class="fas fa-times"></i>
                                    </a>
                                    <?php endif; ?>
                                    <a href="?page=delete-produto&id=<?= $product['id'] ?>" class="action-button delete" onclick="return confirm('Tem certeza que deseja excluir este produto?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
    
    <style>
    .admin-page {
        background-color: #f8f9fa;
        min-height: 100vh;
        padding: 2rem 0;
    }
    
    .admin-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 1rem;
    }
    
    .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
    }
    
    .admin-header h1 {
        font-size: 2rem;
        color: var(--primary);
        margin: 0;
    }
    
    .back-button {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background-color: #f8f9fa;
        color: #495057;
        padding: 0.5rem 1rem;
        border-radius: 6px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    
    .back-button:hover {
        background-color: #e9ecef;
    }
    
    .admin-card {
        background-color: white;
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        overflow: hidden;
        margin-bottom: 2rem;
    }
    
    .table-responsive {
        overflow-x: auto;
    }
    
    .admin-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .admin-table th, .admin-table td {
        padding: 1rem;
        text-align: left;
        border-bottom: 1px solid #e9ecef;
    }
    
    .admin-table th {
        background-color: #f8f9fa;
        font-weight: 600;
        color: #495057;
    }
    
    .admin-table tbody tr:hover {
        background-color: #f8f9fa;
    }
    
    .product-thumbnail {
        width: 50px;
        height: 50px;
        border-radius: 4px;
        overflow: hidden;
    }
    
    .product-thumbnail img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .status-badge {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: bold;
    }
    
    .status-badge.ativo {
        background-color: #28a745;
        color: white;
    }
    
    .status-badge.pendente {
        background-color: #ffc107;
        color: #212529;
    }
    
    .status-badge.rejeitado {
        background-color: #dc3545;
        color: white;
    }
    
    .actions {
        display: flex;
        gap: 0.5rem;
    }
    
    .action-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 4px;
        color: white;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    
    .action-button.view {
        background-color: var(--primary);
    }
    
    .action-button.view:hover {
        background-color: var(--primary-hover);
    }
    
    .action-button.approve {
        background-color: #28a745;
    }
    
    .action-button.approve:hover {
        background-color: #218838;
    }
    
    .action-button.reject {
        background-color: #dc3545;
    }
    
    .action-button.reject:hover {
        background-color: #c82333;
    }
    
    .action-button.delete {
        background-color: #dc3545;
    }
    
    .action-button.delete:hover {
        background-color: #c82333;
    }
    </style>
    <?php
    renderFooter();
}

function renderReports() {
    requireLogin();
    requireAdmin();
    
    global $pdo;
    
    // Obter estatísticas
    $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalGroups = $pdo->query("SELECT COUNT(*) FROM `groups`")->fetchColumn();
    $totalProducts = $pdo->query("SELECT COUNT(*) FROM marketplace")->fetchColumn();
    $totalViews = $pdo->query("SELECT SUM(view_count) FROM `groups`")->fetchColumn();
    
    // Obter grupos mais populares
    $popularGroupsStmt = $pdo->query("SELECT g.*, u.username as owner_name 
                                     FROM `groups` g 
                                     JOIN users u ON g.usuario_id = u.id 
                                     WHERE g.aprovação = 'aprovado' 
                                     ORDER BY g.view_count DESC 
                                     LIMIT 5");
    $popularGroups = $popularGroupsStmt->fetchAll();
    
    // Obter usuários mais ativos
    $activeUsersStmt = $pdo->query("SELECT u.id, u.username, u.email, COUNT(g.id) as group_count 
                                   FROM users u 
                                   LEFT JOIN `groups` g ON u.id = g.usuario_id 
                                   GROUP BY u.id 
                                   ORDER BY group_count DESC 
                                   LIMIT 5");
    $activeUsers = $activeUsersStmt->fetchAll();
    
    renderHeader();
    ?>
    <main class="admin-page">
        <div class="admin-container">
            <div class="admin-header">
                <h1>Relatórios e Estatísticas</h1>
                <a href="?page=admin" class="back-button"><i class="fas fa-arrow-left"></i> Voltar ao Painel</a>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Total de Usuários</h3>
                        <p class="stat-value"><?= number_format($totalUsers) ?></p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-layer-group"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Total de Grupos</h3>
                        <p class="stat-value"><?= number_format($totalGroups) ?></p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Total de Produtos</h3>
                        <p class="stat-value"><?= number_format($totalProducts) ?></p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-eye"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Total de Visualizações</h3>
                        <p class="stat-value"><?= number_format($totalViews) ?></p>
                    </div>
                </div>
            </div>
            
            <div class="reports-grid">
                <div class="admin-card">
                    <div class="card-header">
                        <h2>Grupos Mais Populares</h2>
                    </div>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Proprietário</th>
                                    <th>Categoria</th>
                                    <th>Visualizações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($popularGroups as $group): ?>
                                <tr>
                                    <td>
                                        <div class="group-info">
                                            <div class="group-thumbnail">
                                                <img src="<?= htmlspecialchars($group['imagem']) ?>" alt="<?= htmlspecialchars($group['nome_grupo']) ?>">
                                            </div>
                                            <span><?= htmlspecialchars($group['nome_grupo']) ?></span>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($group['owner_name']) ?></td>
                                    <td><?= htmlspecialchars($group['categoria']) ?></td>
                                    <td><?= number_format($group['view_count']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="admin-card">
                    <div class="card-header">
                        <h2>Usuários Mais Ativos</h2>
                    </div>
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Usuário</th>
                                    <th>Email</th>
                                    <th>Grupos Criados</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activeUsers as $user): ?>
                                <tr>
                                    <td><?= htmlspecialchars($user['username']) ?></td>
                                    <td><?= htmlspecialchars($user['email']) ?></td>
                                    <td><?= number_format($user['group_count']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <style>
    .admin-page {
        background-color: #f8f9fa;
        min-height: 100vh;
        padding: 2rem 0;
    }
    
    .admin-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 1rem;
    }
    
    .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
    }
    
    .admin-header h1 {
        font-size: 2rem;
        color: var(--primary);
        margin: 0;
    }
    
    .back-button {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background-color: #f8f9fa;
        color: #495057;
        padding: 0.5rem 1rem;
        border-radius: 6px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    
    .back-button:hover {
        background-color: #e9ecef;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.5rem;
        margin-bottom: 2rem;
    }
    
    .stat-card {
        background-color: white;
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        padding: 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    .stat-icon {
        width: 50px;
        height: 50px;
        border-radius: 12px;
        background-color: var(--primary);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
    }
    
    .stat-content h3 {
        margin: 0;
        font-size: 1rem;
        color: #6c757d;
    }
    
    .stat-value {
        font-size: 1.75rem;
        font-weight: bold;
        color: #343a40;
        margin: 0;
    }
    
    .reports-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2rem;
    }
    
    .admin-card {
        background-color: white;
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        overflow: hidden;
        margin-bottom: 2rem;
    }
    
    .card-header {
        background-color: #f8f9fa;
        padding: 1.25rem;
        border-bottom: 1px solid #e9ecef;
    }
    
    .card-header h2 {
        margin: 0;
        font-size: 1.25rem;
        color: #343a40;
    }
    
    .table-responsive {
        overflow-x: auto;
    }
    
    .admin-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .admin-table th, .admin-table td {
        padding: 1rem;
        text-align: left;
        border-bottom: 1px solid #e9ecef;
    }
    
    .admin-table th {
        background-color: #f8f9fa;
        font-weight: 600;
        color: #495057;
    }
    
    .admin-table tbody tr:hover {
        background-color: #f8f9fa;
    }
    
    .group-info {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    
    .group-thumbnail {
        width: 40px;
        height: 40px;
        border-radius: 4px;
        overflow: hidden;
    }
    
    .group-thumbnail img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    @media (max-width: 992px) {
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .reports-grid {
            grid-template-columns: 1fr;
        }
    }
    
    @media (max-width: 576px) {
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .admin-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 1rem;
        }
    }
    </style>
    <?php
    renderFooter();
}

function renderPendingGroups() {
    requireLogin();
    requireAdmin();
    
    global $pdo;
    
    // Obter grupos pendentes
    $stmt = $pdo->query("SELECT g.*, u.username as owner_name, u.email as owner_email 
                         FROM `groups` g 
                         JOIN users u ON g.usuario_id = u.id 
                         WHERE g.aprovação = 'pendente' 
                         ORDER BY g.criado DESC");
    $pendingGroups = $stmt->fetchAll();
    
    renderHeader();
    ?>
    <main class="admin-page">
        <div class="admin-container">
            <div class="admin-header">
                <h1>Grupos Pendentes de Aprovação</h1>
                <a href="?page=admin" class="back-button"><i class="fas fa-arrow-left"></i> Voltar ao Painel</a>
            </div>
            
            <div class="admin-card">
                <div class="card-header">
                    <h2>Grupos Aguardando Aprovação</h2>
                    <span class="badge"><?= count($pendingGroups) ?></span>
                </div>
                
                <?php if (count($pendingGroups) > 0): ?>
                <div class="pending-groups-list">
                    <?php foreach ($pendingGroups as $group): ?>
                    <div class="pending-group-item">
                        <div class="group-preview">
                            <div class="group-image">
                                <img src="<?= htmlspecialchars($group['imagem']) ?>" alt="<?= htmlspecialchars($group['nome_grupo']) ?>">
                            </div>
                            <div class="group-info">
                                <h3><?= htmlspecialchars($group['nome_grupo']) ?></h3>
                                <p class="group-category"><?= htmlspecialchars($group['categoria']) ?></p>
                                <p class="group-description"><?= htmlspecialchars(substr($group['descrição'], 0, 150)) ?>...</p>
                                <div class="group-meta">
                                    <p class="group-owner">
                                        <i class="fas fa-user"></i> <?= htmlspecialchars($group['owner_name']) ?>
                                    </p>
                                    <p class="group-email">
                                        <i class="fas fa-envelope"></i> <?= htmlspecialchars($group['owner_email']) ?>
                                    </p>
                                    <p class="group-date">
                                        <i class="fas fa-calendar-alt"></i> <?= date('d/m/Y H:i', strtotime($group['criado'])) ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="group-actions">
                            <a href="?page=group&id=<?= $group['id'] ?>" class="details-button">
                                <i class="fas fa-search"></i> Ver Detalhes
                            </a>
                            <div class="approval-buttons">
                                <a href="?page=aprovar-grupo&id=<?= $group['id'] ?>" class="approve-button">
                                    <i class="fas fa-check"></i> Aprovar
                                </a>
                                <a href="?page=rejeitar-grupo&id=<?= $group['id'] ?>" class="reject-button">
                                    <i class="fas fa-times"></i> Rejeitar
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <p>Não há grupos pendentes para aprovação.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    
    <style>
    .admin-page {
        background-color: #f8f9fa;
        min-height: 100vh;
        padding: 2rem 0;
    }
    
    .admin-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 1rem;
    }
    
    .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
    }
    
    .admin-header h1 {
        font-size: 2rem;
        color: var(--primary);
        margin: 0;
    }
    
    .back-button {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background-color: #f8f9fa;
        color: #495057;
        padding: 0.5rem 1rem;
        border-radius: 6px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    
    .back-button:hover {
        background-color: #e9ecef;
    }
    
    .admin-card {
        background-color: white;
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        overflow: hidden;
        margin-bottom: 2rem;
    }
    
    .card-header {
        background-color: #f8f9fa;
        padding: 1.25rem;
        border-bottom: 1px solid #e9ecef;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .card-header h2 {
        margin: 0;
        font-size: 1.25rem;
        color: #343a40;
    }
    
    .badge {
        background-color: var(--primary);
        color: white;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.9rem;
        font-weight: bold;
    }
    
    .pending-groups-list {
        padding: 1.5rem;
    }
    
    .pending-group-item {
        border: 1px solid #e9ecef;
        border-radius: 8px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }
    
    .pending-group-item:last-child {
        margin-bottom: 0;
    }
    
    .group-preview {
        display: flex;
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }
    
    .group-image {
        width: 120px;
        height: 120px;
        border-radius: 8px;
        overflow: hidden;
        flex-shrink: 0;
    }
    
    .group-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .group-info {
        flex: 1;
    }
    
    .group-info h3 {
        margin: 0 0 0.5rem 0;
        font-size: 1.3rem;
        color: #343a40;
    }
    
    .group-category {
        display: inline-block;
        background-color: #e9ecef;
        color: #495057;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.8rem;
        margin-bottom: 0.75rem;
    }
    
    .group-description {
        color: #6c757d;
        font-size: 0.95rem;
        line-height: 1.5;
        margin-bottom: 1rem;
    }
    
    .group-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .group-owner, .group-email, .group-date {
        font-size: 0.85rem;
        color: #6c757d;
        margin: 0;
    }
    
    .group-owner i, .group-email i, .group-date i {
        margin-right: 0.5rem;
    }
    
    .group-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .details-button {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background-color: white;
        color: var(--primary);
        border: 2px solid var(--primary);
        border-radius: 6px;
        padding: 0.75rem 1.25rem;
        font-size: 0.95rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    
    .details-button:hover {
        background-color: var(--primary);
        color: white;
    }
    
    .approval-buttons {
        display: flex;
        gap: 0.75rem;
    }
    
    .approve-button, .reject-button {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem 1.25rem;
        border-radius: 6px;
        font-size: 0.95rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.3s ease;
    }
    
    .approve-button {
        background-color: #28a745;
        color: white;
    }
    
    .approve-button:hover {
        background-color: #218838;
    }
    
    .reject-button {
        background-color: #dc3545;
        color: white;
    }
    
    .reject-button:hover {
        background-color: #c82333;
    }
    
    .empty-state {
        padding: 4rem 1.5rem;
        text-align: center;
        color: #6c757d;
    }
    
    .empty-state i {
        font-size: 3.5rem;
        color: #28a745;
        margin-bottom: 1.5rem;
    }
    
    .empty-state p {
        font-size: 1.2rem;
        margin: 0;
    }
    
    @media (max-width: 768px) {
        .group-preview {
            flex-direction: column;
        }
        
        .group-image {
            width: 100%;
            height: 200px;
        }
        
        .group-actions {
            flex-direction: column;
            gap: 1rem;
        }
        
        .details-button, .approval-buttons {
            width: 100%;
        }
        
        .approval-buttons {
            justify-content: space-between;
        }
        
        .approve-button, .reject-button {
            flex: 1;
            justify-content: center;
        }
    }
    </style>
    <?php
    renderFooter();
}





function addNotification($user_id, $message) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message) VALUES (:user_id, :message)");
    if ($stmt->execute([':user_id' => $user_id, ':message' => $message])) {
        error_log("Notificação adicionada para o usuário $user_id: $message");
    } else {
        error_log("Erro ao adicionar notificação para o usuário $user_id");
    }
}

function renderAprovarGrupo() {
    requireAdmin();

    global $pdo;

    $group_id = $_GET['id'] ?? null;

    if (!$group_id) {
        header('Location: ?page=admin');
        exit();
    }

    // Get the user_id of the group owner
    $stmt = $pdo->prepare("SELECT usuario_id, nome_grupo FROM `groups` WHERE id = :id");
    $stmt->execute([':id' => $group_id]);
    $group = $stmt->fetch();
    
    if (!$group) {
        header('Location: ?page=admin');
        exit();
    }
    
    $user_id = $group['usuario_id'];
    $group_name = $group['nome_grupo'];

    // Update the group status to approved
    $stmt = $pdo->prepare("UPDATE `groups` SET aprovação = 1 WHERE id = :id");
    $stmt->execute([':id' => $group_id]);

    // Add notification for the group owner
    addNotification($user_id, "Seu grupo \"" . htmlspecialchars($group_name) . "\" foi aprovado e está agora visível no site.");

    // Redirect back to admin panel
    header('Location: ?page=admin');
    exit();
}



function renderRejeitarGrupo() {
    requireAdmin();

    global $pdo;

    $group_id = $_GET['id'] ?? null;

    if (!$group_id) {
        header('Location: ?page=admin');
        exit();
    }

    $stmt = $pdo->prepare("SELECT usuario_id FROM `groups` WHERE id = :id");
    $stmt->execute([':id' => $group_id]);
    $user_id = $stmt->fetchColumn();

    $stmt = $pdo->prepare("DELETE FROM `groups` WHERE id = :id");
    $stmt->execute([':id' => $group_id]);

    // Adiciona a notificação
    addNotification($user_id, "Seu grupo foi rejeitado e removido do site.");

    header('Location: ?page=admin');
    exit();
}

function renderAprovarProduto() {
    requireAdmin();

    global $pdo;

    $product_id = $_GET['id'] ?? null;

    if (!$product_id) {
        header('Location: ?page=admin');
        exit();
    }

    $stmt = $pdo->prepare("UPDATE marketplace SET aprovado = 1 WHERE id = :id");
    $stmt->execute([':id' => $product_id]);

    $stmt = $pdo->prepare("SELECT usuario_id FROM marketplace WHERE id = :id");
    $stmt->execute([':id' => $product_id]);
    $user_id = $stmt->fetchColumn();

    addNotification($user_id, "Seu produto foi aprovado e está agora visível no marketplace.");

    header('Location: ?page=admin');
    exit();
}

function renderRejeitarProduto() {
    requireAdmin();

    global $pdo;

    $product_id = $_GET['id'] ?? null;

    if (!$product_id) {
        header('Location: ?page=admin');
        exit();
    }

    $stmt = $pdo->prepare("SELECT usuario_id FROM marketplace WHERE id = :id");
    $stmt->execute([':id' => $product_id]);
    $user_id = $stmt->fetchColumn();

    $stmt = $pdo->prepare("DELETE FROM marketplace WHERE id = :id");
    $stmt->execute([':id' => $product_id]);

    addNotification($user_id, "Seu produto foi rejeitado e removido do marketplace.");

    header('Location: ?page=admin');
    exit();
}



function renderCheckout() {
    requireLogin();

    global $pdo;

    $group_id = $_GET['group_id'] ?? null;
    if (!$group_id) {
        header('Location: ?page=meus-grupos');
        exit();
    }

    $stmt = $pdo->prepare("SELECT * FROM `groups` WHERE id = :id AND usuario_id = :user_id");
    $stmt->execute([':id' => $group_id, ':user_id' => $_SESSION['user_id']]);
    $group = $stmt->fetch();

    if (!$group) {
        header('Location: ?page=meus-grupos');
        exit();
    }

    // Default to 1 day if no duration is selected
    $duration = isset($_POST['duration']) ? intval($_POST['duration']) : 1;
    
    // Set prices based on duration
    $prices = [
        1 => "5.00",
        7 => "25.00",
        30 => "80.00"
    ];
    
    // Get the price based on selected duration
    $pixAmount = $prices[$duration] ?? "5.00";
    $pixKey = "atendimento@zaplinks.com.br";
    $paymentId = uniqid('ZL');

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
        // Salvar informações do pagamento pendente no banco de dados
        $stmt = $pdo->prepare("INSERT INTO payments (payment_id, user_id, group_id, amount, duration, status, created_at) 
                              VALUES (:payment_id, :user_id, :group_id, :amount, :duration, 'pending', NOW())");
        $stmt->execute([
            ':payment_id' => $paymentId,
            ':user_id' => $_SESSION['user_id'],
            ':group_id' => $group_id,
            ':amount' => $pixAmount,
            ':duration' => $duration
        ]);

        // Redirecionar para a página de confirmação
        header('Location: ?page=payment_confirmation&payment_id=' . $paymentId);
        exit();
    }

    renderHeader();
    ?>
    <main class="checkout-page">
        <div class="checkout-container">
            <div class="checkout-header">
                <h1>Impulsionar Grupo</h1>
                <div class="checkout-steps">
                    <div class="step active">
                        <div class="step-number">1</div>
                        <div class="step-label">Detalhes</div>
                    </div>
                    <div class="step-connector"></div>
                    <div class="step">
                        <div class="step-number">2</div>
                        <div class="step-label">Pagamento</div>
                    </div>
                    <div class="step-connector"></div>
                    <div class="step">
                        <div class="step-number">3</div>
                        <div class="step-label">Confirmação</div>
                    </div>
                </div>
            </div>
            
            <div class="checkout-content">
                <div class="checkout-columns">
                    <div class="checkout-column">
                        <div class="checkout-card group-preview">
                            <div class="card-header">
                                <h2>Grupo a ser impulsionado</h2>
                            </div>
                            <div class="group-details">
                                <div class="group-image">
                                    <img src="<?= htmlspecialchars($group['imagem']) ?>" alt="<?= htmlspecialchars($group['nome_grupo']) ?>">
                                    <div class="boost-badge">
                                        <i class="fas fa-rocket"></i> Destaque
                                    </div>
                                </div>
                                <div class="group-info">
                                    <h3><?= htmlspecialchars($group['nome_grupo']) ?></h3>
                                    <p class="group-category"><?= htmlspecialchars($group['categoria']) ?></p>
                                    <p class="group-description"><?= htmlspecialchars(substr($group['descrição'], 0, 100)) ?>...</p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Duration Selection Card -->
                        <div class="checkout-card duration-card">
                            <div class="card-header">
                                <h2>Escolha a Duração do Impulso</h2>
                            </div>
                            <form id="durationForm" method="POST" action="?page=checkout&group_id=<?= $group_id ?>">
                                <div class="duration-options">
                                    <div class="duration-option <?= $duration == 1 ? 'selected' : '' ?>">
                                        <input type="radio" id="duration-1" name="duration" value="1" <?= $duration == 1 ? 'checked' : '' ?>>
                                        <label for="duration-1">
                                            <div class="duration-header">
                                                <span class="duration-days">1 dia</span>
                                                <span class="duration-price">R$ 5,00</span>
                                            </div>
                                            <div class="duration-description">
                                                Ideal para promoções rápidas
                                            </div>
                                        </label>
                                    </div>
                                    
                                    <div class="duration-option <?= $duration == 7 ? 'selected' : '' ?>">
                                        <input type="radio" id="duration-7" name="duration" value="7" <?= $duration == 7 ? 'checked' : '' ?>>
                                        <label for="duration-7">
                                            <div class="duration-header">
                                                <span class="duration-days">7 dias</span>
                                                <span class="duration-price">R$ 25,00</span>
                                                <span class="duration-badge">Popular</span>
                                            </div>
                                            <div class="duration-description">
                                                Melhor custo-benefício
                                            </div>
                                        </label>
                                    </div>
                                    
                                    <div class="duration-option <?= $duration == 30 ? 'selected' : '' ?>">
                                        <input type="radio" id="duration-30" name="duration" value="30" <?= $duration == 30 ? 'checked' : '' ?>>
                                        <label for="duration-30">
                                            <div class="duration-header">
                                                <span class="duration-days">30 dias</span>
                                                <span class="duration-price">R$ 80,00</span>
                                                <span class="duration-badge">Economia de 47%</span>
                                            </div>
                                            <div class="duration-description">
                                                Máxima exposição e resultados
                                            </div>
                                        </label>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-update-duration">Atualizar Duração</button>
                            </form>
                        </div>
                        
                        <div class="checkout-card benefits-card">
                            <div class="card-header">
                                <h2>Benefícios do Impulso</h2>
                            </div>
                            <ul class="benefits-list">
                                <li>
                                    <i class="fas fa-star"></i>
                                    <div>
                                        <h4>Destaque na página inicial</h4>
                                        <p>Seu grupo aparecerá no topo da página inicial por <?= $duration ?> <?= $duration == 1 ? 'dia' : 'dias' ?></p>
                                    </div>
                                </li>
                                <li>
                                    <i class="fas fa-eye"></i>
                                    <div>
                                        <h4>Maior visibilidade</h4>
                                        <p>Aumento de até 300% nas visualizações do seu grupo</p>
                                    </div>
                                </li>
                                <li>
                                    <i class="fas fa-search"></i>
                                    <div>
                                        <h4>Prioridade nas buscas</h4>
                                        <p>Seu grupo aparecerá primeiro nos resultados de busca</p>
                                    </div>
                                </li>
                                <li>
                                    <i class="fas fa-certificate"></i>
                                    <div>
                                        <h4>Badge exclusivo</h4>
                                        <p>Seu grupo receberá um badge "Grupo em Destaque"</p>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="checkout-column">
                        <div class="checkout-card payment-card">
                            <div class="card-header">
                                <h2>Detalhes do Pagamento</h2>
                            </div>
                            <div class="payment-details">
                                <div class="payment-amount">
                                    <span class="amount-label">Valor:</span>
                                    <span class="amount-value">R$ <?= $pixAmount ?></span>
                                </div>
                                <div class="payment-method">
                                    <div class="method-header">
                                        <img src="https://logospng.org/download/pix/logo-pix-512.png" alt="PIX" class="pix-logo">
                                        <h3>Pagamento via PIX</h3>
                                    </div>
                                    <div class="pix-instructions">
                                        <div class="pix-key-container">
                                            <p class="pix-label">Chave PIX:</p>
                                            <div class="pix-key">
                                                <span id="pixKey"><?= $pixKey ?></span>
                                                <button class="copy-button" onclick="copyToClipboard('pixKey')">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="pix-value-container">
                                            <p class="pix-label">Valor exato:</p>
                                            <div class="pix-value">
                                                <span id="pixValue">R$ <?= $pixAmount ?></span>
                                                <button class="copy-button" onclick="copyToClipboard('pixValue')">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="pix-id-container">
                                            <p class="pix-label">ID do Pagamento:</p>
                                            <div class="pix-id">
                                                <span id="pixId"><?= $paymentId ?></span>
                                                <button class="copy-button" onclick="copyToClipboard('pixId')">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="payment-steps">
                                        <ol>
                                            <li>Abra o app do seu banco</li>
                                            <li>Selecione a opção de pagamento via PIX</li>
                                            <li>Use a chave PIX acima</li>
                                            <li>Digite o valor exato: <strong>R$ <?= $pixAmount ?></strong></li>
                                            <li>Na descrição, inclua o ID: <strong><?= $paymentId ?></strong></li>
                                            <li>Confirme o pagamento no seu aplicativo bancário</li>
                                        </ol>
                                    </div>
                                </div>
                            </div>
                            <form action="?page=checkout&group_id=<?= $group_id ?>" method="POST" class="payment-form">
                                <input type="hidden" name="payment_id" value="<?= $paymentId ?>">
                                <input type="hidden" name="duration" value="<?= $duration ?>">
                                <button type="submit" name="confirm_payment" class="confirm-button">
                                    <i class="fas fa-check-circle"></i> Confirmar Pagamento
                                </button>
                            </form>
                        </div>
                        
                        <div class="checkout-card support-card">
                            <div class="card-header">
                                <h2>Precisa de ajuda?</h2>
                            </div>
                            <p class="support-text">Após confirmar o pagamento, sua solicitação ficará em análise. Para agilizar o processo, entre em contato pelo WhatsApp:</p>
                            <div class="support-actions">
                                <a href="https://wa.me/5592999652961?text=Olá! Acabei de fazer um pagamento PIX para impulsionar meu grupo por <?= $duration ?> <?= $duration == 1 ? 'dia' : 'dias' ?>. ID do pagamento: <?= $paymentId ?>" class="whatsapp-button" target="_blank">
                                    <div class="whatsapp-icon">
                                        <i class="fab fa-whatsapp"></i>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <style>
    .checkout-page {
        background-color: #f8f9fa;
        min-height: 100vh;
        padding: 2rem 0;
    }
    
    .checkout-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 1rem;
    }
    
    .checkout-header {
        text-align: center;
        margin-bottom: 2rem;
    }
    
    .checkout-header h1 {
        font-size: 2.5rem;
        color: var(--primary);
        margin-bottom: 1.5rem;
    }
    
    .checkout-steps {
        display: flex;
        justify-content: center;
        align-items: center;
        margin: 2rem 0;
    }
    
    .step {
        display: flex;
        flex-direction: column;
        align-items: center;
        position: relative;
    }
    
    .step-number {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background-color: #e9ecef;
        color: #6c757d;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        margin-bottom: 0.5rem;
        transition: all 0.3s ease;
    }
    
    .step.active .step-number {
        background-color: var(--primary);
        color: white;
    }
    
    .step-label {
        font-size: 0.9rem;
        color: #6c757d;
    }
    
    .step.active .step-label {
        color: var(--primary);
        font-weight: bold;
    }
    
    .step-connector {
        width: 80px;
        height: 2px;
        background-color: #e9ecef;
        margin: 0 10px;
    }
    
    .checkout-content {
        margin-top: 2rem;
    }
    
    .checkout-columns {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2rem;
    }
    
    .checkout-card {
        background-color: white;
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        overflow: hidden;
        margin-bottom: 2rem;
    }
    
    .card-header {
        background-color: #f8f9fa;
        padding: 1.25rem;
        border-bottom: 1px solid #e9ecef;
    }
    
    .card-header h2 {
        margin: 0;
        font-size: 1.25rem;
        color: #343a40;
    }
    
    .group-details {
        padding: 1.5rem;
    }
    
    .group-image {
        position: relative;
        margin-bottom: 1rem;
    }
    
    .group-image img {
        width: 100%;
        height: 200px;
        object-fit: cover;
        border-radius: 8px;
    }
    
    .boost-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        background-color: var(--primary);
        color: white;
        padding: 0.5rem 0.75rem;
        border-radius: 20px;
        font-size: 0.9rem;
        font-weight: bold;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    
    .group-info h3 {
        margin: 0 0 0.5rem 0;
        font-size: 1.5rem;
        color: #343a40;
    }
    
    .group-category {
        display: inline-block;
        background-color: #e9ecef;
        color: #495057;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.8rem;
        margin-bottom: 0.75rem;
    }
    
    .group-description {
        color: #6c757d;
        font-size: 0.9rem;
        line-height: 1.5;
    }
    
    /* Duration Selection Styles */
    .duration-card {
        padding-bottom: 1.5rem;
    }
    
    .duration-options {
        padding: 1.5rem;
        display: grid;
        gap: 1rem;
    }
    
    .duration-option {
        position: relative;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        overflow: hidden;
        transition: all 0.3s ease;
    }
    
    .duration-option:hover {
        border-color: var(--primary);
        transform: translateY(-2px);
    }
    
    .duration-option.selected {
        border-color: var(--primary);
        background-color: rgba(37, 211, 102, 0.05);
    }
    
    .duration-option input {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
    }
    
    .duration-option label {
        display: block;
        padding: 1.25rem;
        cursor: pointer;
    }
    
    .duration-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
    }
    
    .duration-days {
        font-size: 1.2rem;
        font-weight: bold;
        color: #343a40;
    }
    
    .duration-price {
        font-size: 1.2rem;
        font-weight: bold;
        color: var(--primary);
    }
    
    .duration-badge {
        background-color: #ffc107;
        color: #212529;
        padding: 0.25rem 0.5rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: bold;
        margin-left: 0.5rem;
    }
    
    .duration-description {
        color: #6c757d;
        font-size: 0.9rem;
    }
    
    .btn-update-duration {
        display: block;
        width: calc(100% - 3rem);
        margin: 0 auto;
        padding: 0.75rem;
        background-color: #f8f9fa;
        color: #495057;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        font-size: 1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .btn-update-duration:hover {
        background-color: #e9ecef;
    }
    
    .benefits-list {
        list-style: none;
        padding: 1.5rem;
        margin: 0;
    }
    
    .benefits-list li {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
        padding: 1rem;
        border-bottom: 1px solid #e9ecef;
    }
    
    .benefits-list li:last-child {
        border-bottom: none;
    }
    
    .benefits-list li i {
        font-size: 1.5rem;
        color: var(--primary);
        margin-top: 0.25rem;
    }
    
    .benefits-list li h4 {
        margin: 0 0 0.5rem 0;
        font-size: 1.1rem;
        color: #343a40;
    }
    
    .benefits-list li p {
        margin: 0;
        color: #6c757d;
        font-size: 0.9rem;
    }
    
    .payment-details {
        padding: 1.5rem;
    }
    
    .payment-amount {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem;
        background-color: #f8f9fa;
        border-radius: 8px;
        margin-bottom: 1.5rem;
    }
    
    .amount-label {
        font-size: 1.1rem;
        color: #343a40;
    }
    
    .amount-value {
        font-size: 1.5rem;
        font-weight: bold;
        color: var(--primary);
    }
    
    .payment-method {
        margin-bottom: 1.5rem;
    }
    
    .method-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    
    .pix-logo {
        width: 40px;
        height: 40px;
        object-fit: contain;
    }
    
    .method-header h3 {
        margin: 0;
        font-size: 1.2rem;
        color: #343a40;
    }
    
    .pix-instructions {
        background-color: #f8f9fa;
        padding: 1.25rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
    }
    
    .pix-label {
        font-size: 0.9rem;
        color: #6c757d;
        margin-bottom: 0.25rem;
    }
    
    .pix-key, .pix-value, .pix-id {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background-color: white;
        padding: 0.75rem 1rem;
        border-radius: 4px;
        border: 1px solid #e9ecef;
        margin-bottom: 1rem;
    }
    
    .pix-key span, .pix-value span, .pix-id span {
        font-family: monospace;
        font-size: 1rem;
        color: #343a40;
    }
    
    .copy-button {
        background: none;
        border: none;
        color: var(--primary);
        cursor: pointer;
        font-size: 1rem;
        transition: color 0.3s ease;
    }
    
    .copy-button:hover {
        color: var(--primary-hover);
    }
    
    .payment-steps {
        margin-bottom: 1.5rem;
    }
    
    .payment-steps ol {
        padding-left: 1.5rem;
        margin: 0;
    }
    
    .payment-steps li {
        margin-bottom: 0.5rem;
        color: #495057;
    }
    
    .payment-form {
        padding: 0 1.5rem 1.5rem;
    }
    
    .confirm-button {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        width: 100%;
        padding: 1rem;
        background-color: var(--primary);
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 1.1rem;
        font-weight: bold;
        cursor: pointer;
        transition: background-color 0.3s ease;
    }
    
    .confirm-button:hover {
        background-color: var(--primary-hover);
    }
    
    .support-card {
        text-align: center;
    }
    
    .support-text {
        padding: 1.5rem;
        margin: 0;
        color: #495057;
    }
    
    .support-actions {
        display: flex;
        justify-content: center;
        padding: 0 1.5rem 1.5rem;
    }

    .whatsapp-button {
        display: flex;
        align-items: center;
        background-color: white;
        color: #075E54;
        border: 2px solid #25D366;
        border-radius: 50px;
        padding: 0;
        font-size: 1rem;
        font-weight: 600;
        text-decoration: none;
        overflow: hidden;
        box-shadow: 0 4px 12px rgba(37, 211, 102, 0.2);
        transition: all 0.3s ease;
    }

    .whatsapp-button:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(37, 211, 102, 0.3);
        border-color: #128C7E;
    }

    .whatsapp-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: #25D366;
        color: white;
        width: 48px;
        height: 48px;
        font-size: 1.5rem;
    }

    .whatsapp-button span {
        padding: 0 1.5rem;
    }
    
    @media (max-width: 992px) {
        .checkout-columns {
            grid-template-columns: 1fr;
        }
    }
    
    @media (max-width: 768px) {
        .checkout-steps {
            flex-direction: column;
            gap: 1rem;
        }
        
        .step-connector {
            width: 2px;
            height: 20px;
        }
    }
    </style>

    <script>
    function copyToClipboard(elementId) {
        const element = document.getElementById(elementId);
        const text = element.textContent;
        
        navigator.clipboard.writeText(text).then(() => {
            // Show success feedback
            const originalText = element.parentElement.querySelector('.copy-button').innerHTML;
            element.parentElement.querySelector('.copy-button').innerHTML = '<i class="fas fa-check"></i>';
            
            setTimeout(() => {
                element.parentElement.querySelector('.copy-button').innerHTML = originalText;
            }, 2000);
        }).catch(err => {
            console.error('Failed to copy text: ', err);
        });
    }
    
    // Duration selection handling
    document.addEventListener('DOMContentLoaded', function() {
        const durationOptions = document.querySelectorAll('.duration-option input');
        
        durationOptions.forEach(option => {
            option.addEventListener('change', function() {
                // Remove selected class from all options
                document.querySelectorAll('.duration-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                
                // Add selected class to the chosen option
                this.closest('.duration-option').classList.add('selected');
            });
        });
    });
    </script>
    <?php
    renderFooter();
}


function renderMeusGrupos() {
    requireLogin();

    global $pdo;

    // Buscar o grupo mais recente do usuário
    $stmt = $pdo->prepare("SELECT * FROM `groups` WHERE usuario_id = :usuario_id ORDER BY criado DESC LIMIT 1");
    $stmt->execute([':usuario_id' => $_SESSION['user_id']]);
    $latestGroup = $stmt->fetch();

    // Buscar os demais grupos do usuário
    $stmt = $pdo->prepare("SELECT * FROM `groups` WHERE usuario_id = :usuario_id AND id != :latest_id ORDER BY criado DESC");
    $stmt->execute([
        ':usuario_id' => $_SESSION['user_id'],
        ':latest_id' => $latestGroup ? $latestGroup['id'] : 0
    ]);
    $groups = $stmt->fetchAll();

    // Buscar o nome do usuário
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = :user_id");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    // Calcular métricas de alcance
    $baseReach = max($latestGroup['view_count'] ?? 0, 1000); 
    $boostMultiplier = 3.5; 
    $boostedReach = $baseReach * $boostMultiplier;
    $engagementRate = 15; 
    $potentialMembers = floor($boostedReach * ($engagementRate / 100));

    

    renderHeader();
    ?>
    <main class="meus-grupos-page">
        <div class="container">
            <div class="page-header">
                <h1>Meus Grupos</h1>
                <a href="?page=enviar-grupo" class="btn btn-create">
                    <i class="fas fa-plus"></i>
                    Novo Grupo
                </a>
            </div>
            
            <?php if ($latestGroup): ?>
            <div class="featured-group-section">
                <div class="section-header">
                    <h2><i class="fas fa-star"></i> Destaque seu Grupo</h2>
                    <span class="badge-new">Recente</span>
                </div>

                <div class="metrics-banner">
                    <div class="metric-card">
                        <div class="metric-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="metric-content">
                            <h3>Alcance Normal</h3>
                            <p><?= number_format($baseReach) ?></p>
                            <span class="metric-label">visualizações</span>
                        </div>
                    </div>
                    <div class="metric-card boost-potential">
                        <div class="metric-icon">
                            <i class="fas fa-rocket"></i>
                        </div>
                        <div class="metric-content">
                            <h3>Com Impulso</h3>
                            <p><?= number_format($boostedReach) ?></p>
                            <span class="metric-label">alcance estimado</span>
                            <span class="boost-badge">+<?= ($boostMultiplier - 1) * 100 ?>%</span>
                        </div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="metric-content">
                            <h3>Potencial</h3>
                            <p><?= number_format($potentialMembers) ?></p>
                            <span class="metric-label">membros estimados</span>
                        </div>
                    </div>
                </div>

                <div class="featured-group-card">
                    <div class="card-media">
                        <img src="<?= htmlspecialchars($latestGroup['imagem']) ?>" 
                             alt="<?= htmlspecialchars($latestGroup['nome_grupo']) ?>">
                        <div class="card-overlay"></div>
                        <?php if ($latestGroup['aprovação']): ?>
                            <span class="status-badge approved">
                                <i class="fas fa-check-circle"></i> Verificado
                            </span>
                        <?php else: ?>
                            <span class="status-badge pending">
                                <i class="fas fa-clock"></i> Em Análise
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="card-details">
                        <div class="card-header">
                            <h3><?= htmlspecialchars($latestGroup['nome_grupo']) ?></h3>
                            <span class="category-tag"><?= htmlspecialchars($latestGroup['categoria']) ?></span>
                        </div>
                        <p class="description"><?= htmlspecialchars(substr($latestGroup['descrição'], 0, 150)) ?>...</p>
                        <div class="card-stats">
                            <div class="stat">
                                <i class="fas fa-eye"></i>
                                <span><?= number_format($latestGroup['view_count'] ?? 0) ?></span>
                                <small>visualizações</small>
                            </div>
                            <div class="stat">
                                <i class="fas fa-calendar-alt"></i>
                                <span><?= date('d/m/Y', strtotime($latestGroup['criado'])) ?></span>
                                <small>criado em</small>
                            </div>
                        </div>
                        <div class="card-actions">
                            <a href="?page=checkout&group_id=<?= $latestGroup['id'] ?>" class="btn btn-boost">
                                <i class="fas fa-rocket"></i>
                                <span>Impulsionar por R$ 5,00</span>
                            </a>
                            <div class="action-buttons">
                                <a href="?page=editar-grupo&id=<?= $latestGroup['id'] ?>" class="btn btn-icon" title="Editar">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="<?= htmlspecialchars($latestGroup['grupo_link']) ?>" class="btn btn-icon" title="Link do Grupo" target="_blank">
                                    <i class="fas fa-link"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="groups-section">
                <div class="section-header">
                    <h2><i class="fas fa-layer-group"></i> Outros Grupos</h2>
                    <div class="view-options">
                        <button class="view-btn active" data-view="grid">
                            <i class="fas fa-th-large"></i>
                        </button>
                        <button class="view-btn" data-view="list">
                            <i class="fas fa-list"></i>
                        </button>
                    </div>
                </div>

                <div class="groups-grid">
                    <?php foreach ($groups as $group): ?>
                    <div class="group-card">
                        <div class="card-media">
                            <img src="<?= htmlspecialchars($group['imagem']) ?>" 
                                 alt="<?= htmlspecialchars($group['nome_grupo']) ?>">
                            <div class="card-overlay"></div>
                            <?php if ($group['aprovação']): ?>
                                <span class="status-badge approved">
                                    <i class="fas fa-check-circle"></i>
                                </span>
                            <?php else: ?>
                                <span class="status-badge pending">
                                    <i class="fas fa-clock"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="card-content">
                            <div class="card-header">
                                <h3><?= htmlspecialchars($group['nome_grupo']) ?></h3>
                                <span class="category-tag"><?= htmlspecialchars($group['categoria']) ?></span>
                            </div>
                            <p class="description"><?= htmlspecialchars(substr($group['descrição'], 0, 100)) ?>...</p>
                            <div class="card-stats">
                                <div class="stat">
                                    <i class="fas fa-eye"></i>
                                    <span><?= number_format($group['view_count'] ?? 0) ?></span>
                                </div>
                                <div class="stat">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span><?= date('d/m/Y', strtotime($group['criado'])) ?></span>
                                </div>
                            </div>
                            <div class="card-actions">
                                <div class="action-buttons">
                                    <a href="?page=editar-grupo&id=<?= $group['id'] ?>" class="btn btn-icon" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="?page=checkout&group_id=<?= $group['id'] ?>" class="btn btn-icon btn-boost-small" title="Impulsionar">
                                        <i class="fas fa-rocket"></i>
                                    </a>
                                    <a href="<?= htmlspecialchars($group['grupo_link']) ?>" class="btn btn-icon" title="Link do Grupo" target="_blank">
                                        <i class="fas fa-link"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>

    <style>
    .meus-grupos-page {
        padding: 2rem 0;
        background: #f8f9fa;
    }

    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 1rem;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
    }

    .btn-create {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem 1.5rem;
        background: #007bff;
        color: white;
        border-radius: 0.5rem;
        text-decoration: none;
        font-weight: 500;
        transition: background-color 0.2s;
    }

    .btn-create:hover {
        background: #0056b3;
    }

    .featured-group-section {
        background: white;
        border-radius: 1rem;
        padding: 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
    }

    .badge-new {
        background: #28a745;
        color: white;
        padding: 0.25rem 0.75rem;
        border-radius: 1rem;
        font-size: 0.875rem;
    }

    .metrics-banner {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .metric-card {
        background: #f8f9fa;
        padding: 1rem;
        border-radius: 0.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .boost-potential {
        background: #fff3cd;
    }

    .metric-icon {
        width: 3rem;
        height: 3rem;
        background: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #007bff;
    }

    .boost-potential .metric-icon {
        color: #ff6b6b;
    }

    .metric-content h3 {
        font-size: 0.875rem;
        color: #666;
        margin: 0;
    }

    .metric-content p {
        font-size: 1.5rem;
        font-weight: 600;
        margin: 0.25rem 0;
    }

    .metric-label {
        font-size: 0.75rem;
        color: #666;
    }

    .boost-badge {
        display: inline-block;
        padding: 0.125rem 0.5rem;
        background: #ff6b6b;
        color: white;
        border-radius: 1rem;
        font-size: 0.75rem;
        margin-left: 0.5rem;
    }

    .featured-group-card {
        background: white;
        border-radius: 0.5rem;
        overflow: hidden;
        display: grid;
        grid-template-columns: 300px 1fr;
        gap: 1.5rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .featured-group-card .card-media {
        position: relative;
        height: 100%;
        min-height: 300px;
    }

    .featured-group-card .card-media img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .featured-group-card .card-details {
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
    }

    .status-badge {
        position: absolute;
        top: 1rem;
        right: 1rem;
        padding: 0.5rem 1rem;
        border-radius: 2rem;
        font-size: 0.875rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        z-index: 1;
    }

    .status-badge.approved {
        background: rgba(40, 167, 69, 0.9);
        color: white;
    }

    .status-badge.pending {
        background: rgba(255, 193, 7, 0.9);
        color: white;
    }

    .groups-section {
        margin-top: 2rem;
    }

    .view-options {
        display: flex;
        gap: 0.5rem;
    }

    .view-btn {
        background: none;
        border: 1px solid #ddd;
        padding: 0.5rem;
        border-radius: 0.375rem;
        cursor: pointer;
        color: #666;
        transition: all 0.2s ease;
    }

    .view-btn.active {
        background: #007bff;
        color: white;
        border-color: #007bff;
    }

    .groups-grid {
        display: grid;
        gap: 1.5rem;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    }

    .group-card {
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        display: flex;
        flex-direction: column;
        height: 100%;
    }

    .group-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }

    .card-media {
        position: relative;
        padding-top: 56.25%; /* 16:9 Aspect Ratio */
        background: #f0f0f0;
    }

    .card-media img {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .card-overlay {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 50%;
        background: linear-gradient(to top, rgba(0,0,0,0.4), transparent);
    }

    .card-content {
        padding: 1rem;
        display: flex;
        flex-direction: column;
        flex-grow: 1;
    }

    .card-header {
        margin-bottom: 0.75rem;
    }

    .card-header h3 {
        font-size: 1.125rem;
        font-weight: 600;
        color: #333;
        margin: 0 0 0.5rem 0;
        line-height: 1.4;
    }

    .category-tag {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        background: #e9ecef;
        color: #666;
        border-radius: 1rem;
        font-size: 0.875rem;
    }

    .description {
        color: #666;
        font-size: 0.875rem;
        margin-bottom: 1rem;
        line-height: 1.5;
    }

    .card-stats {
        display: flex;
        gap: 1rem;
        margin-bottom: 1rem;
        padding-top: 0.5rem;
        border-top: 1px solid #eee;
    }

    .stat {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: #666;
        font-size: 0.875rem;
    }

    .stat i {
        color: #007bff;
    }

    .card-actions {
        margin-top: auto;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .btn-boost {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem 1.5rem;
        background: #00a884;
        color: white;
        border-radius: 0.5rem;
        text-decoration: none;
        font-weight: 500;
        transition: background-color 0.2s;
    }

    .btn-boost:hover {
        background: #008f6f;
    }

    .btn-boost-small {
        padding: 0.5rem;
        background: #00a884;
        color: white;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        transition: background-color 0.2s;
    }

    .btn-boost-small:hover {
        background: #008f6f;
    }

    .action-buttons {
        display: flex;
        gap: 0.5rem;
    }

    .btn-icon {
        padding: 0.5rem;
        border-radius: 0.375rem;
        border: 1px solid #ddd;
        color: #666;
        background: white;
        cursor: pointer;
        transition: all 0.2s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .btn-icon:hover {
        background: #f8f9fa;
        color: #007bff;
        border-color: #007bff;
    }

    @media (max-width: 992px) {
        .featured-group-card {
            grid-template-columns: 1fr;
        }

        .featured-group-card .card-media {
            min-height: 200px;
        }
    }

    @media (max-width: 768px) {
        .groups-grid {
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
        }

        .metrics-banner {
            grid-template-columns: 1fr;
        }

        .card-content {
            padding: 0.875rem;
        }

        .card-header h3 {
            font-size: 1rem;
        }

        .description {
            font-size: 0.813rem;
        }
    }

    @media (max-width: 480px) {
        .page-header {
            flex-direction: column;
            gap: 1rem;
            align-items: stretch;
        }

        .btn-create {
            text-align: center;
            justify-content: center;
        }

        .featured-group-card .card-details {
            padding: 1rem;
        }
    }
    </style>

<script>
function toggleSupportInfo() {
    const supportCard = document.getElementById('supportCard');
    supportCard.classList.toggle('active');
}

// Fechar o card quando clicar fora dele
document.addEventListener('click', function(event) {
    const supportCard = document.getElementById('supportCard');
    const supportButton = document.querySelector('.support-button');
    
    if (!supportCard.contains(event.target) && !supportButton.contains(event.target)) {
        supportCard.classList.remove('active');
    }
});
</script>


    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const viewButtons = document.querySelectorAll('.view-btn');
        const groupsGrid = document.querySelector('.groups-grid');

        viewButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                viewButtons.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                
                const view = btn.dataset.view;
                groupsGrid.className = view === 'grid' ? 'groups-grid' : 'groups-list';
            });
        });
    });

    function impulsionarGrupo(groupId, groupName, userName) {
        const message = `Olá! Me chamo ${userName} e gostaria de impulsionar meu grupo "${groupName}" por 24 horas no valor de R$ 5,00`;
        const whatsappLink = `https://wa.me/5592999652961?text=${encodeURIComponent(message)}`;
        window.open(whatsappLink, '_blank');
    }
    </script>
    <?php
    renderFooter();
}

function tempoDecorrido($data) {
    $agora = new DateTime();
    $impulsionado = new DateTime($data);
    $diferenca = $agora->diff($impulsionado);
    
    if ($diferenca->y > 0) {
        return $diferenca->y . " ano" . ($diferenca->y > 1 ? "s" : "") . " atrás";
    } elseif ($diferenca->m > 0) {
        return $diferenca->m . " mês" . ($diferenca->m > 1 ? "es" : "") . " atrás";
    } elseif ($diferenca->d > 0) {
        return $diferenca->d . " dia" . ($diferenca->d > 1 ? "s" : "") . " atrás";
    } elseif ($diferenca->h > 0) {
        return $diferenca->h . " hora" . ($diferenca->h > 1 ? "s" : "") . " atrás";
    } elseif ($diferenca->i > 0) {
        return $diferenca->i . " minuto" . ($diferenca->i > 1 ? "s" : "") . " atrás";
    } else {
        return "agora mesmo";
    }
}
function renderExcluirGrupo() {
    requireLogin();

    global $pdo;

    $group_id = $_GET['id'] ?? null;

    if (!$group_id) {
        header('Location: ?page=meus-grupos');
        exit();
    }

    $stmt = $pdo->prepare("SELECT * FROM `groups` WHERE id = :id AND usuario_id = :usuario_id");
    $stmt->execute([':id' => $group_id, ':usuario_id' => $_SESSION['user_id']]);
    $group = $stmt->fetch();

    if (!$group) {
        header('Location: ?page=meus-grupos');
        exit();
    }

    $stmt = $pdo->prepare("DELETE FROM `groups` WHERE id = :id");
    $stmt->execute([':id' => $group_id]);

    header('Location: ?page=meus-grupos');
    exit();
}

function renderPerfil() {
    requireLogin();

    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    // Fetch user's groups
    $stmt = $pdo->prepare("SELECT * FROM `groups` WHERE usuario_id = :user_id ORDER BY criado DESC");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $groups = $stmt->fetchAll();

    // Fetch user's products
    $stmt = $pdo->prepare("SELECT * FROM marketplace WHERE usuario_id = :user_id ORDER BY criado DESC");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $products = $stmt->fetchAll();

    $error = '';
    $success = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = $_POST['username'];
        $email = $_POST['email'];
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_new_password = $_POST['confirm_new_password'];

        if (password_verify($current_password, $user['password'])) {
            $update_data = [
                ':id' => $_SESSION['user_id'],
                ':username' => $username,
                ':email' => $email
            ];

            $update_sql = "UPDATE users SET username = :username, email = :email";

            if (!empty($new_password)) {
                if ($new_password === $confirm_new_password) {
                    $update_sql .= ", password = :password";
                    $update_data[':password'] = password_hash($new_password, PASSWORD_DEFAULT);
                } else {
                    $error = "As novas senhas não coincidem.";
                }
            }

            $update_sql .= " WHERE id = :id";

            if (empty($error)) {
                $stmt = $pdo->prepare($update_sql);
                $stmt->execute($update_data);
                $success = "Perfil atualizado com sucesso.";
                $user['username'] = $username;
                $user['email'] = $email;
            }
        } else {
            $error = "Senha atual incorreta.";
        }
    }

    renderHeader();
    ?>
    <main>
        <div class="container">
            <h1>Meu Perfil</h1>
            <?php if ($error): ?>
                <div class="error"><?= $error ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success"><?= $success ?></div>
            <?php endif; ?>
            
            <!-- Profile Update Form -->
            <form action="?page=perfil" method="POST" class="form">
                <div class="form-group">
                    <label for="username">Nome de Usuário</label>
                    <input type="text" id="username" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="current_password">Senha Atual</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>
                <div class="form-group">
                    <label for="new_password">Nova Senha (deixe em branco para não alterar)</label>
                    <input type="password" id="new_password" name="new_password">
                </div>
                <div class="form-group">
                    <label for="confirm_new_password">Confirmar Nova Senha</label>
                    <input type="password" id="confirm_new_password" name="confirm_new_password">
                </div>
                <button type="submit" class="btn btn-primary">Atualizar Perfil</button>
            </form>

            <!-- User Statistics -->
            <div class="user-stats">
                <h2>Suas Estatísticas</h2>
                <div class="stats-cards">
                    <div class="stat-card">
                        <h3>Total de Grupos</h3>
                        <p><?= count($groups) ?></p>
                    </div>
                    <div class="stat-card">
                        <h3>Total de Produtos</h3>
                        <p><?= count($products) ?></p>
                    </div>
                    <div class="stat-card">
                        <h3>Visualizações Totais</h3>
                        <p><?= array_sum(array_column($groups, 'view_count')) + array_sum(array_column($products, 'view_count')) ?></p>
                    </div>
                </div>
            </div>

            <!-- User's Groups -->
            <div class="user-content">
                <h2>Seus Grupos</h2>
                <?php if (empty($groups)): ?>
                    <p>Você ainda não cadastrou nenhum grupo.</p>
                <?php else: ?>
                    <div class="groups-grid">
                        <?php foreach ($groups as $group): ?>
                            <div class="group-card">
                                <div class="card-header">
                                    <img src="<?= htmlspecialchars($group['imagem']) ?>" alt="<?= htmlspecialchars($group['nome_grupo']) ?>">
                                    <?php if ($group['aprovação']): ?>
                                        <span class="status-badge approved">Aprovado</span>
                                    <?php else: ?>
                                        <span class="status-badge pending">Pendente</span>
                                    <?php endif; ?>
                                </div>
                                <div class="card-content">
                                    <h3><?= htmlspecialchars($group['nome_grupo']) ?></h3>
                                    <p class="description"><?= htmlspecialchars(substr($group['descrição'], 0, 100)) ?>...</p>
                                    <div class="card-stats">
                                        <div class="stat">
                                            <i class="fas fa-eye"></i>
                                            <span><?= number_format($group['view_count'] ?? 0) ?> visualizações</span>
                                        </div>
                                        <div class="stat">
                                            <i class="fas fa-calendar"></i>
                                            <span><?= date('d/m/Y', strtotime($group['criado'])) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- User's Products -->
                <h2>Seus Produtos</h2>
                <?php if (empty($products)): ?>
                    <p>Você ainda não cadastrou nenhum produto.</p>
                <?php else: ?>
                    <div class="products-grid">
                        <?php foreach ($products as $product): ?>
                            <div class="product-card">
                                <div class="card-header">
                                    <img src="<?= htmlspecialchars($product['imagem']) ?>" alt="<?= htmlspecialchars($product['nome_produto']) ?>">
                                    <?php if ($product['aprovado']): ?>
                                        <span class="status-badge approved">Aprovado</span>
                                    <?php else: ?>
                                        <span class="status-badge pending">Pendente</span>
                                    <?php endif; ?>
                                </div>
                                <div class="card-content">
                                    <h3><?= htmlspecialchars($product['nome_produto']) ?></h3>
                                    <p class="price">R$ <?= number_format($product['preco'], 2, ',', '.') ?></p>
                                    <div class="card-stats">
                                        <div class="stat">
                                            <i class="fas fa-eye"></i>
                                            <span><?= number_format($product['view_count'] ?? 0) ?> visualizações</span>
                                        </div>
                                        <div class="stat">
                                            <i class="fas fa-calendar"></i>
                                            <span><?= date('d/m/Y', strtotime($product['criado'])) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <style>
    .user-stats {
        margin: 2rem 0;
        padding: 1rem;
        background: var(--card-bg);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
    }

    .stats-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-top: 1rem;
    }

    .stat-card {
        background: linear-gradient(135deg, #f6f8fa 0%, #ffffff 100%);
        padding: 1.5rem;
        border-radius: var(--radius);
        text-align: center;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .stat-card h3 {
        color: var(--muted-foreground);
        font-size: 0.9rem;
        margin-bottom: 0.5rem;
    }

    .stat-card p {
        color: var(--foreground);
        font-size: 1.5rem;
        font-weight: bold;
    }

    .user-content {
        margin-top: 2rem;
    }

    .groups-grid,
    .products-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 1.5rem;
        margin: 1rem 0 2rem 0;
    }

    .group-card,
    .product-card {
        background: var(--card-bg);
        border-radius: var(--radius);
        overflow: hidden;
        box-shadow: var(--shadow);
        transition: transform 0.2s ease;
    }

    .group-card:hover,
    .product-card:hover {
        transform: translateY(-2px);
    }

    .card-header {
        position: relative;
    }

    .card-header img {
        width: 100%;
        height: 160px;
        object-fit: cover;
    }

    .status-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 500;
    }

    .status-badge.approved {
        background-color: #10B981;
        color: white;
    }

    .status-badge.pending {
        background-color: #F59E0B;
        color: white;
    }

    .card-content {
        padding: 1rem;
    }

    .card-content h3 {
        margin: 0 0 0.5rem 0;
        font-size: 1.1rem;
    }

    .description {
        color: var(--muted-foreground);
        font-size: 0.9rem;
        margin-bottom: 1rem;
    }

    .price {
        color: var(--primary);
        font-weight: bold;
        font-size: 1.2rem;
        margin-bottom: 1rem;
    }

    .card-stats {
        display: flex;
        justify-content: space-between;
        padding-top: 0.5rem;
        border-top: 1px solid var(--border);
    }

    .stat {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--muted-foreground);
        font-size: 0.9rem;
    }

    .stat i {
        color: var(--primary);
    }

    @media (max-width: 768px) {
        .stats-cards {
            grid-template-columns: 1fr;
        }

        .groups-grid,
        .products-grid {
            grid-template-columns: 1fr;
        }
    }
    </style>
    <?php
    renderFooter();
}

function renderVendorRegister() {
    requireLogin();

    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM vendors WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $existing_vendor = $stmt->fetch();

    if ($existing_vendor) {
        header('Location: ?page=vendor-profile');
        exit();
    }

    $error = '';
    $success = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nome = $_POST['nome'];
        $email = $_POST['email'];
        $telefone = $_POST['telefone'];
        $dados_pix = $_POST['dados_pix'];

        if (empty($nome) || empty($email) || empty($telefone) || empty($dados_pix)) {
            $error = "Todos os campos são obrigatórios.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO vendors (user_id, nome, email, telefone, dados_pix) VALUES (:user_id, :nome, :email, :telefone, :dados_pix)");
            $result = $stmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':nome' => $nome,
                ':email' => $email,
                ':telefone' => $telefone,
                ':dados_pix' => $dados_pix
            ]);

            if ($result) {
                $success = "Registro de vendedor concluído com sucesso!";
            } else {
                $error = "Ocorreu um erro ao registrar. Por favor, tente novamente.";
            }
        }
    }

    renderHeader();
    ?>
    <main>
        <div class="container">
            <h1>Registro de Vendedor</h1>
            <?php if ($error): ?>
                <div class="error"><?= $error ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success"><?= $success ?></div>
            <?php else: ?>
                <form action="?page=vendor-register" method="POST" class="form">
                    <div class="form-group">
                        <label for="nome">Nome Completo</label>
                        <input type="text" id="nome" name="nome" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email de Contato</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="telefone">Telefone</label>
                        <input type="tel" id="telefone" name="telefone" required>
                    </div>
                    <div class="form-group">
                        <label for="dados_pix">Dados PIX</label>
                        <input type="text" id="dados_pix" name="dados_pix" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Registrar como Vendedor</button>
                </form>
            <?php endif; ?>
        </div>
    </main>
    <?php
    renderFooter();
}

function renderVendorProfile() {
    requireLogin();

    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM vendors WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $vendor = $stmt->fetch();

    if (!$vendor) {
        header('Location: ?page=vendor-register');
        exit();
    }

    // Buscar os grupos do vendedor
    $stmt = $pdo->prepare("SELECT * FROM `groups` WHERE usuario_id = :user_id ORDER BY criado DESC");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $groups = $stmt->fetchAll();

    // Buscar os produtos do vendedor
    $stmt = $pdo->prepare("SELECT * FROM marketplace WHERE usuario_id = :user_id ORDER BY criado DESC");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $products = $stmt->fetchAll();

    $error = '';
    $success = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nome = $_POST['nome'];
        $email = $_POST['email'];
        $telefone = $_POST['telefone'];
        $dados_pix = $_POST['dados_pix'];

        if (empty($nome) || empty($email) || empty($telefone) || empty($dados_pix)) {
            $error = "Todos os campos são obrigatórios.";
        } else {
            $stmt = $pdo->prepare("UPDATE vendors SET nome = :nome, email = :email, telefone = :telefone, dados_pix = :dados_pix WHERE user_id = :user_id");
            $result = $stmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':nome' => $nome,
                ':email' => $email,
                ':telefone' => $telefone,
                ':dados_pix' => $dados_pix
            ]);

            if ($result) {
                $success = "Perfil de vendedor atualizado com sucesso!";
                $vendor = [
                    'nome' => $nome,
                    'email' => $email,
                    'telefone' => $telefone,
                    'dados_pix' => $dados_pix
                ];
            } else {
                $error = "Ocorreu um erro ao atualizar. Por favor, tente novamente.";
            }
        }
    }

    renderHeader();
    ?>
    <main>
        <div class="container">
            <h1>Perfil de Vendedor</h1>
            <?php if ($error): ?>
                <div class="error"><?= $error ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success"><?= $success ?></div>
            <?php endif; ?>
            
            <!-- Formulário do perfil -->
            <form action="?page=vendor-profile" method="POST" class="form">
                <div class="form-group">
                    <label for="nome">Nome do Vendedor</label>
                    <input type="text" id="nome" name="nome" value="<?= htmlspecialchars($vendor['nome']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="email">Email de Contato</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($vendor['email']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="telefone">Telefone</label>
                    <input type="tel" id="telefone" name="telefone" value="<?= htmlspecialchars($vendor['telefone']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="dados_pix">Dados PIX</label>
                    <input type="text" id="dados_pix" name="dados_pix" value="<?= htmlspecialchars($vendor['dados_pix']) ?>" required>
                </div>
                <button type="submit" class="btn btn-primary">Atualizar Perfil de Vendedor</button>
            </form>

            <!-- Seção de Estatísticas -->
            <div class="vendor-stats">
                <h2>Suas Estatísticas</h2>
                <div class="stats-cards">
                    <div class="stat-card">
                        <h3>Total de Grupos</h3>
                        <p><?= count($groups) ?></p>
                    </div>
                    <div class="stat-card">
                        <h3>Total de Produtos</h3>
                        <p><?= count($products) ?></p>
                    </div>
                    <div class="stat-card">
                        <h3>Visualizações Totais</h3>
                        <p><?= array_sum(array_column($groups, 'view_count')) + array_sum(array_column($products, 'view_count')) ?></p>
                    </div>
                </div>
            </div>

                <!-- Seção de Produtos -->
                <h2>Seus Produtos</h2>
                <?php if (empty($products)): ?>
                    <p>Você ainda não cadastrou nenhum produto.</p>
                <?php else: ?>
                    <div class="products-grid">
                        <?php foreach ($products as $product): ?>
                            <div class="product-card">
                                <div class="card-header">
                                    <img src="<?= htmlspecialchars($product['imagem']) ?>" alt="<?= htmlspecialchars($product['nome_produto']) ?>">
                                    <?php if ($product['aprovado']): ?>
                                        <span class="status-badge approved">Aprovado</span>
                                    <?php else: ?>
                                        <span class="status-badge pending">Pendente</span>
                                    <?php endif; ?>
                                </div>
                                <div class="card-content">
                                    <h3><?= htmlspecialchars($product['nome_produto']) ?></h3>
                                    <p class="price">R$ <?= number_format($product['preco'], 2, ',', '.') ?></p>
                                    <div class="card-stats">
                                        <div class="stat">
                                            <i class="fas fa-eye"></i>

                                        </div>
                                        <div class="stat">
                                            <i class="fas fa-calendar"></i>
                                            <span><?= date('d/m/Y', strtotime($product['criado'])) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <style>
    .vendor-stats {
        margin: 2rem 0;
        padding: 1rem;
        background: var(--card-bg);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
    }

    .stats-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-top: 1rem;
    }

    .stat-card {
        background: linear-gradient(135deg, #f6f8fa 0%, #ffffff 100%);
        padding: 1.5rem;
        border-radius: var(--radius);
        text-align: center;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .stat-card h3 {
        color: var(--muted-foreground);
        font-size: 0.9rem;
        margin-bottom: 0.5rem;
    }

    .stat-card p {
        color: var(--foreground);
        font-size: 1.5rem;
        font-weight: bold;
    }

    .vendor-content {
        margin-top: 2rem;
    }

    .groups-grid,
    .products-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 1.5rem;
        margin: 1rem 0 2rem 0;
    }

    .group-card,
    .product-card {
        background: var(--card-bg);
        border-radius: var(--radius);
        overflow: hidden;
        box-shadow: var(--shadow);
        transition: transform 0.2s ease;
    }

    .group-card:hover,
    .product-card:hover {
        transform: translateY(-2px);
    }

    .card-header {
        position: relative;
    }

    .card-header img {
        width: 100%;
        height: 160px;
        object-fit: cover;
    }

    .status-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 500;
    }

    .status-badge.approved {
        background-color: #10B981;
        color: white;
    }

    .status-badge.pending {
        background-color: #F59E0B;
        color: white;
    }

    .card-content {
        padding: 1rem;
    }

    .card-content h3 {
        margin: 0 0 0.5rem 0;
        font-size: 1.1rem;
    }

    .description {
        color: var(--muted-foreground);
        font-size: 0.9rem;
        margin-bottom: 1rem;
    }

    .price {
        color: var(--primary);
        font-weight: bold;
        font-size: 1.2rem;
        margin-bottom: 1rem;
    }

    .card-stats {
        display: flex;
        justify-content: space-between;
        padding-top: 0.5rem;
        border-top: 1px solid var(--border);
    }

    .stat {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--muted-foreground);
        font-size: 0.9rem;
    }

    .stat i {
        color: var(--primary);
    }

    @media (max-width: 768px) {
        .stats-cards {
            grid-template-columns: 1fr;
        }

        .groups-grid,
        .products-grid {
            grid-template-columns: 1fr;
        }
    }
    </style>
    <?php
    renderFooter();
}
function renderCadastrarProduto() {
    requireLogin();

    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM vendors WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $vendor = $stmt->fetch();

    if (!$vendor) {
        header('Location: ?page=vendor-register');
        exit();
    }

    $error = '';
    $success = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nome_produto = $_POST['nome_produto'];
        $descricao = $_POST['descricao'];
        $preco = $_POST['preco'];
        $categoria = $_POST['categoria'];
        $imagem = $_FILES['imagem'];

        if (empty($nome_produto) || empty($descricao) || empty($preco) || empty($categoria)) {
            $error = "Todos os campos são obrigatórios.";
        } elseif ($imagem['error'] !== UPLOAD_ERR_OK) {
            $error = "Erro no upload da imagem.";
        } else {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            if (!in_array($imagem['type'], $allowed_types)) {
                $error = "Tipo de arquivo não permitido. Use apenas JPG, PNG ou GIF.";
            } else {
                $upload_dir = 'uploads/';
                $filename = uniqid() . '_' . basename($imagem['name']);
                $upload_path = $upload_dir . $filename;

                if (move_uploaded_file($imagem['tmp_name'], $upload_path)) {
                    $stmt = $pdo->prepare("INSERT INTO marketplace (usuario_id, nome_produto, descricao, preco, categoria, imagem) VALUES (:usuario_id, :nome_produto, :descricao, :preco, :categoria, :imagem)");
                    $result = $stmt->execute([
                        ':usuario_id' => $_SESSION['user_id'],
                        ':nome_produto' => $nome_produto,
                        ':descricao' => $descricao,
                        ':preco' => $preco,
                        ':categoria' => $categoria,
                        ':imagem' => $upload_path
                    ]);

                    if ($result) {
                        $success = "Produto cadastrado com sucesso! Aguarde a aprovação do administrador.";
                    } else {
                        $error = "Ocorreu um erro ao cadastrar o produto. Por favor, tente novamente.";
                    }
                } else {
                    $error = "Erro ao salvar a imagem.";
                }
            }
        }
    }

    renderHeader();
    ?>
    <main>
        <div class="container">
            <h1>Cadastrar Produto</h1>
            <?php if ($error): ?>
                <div class="error"><?= $error ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success"><?= $success ?></div>
            <?php endif; ?>
            <form action="?page=cadastrar-produto" method="POST" enctype="multipart/form-data" class="form">
                <div class="form-group">
                    <label for="nome_produto">Nome do Produto</label>
                    <input type="text" id="nome_produto" name="nome_produto" required>
                </div>
                <div class="form-group">
                    <label for="descricao">Descrição</label>
                    <textarea id="descricao" name="descricao" required></textarea>
                </div>
                <div class="form-group">
                    <label for="preco">Preço (R$)</label>
                    <input type="number" id="preco" name="preco" step="0.01" required>
                </div>
                <div class="form-group">
                    <label for="categoria">Categoria</label>
                    <input type="hidden" id="categoria" name="categoria" required>
                    <div class="category-grid">
                        <?php
                        $categories = [
                            ['Assinaturas e Premium', 'star'],
                            ['Bot WhatsApp', 'robot'],
                            ['Grupos WhatsApp', 'users'],
                            ['Contas', 'user-circle'],
                            ['Design Gráfico', 'palette'],
                            ['Free Fire', 'fire'],
                            ['Valorant', 'gamepad'],
                            ['Fortnite', 'fort-awesome'],
                            ['Minecraft', 'cube'],
                            ['Counter Strike', 'crosshairs'],
                            ['Cursos e Treinamentos', 'graduation-cap'],
                            ['Números WhatsApp', 'phone'],
                            ['Seguidores/Curtidas', 'thumbs-up'],
                            ['Rede Social', 'share-alt'],
                            ['Software e Licenças', 'key'],
                            ['E-mails', 'envelope'],
                            ['Serviços digitais', 'laptop-code'],
                            ['Discord', 'discord'],
                            ['ZapLinks', 'link']
                        ];
                        
                        foreach ($categories as $cat):
                            list($category, $icon) = $cat;
                        ?>
                            <div class="category-item" data-category="<?= htmlspecialchars($category) ?>" onclick="selectCategory(this)">
                                <i class="fas fa-<?= $icon ?>"></i>
                                <span><?= htmlspecialchars($category) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label for="imagem">Imagem ou Gif do Produto</label>
                    <div class="image-upload-container">
                        <input type="file" id="imagem" name="imagem" accept="image/*" required class="image-upload-input">
                        <label for="imagem" class="image-upload-label">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <span>Escolha uma imagem ou arraste aqui</span>
                        </label>
                        <div id="image-preview" class="image-preview"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="terms">
                        <input type="checkbox" id="terms" name="terms" required>
                        Eu li e concordo com os <a href="#" onclick="showTerms(); return false;">Termos de Uso para Vendas Online</a>
                    </label>
                </div>
                <button type="submit" class="btn btn-primary">Cadastrar Produto</button>
            </form>
        </div>
    </main>

    <div id="termsModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Termos de Uso para Vendas Online</h2>
            <p>1. Responsabilidade do Vendedor: O vendedor é responsável pela precisão das informações do produto, entrega e qualidade do item vendido.</p>
            <p>2. Produtos Proibidos: É proibida a venda de itens ilegais, falsificados ou que violem direitos autorais.</p>
            <p>3. Preços e Pagamentos: Os preços devem ser claramente indicados e todas as transações devem ser processadas através de métodos de pagamento seguros.</p>
            <p>4. Política de Reembolso: O vendedor deve estabelecer uma política clara de reembolso e devolução.</p>
            <p>5. Comunicação: Mantenha uma comunicação clara e respeitosa com os compradores.</p>
            <p>6. Conformidade Legal: Cumpra todas as leis e regulamentos aplicáveis ao comércio eletrônico.</p>
            <p>7. Privacidade: Proteja as informações pessoais dos clientes de acordo com as leis de proteção de dados.</p>
            <p>8. Resolução de Disputas: Coopere na resolução de quaisquer disputas que possam surgir com os compradores.</p>
            <p>9. Cancelamento de Conta: Reservamos o direito de cancelar contas que violem estes termos.</p>
            <p>10. Alterações nos Termos: Estes termos podem ser atualizados periodicamente. É responsabilidade do vendedor manter-se informado sobre quaisquer mudanças.</p>
        </div>
    </div>

    <style>
    .category-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 1rem;
        margin-top: 1rem;
    }

    .category-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 1rem;
        background-color: var(--card-bg);
        border: 2px solid var(--border);
        border-radius: var(--radius);
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .category-item:hover {
        border-color: var(--primary);
        background-color: rgba(37, 211, 102, 0.1);
        transform: translateY(-2px);
    }

    .category-item.selected {
        background-color: var(--primary);
        border-color: var(--primary);
        color: white;
    }

    .category-item i {
        font-size: 1.2rem;
    }

    .category-item span {
        font-size: 0.9rem;
        font-weight: 500;
    }

    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.4);
    }

    .modal-content {
        background-color: var(--card-bg);
        margin: 15% auto;
        padding: 20px;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        width: 80%;
        max-width: 600px;
    }

    .close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }

    .close:hover,
    .close:focus {
        color: #000;
        text-decoration: none;
        cursor: pointer;
    }

    /* Animação de fade-in para as categorias */
    .category-item {
        opacity: 0;
        animation: fadeIn 0.3s ease forwards;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Adicionar delay na animação para cada item */
    <?php for($i = 0; $i < count($categories); $i++): ?>
    .category-item:nth-child(<?= $i + 1 ?>) {
        animation-delay: <?= $i * 0.05 ?>s;
    }
    <?php endfor; ?>
    </style>

    <script>
    function selectCategory(element) {
        document.querySelectorAll('.category-item').forEach(item => {
            item.classList.remove('selected');
        });
        element.classList.add('selected');
        document.getElementById('categoria').value = element.dataset.category;
    }

    function showTerms() {
        document.getElementById('termsModal').style.display = 'block';
    }

    // Close modal when clicking on <span> (x)
    document.querySelector('.close').onclick = function() {
        document.getElementById('termsModal').style.display = 'none';
    }

    // Close modal when clicking outside of it
    window.onclick = function(event) {
        if (event.target == document.getElementById('termsModal')) {
            document.getElementById('termsModal').style.display = 'none';
        }
    }

    // Existing image upload code
    document.addEventListener('DOMContentLoaded', function() {
        const imageInput = document.getElementById('imagem');
        const imagePreview = document.getElementById('image-preview');
        const imageLabel = document.querySelector('.image-upload-label span');

        imageInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    imagePreview.style.backgroundImage = `url(${e.target.result})`;
                    imagePreview.style.display = 'block';
                    imageLabel.textContent = file.name;
                }
                reader.readAsDataURL(file);
            }
        });

        // ... (rest of the existing image upload code)
    });
    </script>
    <?php
    renderFooter();
}
function renderProduto() {
    requireLogin();
    global $pdo;
    
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    $stmt = $pdo->prepare("SELECT m.*, username as vendor_name,
                           COALESCE(u.whatsapp, '5511999999999') as vendor_whatsapp
                           FROM marketplace m 
                           LEFT JOIN users u ON m.usuario_id = u.id 
                           WHERE m.id = :id AND m.aprovado = 1");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $produto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$produto) {
        echo "Produto não encontrado.";
        return;
    }
    
    // Buscar produtos relacionados
    $stmt = $pdo->prepare("SELECT * FROM marketplace WHERE categoria = :categoria AND id != :id AND aprovado = 1 ORDER BY RAND() LIMIT 4");
    $stmt->bindParam(':categoria', $produto['categoria'], PDO::PARAM_STR);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $produtosRelacionados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    renderHeader();
    ?>
    <main class="product-details">
        <div class="container">
            <nav class="breadcrumb" aria-label="breadcrumb">
                <ol>
                    <li><a href="?page=marketplace">Marketplace</a></li>
                    <li><a href="?page=marketplace&category=<?= urlencode($produto['categoria']) ?>"><?= htmlspecialchars($produto['categoria']) ?></a></li>
                    <li aria-current="page"><?= htmlspecialchars($produto['nome_produto']) ?></li>
                </ol>
            </nav>

            <div class="product-main">
                <div class="product-gallery">
                    <div class="product-image-main">
                        <img src="<?= htmlspecialchars($produto['imagem']) ?>" alt="<?= htmlspecialchars($produto['nome_produto']) ?>" id="main-image">
                    </div>
                </div>
                <div class="product-info">
                    <h1 class="product-title"><?= htmlspecialchars($produto['nome_produto']) ?></h1>
                    <div class="product-meta">
                        <span class="product-category"><?= htmlspecialchars($produto['categoria']) ?></span>
                    </div>
                    <div class="product-price">
                        <span class="current-price">R$ <?= number_format($produto['preco'], 2, ',', '.') ?></span>
                    </div>
                    <div class="product-description">
                        <?= nl2br(htmlspecialchars($produto['descricao'])) ?>
                    </div>
                    <div class="product-actions">
                        <a href="?page=comprar&id=<?= $produto['id'] ?>" class="btn btn-primary btn-large btn-buy">
                            <i class="fas fa-shopping-cart"></i> Comprar Agora
                        </a>
                        <button class="btn btn-secondary btn-large btn-wishlist">
                            <i class="far fa-heart"></i> Adicionar aos Favoritos
                        </button>
                    </div>
                    <div class="product-guarantee">
                        <i class="fas fa-shield-alt"></i>
                        <span>Garantia ZapLinks de 7 dias</span>
                    </div>
                </div>
            </div>

            <div class="product-details-tabs">
                <ul class="tabs-nav">
                    <li class="active" data-tab="description">Descrição</li>
                    <li data-tab="specifications">Especificações</li>
                </ul>
                <div class="tabs-content">
                    <div id="description" class="tab-pane active">
                        <h3>Descrição do Produto</h3>
                        <?= nl2br(htmlspecialchars($produto['descricao'])) ?>
                    </div>
                    <div id="specifications" class="tab-pane">
                        <h3>Especificações</h3>
                        <ul>
                            <li><strong>Categoria:</strong> <?= htmlspecialchars($produto['categoria']) ?></li>
                            <li><strong>Formato de Entrega:</strong> Digital</li>
                            <li><strong>Prazo de Entrega:</strong> Imediato após confirmação do pagamento</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="vendor-info">
                <h3>Informações do Vendedor</h3>
                <div class="vendor-card">
                    <div class="vendor-details">
                        <h4><?= htmlspecialchars($produto['vendor_name']) ?></h4>
                    </div>
                    <a href="#" class="btn btn-outline">Ver Perfil</a>
                </div>
            </div>

            <?php if (!empty($produtosRelacionados)): ?>
            <div class="related-products">
                <h3>Produtos Relacionados</h3>
                <div class="products-grid">
                    <?php foreach ($produtosRelacionados as $relacionado): ?>
                        <div class="product-card">
                            <div class="product-image">
                                <img src="<?= htmlspecialchars($relacionado['imagem']) ?>" alt="<?= htmlspecialchars($relacionado['nome_produto']) ?>">
                            </div>
                            <div class="product-info">
                                <h4><?= htmlspecialchars($relacionado['nome_produto']) ?></h4>
                                <p class="product-price">R$ <?= number_format($relacionado['preco'], 2, ',', '.') ?></p>
                            </div>
                            <a href="?page=produto=<?= $relacionado['nome_produto'] ?>" class="btn btn-secondary">Comprar</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <style>
        .product-details {
            padding: 3rem 0;
            background-color: #f8f9fa;
        }
    
        .breadcrumb {
            margin-bottom: 2rem;
            padding: 0.5rem 1rem;
            background-color: #ffffff;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
    
        .breadcrumb ol {
            list-style: none;
            display: flex;
            padding: 0;
            margin: 0;
        }
    
        .breadcrumb li {
            font-size: 0.9rem;
        }
    
        .breadcrumb li:not(:last-child)::after {
            content: ">";
            margin: 0 0.5rem;
            color: var(--muted-foreground);
        }
    
        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }
    
        .breadcrumb li:last-child {
            color: var(--muted-foreground);
        }
    
        .product-main {
            display: flex;
            gap: 2rem;
            margin-bottom: 3rem;
            background-color: #ffffff;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 2rem;
        }
    
        .product-gallery {
            flex: 1;
            max-width: 500px;
        }
    
        .product-image-main {
            margin-bottom: 1rem;
            border-radius: var(--radius);
            overflow: hidden;
        }
    
        .product-image-main img {
            width: 100%;
            height: auto;
            object-fit: cover;
        }
    
        .product-image-thumbnails {
            display: flex;
            gap: 0.5rem;
        }
    
        .thumbnail {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: var(--radius);
            cursor: pointer;
            border: 2px solid transparent;
            transition: border-color 0.3s ease;
        }
    
        .thumbnail.active,
        .thumbnail:hover {
            border-color: var(--primary);
        }
    
        .product-info {
            flex: 1;
        }
    
        .product-title {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--foreground);
        }
    
        .product-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: var(--muted-foreground);
        }
    
        .product-rating {
            margin-bottom: 1rem;
            color: #ffc107;
        }
    
        .rating-count {
            color: var(--muted-foreground);
            font-size: 0.9rem;
            margin-left: 0.5rem;
        }
    
        .product-price {
            margin-bottom: 1.5rem;
        }
    
        .current-price {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary);
        }
    
        .original-price {
            text-decoration: line-through;
            color: var(--muted-foreground);
            margin-left: 0.5rem;
        }
    
        .discount-percentage {
            background-color: var(--primary);
            color: #ffffff;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
            margin-left: 0.5rem;
        }
    
        .product-description {
            margin-bottom: 1.5rem;
            line-height: 1.6;
        }
    
        .product-actions {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
    
        .btn-buy,
        .btn-wishlist {
            flex: 1;
        }
    
        .product-guarantee {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: var(--muted-foreground);
        }
    
        .product-details-tabs {
            background-color: #ffffff;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 3rem;
        }
    
        .tabs-nav {
            display: flex;
            list-style: none;
            padding: 0;
            margin: 0;
            border-bottom: 1px solid var(--border);
        }
    
        .tabs-nav li {
            padding: 1rem 1.5rem;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
    
        .tabs-nav li.active {
            background-color: #ffffff;
            border-bottom: 2px solid var(--primary);
            color: var(--primary);
        }
    
        .tab-pane {
            display: none;
            padding: 2rem;
        }
    
        .tab-pane.active {
            display: block;
        }
    
        .vendor-info {
            background-color: #ffffff;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-bottom: 3rem;
        }
    
        .vendor-card {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
    
        .vendor-avatar img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
        }
    
        .vendor-details {
            flex: 1;
        }
    
        .vendor-details h4 {
            margin: 0 0 0.5rem;
        }
    
        .vendor-details p {
            margin: 0 0 0.25rem;
            color: var(--muted-foreground);
        }
    
        
        .related-products {
            margin-top: 3rem;
        }
    
        .related-products h3 {
            margin-bottom: 1.5rem;
        }
    
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1.5rem;
        }
    
        .product-card {
            background-color: #ffffff;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
    
        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
    
        .product-card .product-image {
            height: 200px;
            overflow: hidden;
        }
    
        .product-card .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
    
        .product-card:hover .product-image img {
            transform: scale(1.05);
        }
    
        .product-card .product-info {
            padding: 1rem;
        }
    
        .product-card h4 {
            margin: 0 0 0.5rem;
            font-size: 1rem;
        }
    
        .product-card .product-price {
            font-weight: bold;
            color: var(--primary);
        }
    
        .product-card .btn {
            display: block;
            width: 100%;
            text-align: center;
            margin-top: 1rem;
        }
    
        @media (max-width: 768px) {
            .product-main {
                flex-direction: column;
            }
    
            .product-gallery {
                max-width: 100%;
            }
    
            .product-actions {
                flex-direction: column;
            }
    
            .vendor-card {
                flex-direction: column;
                text-align: center;
            }
    
            .vendor-details {
                margin-bottom: 1rem;
            }
        }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const tabs = document.querySelectorAll('.tabs-nav li');
        const tabContents = document.querySelectorAll('.tab-pane');

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const tabId = tab.getAttribute('data-tab');
                
                tabs.forEach(t => t.classList.remove('active'));
                tabContents.forEach(content => content.classList.remove('active'));

                tab.classList.add('active');
                document.getElementById(tabId).classList.add('active');
            });
        });

        const wishlistBtn = document.querySelector('.btn-wishlist');
        wishlistBtn.addEventListener('click', function() {
            this.classList.toggle('active');
            const icon = this.querySelector('i');
            if (this.classList.contains('active')) {
                icon.classList.remove('far');
                icon.classList.add('fas');
            } else {
                icon.classList.remove('fas');
                icon.classList.add('far');
            }
        });
    });
    </script>
    <?php
    renderFooter();
}
   
function renderComprar() {
    // Check if product ID is provided
    if (!isset($_GET['id']) || empty($_GET['id'])) {
        echo "Produto não encontrado.";
        return;
    }
    
    global $pdo;
    $id = intval($_GET['id']);
    
    // Get product and vendor information using the VENDORS table
    $stmt = $pdo->prepare("SELECT m.*, 
                          v.nome as vendor_name, 
                          v.telefone as vendor_telefone 
                          FROM marketplace m 
                          LEFT JOIN vendors v ON m.usuario_id = v.user_id 
                          WHERE m.id = :id AND m.aprovado = 1");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $produto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$produto) {
        echo "Produto não encontrado.";
        return;
    }
    
    // Check if vendor has a phone number
    if (empty($produto['vendor_telefone'])) {
        // If vendor doesn't have a phone number, show an error message
        renderHeader();
        ?>
        <div class="container mt-5">
            <div class="alert alert-danger">
                <h4>Não foi possível completar a ação</h4>
                <p>O vendedor não possui um número de telefone cadastrado. Por favor, entre em contato por outro meio.</p>
                <a href="?page=produto&id=<?= $id ?>" class="btn btn-primary mt-3">Voltar ao produto</a>
            </div>
        </div>
        <?php
        renderFooter();
        return;
    }
    
    // Format the phone number for WhatsApp (remove any non-digit characters)
    $phoneNumber = preg_replace('/[^0-9]/', '', $produto['vendor_telefone']);
    
    // Format the WhatsApp message
    $productName = htmlspecialchars_decode($produto['nome_produto']);
    $message = "Olá Vendedor da ZapLinks, Gostaria de Saber se o item {$productName} ainda está disponível";
    $encodedMessage = urlencode($message);
    
    // Create WhatsApp URL with the vendor's phone number
    $whatsappURL = "https://wa.me/" . $phoneNumber . "?text=" . $encodedMessage;
    
    // Redirect to WhatsApp
    header("Location: " . $whatsappURL);
    exit;
}
    function renderAvaliarProduto() {
        requireLogin();
        global $pdo;
    
        $product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $user_id = $_SESSION['user_id'];
    
        // Verificar se o produto existe
$stmt = $pdo->prepare("SELECT id, nome_produto, imagem, usuario_id FROM marketplace WHERE id = :id AND aprovado = 1");
$stmt->bindParam(':id', $product_id, PDO::PARAM_INT);
$stmt->execute();
$produto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$produto) {
    echo "Produto não encontrado.";
    return;
}

// Obter o vendor_id do produto
$vendor_id = $produto['usuario_id']; // Supondo que usuario_id é o vendor_id

// Verificar se o usuário já avaliou este produto
$stmt = $pdo->prepare("SELECT id FROM ratings WHERE user_id = :user_id AND product_id = :product_id");
$stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
$stmt->execute();
$existingRating = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existingRating) {
    echo "Você já avaliou este produto.";
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = isset($_POST['rating']) ? intval($_POST['rating']) : 0;
    $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';

    if ($rating < 1 || $rating > 5) {
        echo "Avaliação inválida.";
        return;
    }

    if (empty($comment)) {
        echo "O comentário não pode estar vazio.";
        return;
    }

    // Inserir a avaliação, incluindo o vendor_id
    $stmt = $pdo->prepare("
        INSERT INTO ratings (user_id, product_id, rating, comment, vendor_id, created_at) 
        VALUES (:user_id, :product_id, :rating, :comment, :vendor_id, NOW())
    ");
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':product_id', $product_id, PDO::PARAM_INT);
    $stmt->bindParam(':rating', $rating, PDO::PARAM_INT);
    $stmt->bindParam(':comment', $comment, PDO::PARAM_STR);
    $stmt->bindParam(':vendor_id', $vendor_id, PDO::PARAM_INT); // Adicionando o vendor_id

    if ($stmt->execute()) {
        echo "<script>alert('Avaliação enviada com sucesso!'); window.location.href = '?page=produto&id=$product_id';</script>";
        exit;
    } else {
        echo "Erro ao enviar a avaliação.";
    }
}
    
        renderHeader();
        ?>
        <main class="avaliar-produto">
            <div class="container">
                <h1>Avaliar Produto</h1>
                <div class="product-summary">
                    <img src="<?= htmlspecialchars($produto['imagem']) ?>" alt="<?= htmlspecialchars($produto['nome_produto']) ?>" class="product-image">
                    <h2><?= htmlspecialchars($produto['nome_produto']) ?></h2>
                </div>
                <form action="?page=avaliar-produto&id=<?= $product_id ?>" method="POST" class="rating-form">
                    <div class="form-group">
                        <label>Sua Avaliação</label>
                        <div class="rating-stars">
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <input type="radio" id="star<?= $i ?>" name="rating" value="<?= $i ?>" required>
                                <label for="star<?= $i ?>"><i class="fas fa-star"></i></label>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="comment">Seu Comentário</label>
                        <textarea id="comment" name="comment" rows="4" required placeholder="Conte-nos sua experiência com este produto..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Enviar Avaliação</button>
                </form>
            </div>
        </main>
    
        <style>
        .avaliar-produto {
            padding: 2rem 0;
            background-color: #f8f9fa;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 0 15px;
        }
        h1 {
            text-align: center;
            margin-bottom: 2rem;
            color: #333;
        }
        .product-summary {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
            background-color: #fff;
            padding: 1rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .product-image {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 8px;
            margin-right: 1rem;
        }
        .product-summary h2 {
            font-size: 1.2rem;
            margin: 0;
            color: #333;
        }
        .rating-form {
            background-color: #fff;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: bold;
            color: #333;
        }
        .rating-stars {
            display: flex;
            flex-direction: row-reverse;
            justify-content: flex-end;
        }
        .rating-stars input {
            display: none;
        }
        .rating-stars label {
            cursor: pointer;
            font-size: 1.5rem;
            color: #ddd;
            transition: color 0.2s ease-in-out;
        }
        .rating-stars label:hover,
        .rating-stars label:hover ~ label,
        .rating-stars input:checked ~ label {
            color: #ffc107;
        }
        textarea {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ced4da;
            border-radius: 4px;
            resize: vertical;
        }
        .btn-primary {
            display: block;
            width: 100%;
            padding: 0.75rem;
            background-color: #007bff;
            color: #fff;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            cursor: pointer;
            transition: background-color 0.2s ease-in-out;
        }
        .btn-primary:hover {
            background-color: #0056b3;
        }
        </style>
    
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('.rating-form');
            form.addEventListener('submit', function(e) {
                const rating = form.querySelector('input[name="rating"]:checked');
                const comment = form.querySelector('#comment');
                
                if (!rating) {
                    e.preventDefault();
                    alert('Por favor, selecione uma avaliação.');
                } else if (comment.value.trim().length < 10) {
                    e.preventDefault();
                    alert('Por favor, escreva um comentário com pelo menos 10 caracteres.');
                }
            });
        });
        </script>
        <?php
        renderFooter();
    }
    
    
    
    
function renderGroup() {
    global $pdo;

    $group_id = $_GET['id'] ?? null;

    if (!$group_id) {
        header('Location: ?page=index');
        exit();
    }

    $stmt = $pdo->prepare("SELECT g.*, u.username FROM `groups` g JOIN users u ON g.usuario_id = u.id WHERE g.id = :id AND g.aprovação = 1");
    $stmt->execute([':id' => $group_id]);
    $group = $stmt->fetch();

    if (!$group) {
        header('Location: ?page=index');
        exit();
    }

    // Incrementar o contador de visualizações
    $stmt = $pdo->prepare("UPDATE `groups` SET view_count = view_count + 1 WHERE id = :id");
    $stmt->execute([':id' => $group_id]);

    renderHeader();
    ?>
    <main>
        <div class="container">
            <div class="group-details">
                <div class="group-card">
                    <div class="card-header">
                        <img src="<?= htmlspecialchars($group['imagem']) ?>" alt="<?= htmlspecialchars($group['nome_grupo']) ?>">
                        <span class="status-badge approved">Aprovado</span>
                    </div>
                    <div class="card-content">
                        <h1><?= htmlspecialchars($group['nome_grupo']) ?></h1>
                        <p class="description"><?= htmlspecialchars($group['descrição']) ?></p>
                        <div class="card-stats">
                            <div class="stat">
                                <i class="fas fa-user"></i>
                                <span>Administrador: <?= htmlspecialchars($group['username']) ?></span>
                            </div>
                            <div class="stat">
                                <i class="fas fa-tag"></i>
                                <span>Categoria: <?= htmlspecialchars($group['categoria']) ?></span>
                            </div>
                            <div class="stat">
                             <i class="fas fa-check"></i>
                            <span>Grupo Verificado!</span>
                            </div>
                             <div class="stat">
                              <i class="fas fa-file-contract"></i>
                             <span>Grupo segue as Diretrizes!</span>
                            </div>
                            <div class="stat">
                                <i class="fas fa-calendar"></i>
                                <span>Divulgado em: <?= date('d/m/Y', strtotime($group['criado'])) ?></span>
                            </div>
                        </div>
                        <div class="group-actions">
                            <a href="<?= htmlspecialchars($group['grupo_link']) ?>" class="btn btn-primary" target="_blank" rel="noopener noreferrer">
                                <i class="fab fa-whatsapp"></i> Entrar no Grupo
                            </a>
                        </div>
                        <?php if (!empty($group['impulsionado_desde'])): ?>
                            <p class="impulso-info">Grupo Impulsionado  <?= tempoDecorrido($group['impulsionado_desde']) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <style>
    .group-details {
        max-width: 800px;
        margin: 2rem auto;
    }

    .group-card {
        background: var(--card-bg);
        border-radius: var(--radius);
        overflow: hidden;
        box-shadow: var(--shadow);
    }

    .card-header {
        position: relative;
    }

    .card-header img {
        width: 100%;
        height: 300px;
        object-fit: cover;
    }

    .status-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 500;
        background-color: #10B981;
        color: white;
    }

    .card-content {
        padding: 1.5rem;
    }

    .card-content h1 {
        margin: 0 0 1rem 0;
        font-size: 1.8rem;
        color: var(--primary);
    }

    .description {
        color: var(--foreground);
        font-size: 1rem;
        margin-bottom: 1.5rem;
        line-height: 1.6;
    }

    .card-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        padding-top: 1rem;
        border-top: 1px solid var(--border);
        margin-bottom: 1.5rem;
    }

    .stat {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--muted-foreground);
        font-size: 0.9rem;
    }

    .stat i {
        color: var(--primary);
    }

    .group-actions {
        display: flex;
        justify-content: center;
    }

    .btn-primary {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem 1.5rem;
        font-size: 1rem;
        font-weight: 600;
    }

    .impulso-info {
        text-align: center;
        font-style: italic;
        color: var(--muted-foreground);
        margin-top: 1rem;
        font-size: 0.9rem;
    }

    @media (max-width: 768px) {
        .card-stats {
            grid-template-columns: 1fr;
        }
    }
    </style>
    <?php
    renderFooter();
}

switch ($page) {
 // Páginas principais ZapLinks
 case 'index':
    renderIndex();
    break;
case 'login':
    renderLogin();
    break;
case 'register':
    renderRegister();
    break;
case 'logout':
    renderLogout();
    break;
case 'perfil':
    renderPerfil();
    break;
case 'termos':
    renderTermos();
    break;
    
// Grupos ZapLinks
case 'enviar-grupo':
    renderEnviarGrupo();
    break;
case 'meus-grupos':
    renderMeusGrupos();
    break;
case 'editar-grupo':
    renderEditarGrupo();
    break;
case 'excluir-grupo':
    renderExcluirGrupo();
    break;
case 'group':
    renderGroup();
    break;
case 'aprovar-grupo':
    renderAprovarGrupo();
    break;
case 'rejeitar-grupo':
    renderRejeitarGrupo();
    break;

    case 'group_options':
        renderGroupOptions();
        break;
    
// Marketplace ZapLinks
case 'marketplace':
    renderMarketplace();
    break;
case 'cadastrar-produto':
    renderCadastrarProduto();
    break;
case 'produto':
    renderProduto();
    break;
case 'comprar':
    renderComprar();
    break;
case 'avaliar-produto':
    renderAvaliarProduto();
    break;
case 'checkout':
    renderCheckout();
    break;
    
// Vendedor ZapLinks
case 'vendor-register':
    renderVendorRegister();
    break;
case 'vendor-profile':
    renderVendorProfile();
    break;
    
// Administração ZapLinks
case 'admin':
    renderAdminPanel();
    break;
case 'users':
    renderUserManagement();
    break;
case 'all-groups':
    renderAllGroups();
    break;
case 'marketplace-admin':
    renderMarketplaceAdmin();
    break;
case 'reports':
    renderReports();
    break;
case 'pending-groups':
    renderPendingGroups();
    break;
case 'edit-user':
    renderEditUser();
    break;
case 'delete-user':
    renderDeleteUser();
    break;
case 'delete-group':
    renderDeleteGroup();
    break;
case 'aprovar-produto':
    renderAprovarProduto();
    break;
case 'rejeitar-produto':
    renderRejeitarProduto();
    break;
case 'delete-produto':
    renderDeleteProduto();
    break;
    
// Notificações ZapLinks
case 'get-notifications':
    renderGetNotifications();
    break;
case 'mark-notification-read':
    renderMarkNotificationRead();
    break;
case 'mark-all-notifications-read':
    renderMarkAllNotificationsRead();
    break;
case 'send-notification':
        renderSendNotification();
     break;
    
// Página não encontrada ZapLinks
default:
    renderNotFound();
    break;
}

// Função para página não encontrada ZapLinks
function renderNotFound() {
http_response_code(404);
renderHeader();
?>
<main class="not-found">
    <div class="container">
        <div class="not-found-content">
            <h1>404</h1>
            <h2>Rum! </h2>
            <p>A página que você está procurando não existe ou foi removida.</p>
            <a href="?page=index" class="btn btn-primary">Voltar para a página inicial</a>
        </div>
    </div>
</main> 
<style>
.not-found {
    min-height: 70vh;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 2rem 0;
}

.not-found-content {
    max-width: 600px;
    margin: 0 auto;
}

.not-found h1 {
    font-size: 6rem;
    color: var(--primary);
    margin: 0;
}

.not-found h2 {
    font-size: 2rem;
    margin-bottom: 1rem;
}

.not-found p {
    font-size: 1.1rem;
    color: #6c757d;
    margin-bottom: 2rem;
}
</style>
<?php
renderFooter();
}

ob_end_flush();
?>

