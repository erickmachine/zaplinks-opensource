<?php
require_once 'vendor/autoload.php';
MercadoPago\SDK::setAccessToken("APP_USR-3642933615984070-042314-b0bf9b2e4d6043ea57f2ef0efe58a4bf-1339300911");

function renderContent() {
    global $pdo;

    requireLogin();

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

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payment = new MercadoPago\Payment();
        $payment->transaction_amount = 5;
        $payment->description = "Impulso de 24 horas para o grupo: " . $group['nome_grupo'];
        $payment->payment_method_id = "pix";
        $payment->payer = array(
            "email" => $_POST['email'],
            "first_name" => $_POST['first_name'],
            "last_name" => $_POST['last_name']
        );

        $payment->save();

        if ($payment->status == 'approved') {
            $stmt = $pdo->prepare("UPDATE `groups` SET impulsionado = 1, impulsionado_desde = NOW() WHERE id = :id");
            $stmt->execute([':id' => $group_id]);

            header('Location: ?page=checkout&status=success&group_id=' . $group_id);
            exit();
        } else {
            $error = "Falha no pagamento. Por favor, tente novamente.";
        }
    }

    $status = $_GET['status'] ?? '';
    ?>
    <div class="checkout-container">
        <?php if ($status === 'success'): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <h2>Pagamento Aprovado!</h2>
                <p>Seu grupo "<?= htmlspecialchars($group['nome_grupo']) ?>" foi impulsionado por 24 horas.</p>
                <a href="?page=meus-grupos" class="btn btn-primary">Voltar para Meus Grupos</a>
            </div>
        <?php else: ?>
            <h1>Checkout - Impulso de Grupo</h1>
            <div class="group-info">
                <h2><?= htmlspecialchars($group['nome_grupo']) ?></h2>
                <p>Impulso de 24 horas</p>
                <p class="price">R$ 5,00</p>
            </div>
            <?php if (isset($error)): ?>
                <div class="error-message"><?= $error ?></div>
            <?php endif; ?>
            <form action="?page=checkout&group_id=<?= $group_id ?>" method="POST" id="payment-form">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="first_name">Nome</label>
                    <input type="text" id="first_name" name="first_name" required>
                </div>
                <div class="form-group">
                    <label for="last_name">Sobrenome</label>
                    <input type="text" id="last_name" name="last_name" required>
                </div>
                <button type="submit" class="btn btn-primary">Pagar com PIX</button>
            </form>
        <?php endif; ?>
    </div>

    <style>
    .checkout-container {
        max-width: 600px;
        margin: 2rem auto;
        padding: 2rem;
        background-color: #fff;
        border-radius: 10px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .checkout-container h1 {
        color: #00a884;
        margin-bottom: 1.5rem;
    }

    .group-info {
        background-color: #e6fff9;
        padding: 1rem;
        border-radius: 5px;
        margin-bottom: 1.5rem;
    }

    .group-info h2 {
        color: #00a884;
        margin-bottom: 0.5rem;
    }

    .price {
        font-size: 1.5rem;
        font-weight: bold;
        color: #00a884;
    }

    .form-group {
        margin-bottom: 1rem;
    }

    .form-group label {
        display: block;
        margin-bottom: 0.5rem;
        color: #333;
    }

    .form-group input {
        width: 100%;
        padding: 0.5rem;
        border: 1px solid #ccc;
        border-radius: 5px;
        font-size: 1rem;
    }

    .btn-primary {
        background-color: #00a884;
        color: #fff;
        border: none;
        padding: 0.75rem 1.5rem;
        font-size: 1rem;
        border-radius: 5px;
        cursor: pointer;
        transition: background-color 0.3s ease;
    }

    .btn-primary:hover {
        background-color: #008f6f;
    }

    .success-message {
        text-align: center;
        color: #00a884;
    }

    .success-message i {
        font-size: 4rem;
        margin-bottom: 1rem;
    }

    .error-message {
        background-color: #ffe6e6;
        color: #ff3333;
        padding: 1rem;
        border-radius: 5px;
        margin-bottom: 1rem;
    }

    @media (max-width: 768px) {
        .checkout-container {
            padding: 1rem;
            margin: 1rem;
        }
    }
    </style>
    <?php
}

