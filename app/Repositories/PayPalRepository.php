<?php
declare(strict_types=1);
use PDO;

class PayPalRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function createTransaction(string $orderId, array $orderData): int
    {
        // Check if order already exists to prevent duplicates
        $existing = $this->getTransactionByOrderId($orderId);
        if ($existing) {
            error_log("PayPal: Order $orderId already exists in database");
            return (int)$existing['id'];
        }

        $stmt = $this->db->prepare("
            INSERT INTO paypal_transactions (
                paypal_order_id, status, raw_response, created_at, updated_at, webhook_verified
            ) VALUES (?, ?, ?, NOW(), NOW(), 0)
        ");
        $stmt->execute([
            $orderId,
            $orderData['status'] ?? 'CREATED',
            json_encode($orderData, JSON_UNESCAPED_SLASHES),
        ]);
        
        $transactionId = (int)$this->db->lastInsertId();
        error_log("PayPal: Created transaction ID $transactionId for order $orderId");
        
        return $transactionId;
    }

    public function updateTransactionWithCapture(string $orderId, array $captureData): bool
    {
        $paymentId  = null;
        $payerId    = null;
        $payerEmail = null;
        
        if (isset($captureData['purchase_units'][0]['payments']['captures'][0])) {
            $capture   = $captureData['purchase_units'][0]['payments']['captures'][0];
            $paymentId = $capture['id'] ?? null;
        }
        
        if (isset($captureData['payer'])) {
            $payerId    = $captureData['payer']['payer_id'] ?? null;
            $payerEmail = $captureData['payer']['email_address'] ?? null;
        }

        $stmt = $this->db->prepare("
            UPDATE paypal_transactions
            SET 
                paypal_payment_id = ?,
                payer_id          = ?,
                payer_email       = ?,
                status            = ?,
                raw_response      = ?,
                updated_at        = NOW()
            WHERE paypal_order_id = ?
        ");
        
        $result = $stmt->execute([
            $paymentId,
            $payerId,
            $payerEmail,
            $captureData['status'] ?? 'COMPLETED',
            json_encode($captureData, JSON_UNESCAPED_SLASHES),
            $orderId,
        ]);

        if ($result) {
            error_log("PayPal: Updated transaction for order $orderId with capture data");
        } else {
            error_log("PayPal: Failed to update transaction for order $orderId");
        }

        return $result;
    }

    public function linkToDonation(string $orderId, int $donationId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE paypal_transactions 
            SET donation_id = ?, updated_at = NOW() 
            WHERE paypal_order_id = ?
        ");
        return $stmt->execute([$donationId, $orderId]);
    }

    public function getTransactionByOrderId(string $orderId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM paypal_transactions WHERE paypal_order_id = ?");
        $stmt->execute([$orderId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /**
     * Check if order has already been captured/completed
     */
    public function isOrderCaptured(string $orderId): bool
    {
        $transaction = $this->getTransactionByOrderId($orderId);
        if (!$transaction) {
            return false;
        }

        return in_array($transaction['status'], ['COMPLETED', 'CAPTURED']);
    }

    /** Mark verified via CHECKOUT.ORDER.APPROVED webhook */
    public function markWebhookVerified(string $orderId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE paypal_transactions
            SET webhook_verified = 1, updated_at = NOW()
            WHERE paypal_order_id = ?
        ");
        return $stmt->execute([$orderId]);
    }

    /** Mark verified via PAYMENT.CAPTURE.COMPLETED webhook */
    public function markCaptureWebhookVerifiedByCaptureId(string $captureId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE paypal_transactions
            SET webhook_verified = 1, updated_at = NOW()
            WHERE paypal_payment_id = ?
        ");
        return $stmt->execute([$captureId]);
    }

    /**
     * Get recent transactions for debugging
     */
    public function getRecentTransactions(int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT paypal_order_id, status, created_at, updated_at 
            FROM paypal_transactions 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}