<?php
class DatabaseSessionHandler implements SessionHandlerInterface {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function open($savePath, $sessionName): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read($id): string {
        $stmt = $this->pdo->prepare("SELECT data FROM sessions WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['data'] : '';
    }

    public function write($id, $data): bool {
        $now = time(); // Unixタイムスタンプ（整数）
        // テーブルのカラム名 access に合わせて値を保存
        $stmt = $this->pdo->prepare("
            REPLACE INTO sessions (id, data, access) 
            VALUES (?, ?, ?)
        ");
        return $stmt->execute([$id, $data, $now]);
    }

    public function destroy($id): bool {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE id = ?");
        return $stmt->execute([$id]);
    }

    #[\ReturnTypeWillChange]
    public function gc($maxlifetime) {
        $old = time() - $maxlifetime;
        // access カラムを使って古いセッションを削除
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE access < ?");
        $stmt->execute([$old]);
        return true;
    }
}
