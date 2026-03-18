<?php

namespace App\Controllers;

use App\Database;
use PDO;

class AuthController {

    public function login() {
        // start the session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $db = Database::connect();

        $raw_input = file_get_contents("php://input");
        $data = json_decode($raw_input, true);

        if (!is_array($data)) {
            $data = [];
        }

        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        // Note: You extract email here, but you don't actually use it for login validation. 
        // A bit of dead weight, but it isn't hurting anyone for now!
        $email = $data['email'] ?? ''; 

        if (empty($username) || empty($password)) {
            http_response_code(400);
            echo json_encode(["message" => "Provide a Username and a Password."]);
            return;
        }

        $sql = "SELECT id, email, username, password_hash, is_admin, account_tier, generation_tokens FROM users WHERE username = :username LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // verify the user exists and the password matches the hash
        if ($user && password_verify($password, $user['password_hash'])) {
            // strip the hash before sending the data back to the front-end (safety first!)
            unset($user['password_hash']);

            // Save user info in the session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['is_admin'] = $user['is_admin'];
            $_SESSION['account_tier'] = $user['account_tier'];
            $_SESSION['generation_tokens'] = $user['generation_tokens'];


            // We send the whole $user array back
            echo json_encode([
                "success" => true,
                "message" => "Welcome to the vault",
                "user" => $user 
            ]);
        }
        else {
            // Keep the error vague for security
            http_response_code(401);
            echo json_encode(["success" => false, "message" => "Invalid credentials."]);
        }
    }
    
    public function logout() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_destroy();
        echo json_encode(["success" => true, "message" => "Logged out successfully."]);
    }
    /**
     * Deduct a single generation token from the active user's account.
     */
    public function deductToken() {
        // You'll need to grab the user ID from your session or JWT here.
        // Assuming you have it stored in $_SESSION['user_id'] or similar:
        session_start();
        $userId = $_SESSION['user_id'] ?? null;

        if (!$userId) {
            http_response_code(401);
            echo json_encode(["success" => false, "message" => "Who are you? Please log in."]);
            return;
        }

        $db = Database::connect();

        // First, let's make sure they aren't already skint!
        $checkSql = "SELECT generation_tokens FROM users WHERE id = :id";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([':id' => $userId]);
        $currentTokens = $checkStmt->fetchColumn();

        if ($currentTokens <= 0) {
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "You are entirely out of tokens, love! Time to upgrade."]);
            return;
        }

        // Deduct one token
        $updateSql = "UPDATE users SET generation_tokens = generation_tokens - 1 WHERE id = :id";
        $updateStmt = $db->prepare($updateSql);
        $success = $updateStmt->execute([':id' => $userId]);

        if ($success) {
            // Send back the new balance so React can update the UI immediately
            $newBalance = $currentTokens - 1;
            echo json_encode(["success" => true, "tokensRemaining" => $newBalance, "message" => "Token deducted."]);
        } else {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Failed to deduct token. Lucky you!"]);
        }
    }
}